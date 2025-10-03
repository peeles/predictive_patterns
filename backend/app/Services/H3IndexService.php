<?php

namespace App\Services;

use App\Support\H3;
use Closure;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use function H3\geoToH3;
use function H3\latLngToCell;

/**
 * Provides a thin abstraction around whichever H3 PHP binding is installed.
 *
 * By resolving the converter lazily we can support ext-h3, h3-php, and native
 * bindings without leaking implementation details across the rest of the code
 * base.
 */
class H3IndexService
{
    /**
     * Converts latitude/longitude pairs into an H3 index for a given resolution.
     *
     * @var Closure(float, float, int):string
     */
    private readonly Closure $converter;

    private bool $usingNodeFallback = false;

    private ?string $nodeScriptPath = null;

    private ?Process $nodeDaemon = null;

    private ?InputStream $nodeDaemonInput = null;

    private string $nodeDaemonBuffer = '';

    private int $nodeDaemonSequence = 0;

    /**
     * @var array<string, string>
     */
    private array $nodeCache = [];

    /**
     * @var list<string>
     */
    private array $nodeCacheOrder = [];

    private const NODE_CACHE_LIMIT = 10000;

    private const NODE_DAEMON_START_TIMEOUT = 5.0;

    private const NODE_DAEMON_REQUEST_TIMEOUT = 5.0;

    /**
     * @throws RuntimeException When no compatible H3 implementation is installed
     */
    public function __construct()
    {
        $this->converter = $this->resolveConverter();
    }

    public function __destruct()
    {
        $this->shutdownNodeDaemon();
    }

    /**
     * Convert a latitude/longitude pair into an H3 index for the requested resolution.
     *
     * @throws RuntimeException When the underlying H3 binding fails to resolve
     */
    public function toH3(float $lat, float $lng, int $resolution): string
    {
        if ($this->usingNodeFallback) {
            $cacheKey = $this->nodeCacheKey($lat, $lng, $resolution);
            if (isset($this->nodeCache[$cacheKey])) {
                return $this->nodeCache[$cacheKey];
            }
        }

        $converter = $this->converter;
        $index = $converter($lat, $lng, $resolution);

        if ($this->usingNodeFallback) {
            $this->rememberNodeIndex($lat, $lng, $resolution, $index);
        }

        return $index;
    }

    /**
     * Generate multiple H3 indexes for the coordinate across the requested resolutions.
     *
     * @param int[] $resolutions
     * @return array<int, string>
     *
     * @throws RuntimeException When the underlying H3 binding fails to resolve
     */
    public function indexesFor(float $lat, float $lng, array $resolutions): array
    {
        if ($this->usingNodeFallback) {
            return $this->nodeIndexesFor($lat, $lng, $resolutions);
        }

        $results = [];
        foreach ($resolutions as $resolution) {
            $results[$resolution] = $this->toH3($lat, $lng, $resolution);
        }

        return $results;
    }

    /**
     * Resolve the optimal conversion callback exposed by the available H3 extension.
     *
     * @throws RuntimeException When no compatible H3 implementation is installed
     */
    private function resolveConverter(): Closure
    {
        // Use the correct namespace for the H3 class
        $h3 = new H3\H3();

        if (method_exists($h3, 'latLngToCell')) {
            return fn (float $lat, float $lng, int $res): string => $h3->latLngToCell($lat, $lng, $res);
        }

        if (method_exists($h3, 'geoToH3')) {
            return fn (float $lat, float $lng, int $res): string => $h3->geoToH3($lat, $lng, $res);
        }

        if (function_exists('H3\\latLngToCell')) {
            return fn (float $lat, float $lng, int $res): string => latLngToCell($lat, $lng, $res);
        }

        if (function_exists('H3\\geoToH3')) {
            return fn (float $lat, float $lng, int $res): string => geoToH3($lat, $lng, $res);
        }

        if (function_exists('latLngToCell')) {
            return fn (float $lat, float $lng, int $res): string => latLngToCell($lat, $lng, $res);
        }

        if (function_exists('geoToH3')) {
            return fn (float $lat, float $lng, int $res): string => geoToH3($lat, $lng, $res);
        }

        if ($this->nodeIndexHelperAvailable()) {
            $script = $this->nodeIndexScript();
            $this->usingNodeFallback = true;
            $this->nodeScriptPath = $script;

            return $this->buildNodeIndexConverter($script);
        }

        throw new RuntimeException('H3 library is not available');
    }

    private function nodeIndexHelperAvailable(): bool
    {
        $script = $this->nodeIndexScript();

        return is_file($script) && is_readable($script);
    }

    private function nodeIndexScript(): string
    {
        static $resolved;

        if ($resolved !== null) {
            return $resolved;
        }

        $candidates = [
            base_path('backend/scripts/h3-index.cjs'),
            base_path('scripts/h3-index.cjs'),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $resolved = $candidate;
            }
        }

        return $resolved = $candidates[0];
    }

    private function buildNodeIndexConverter(string $script): Closure
    {
        return function (float $lat, float $lng, int $resolution) use ($script): string {
            if ($this->ensureNodeDaemon($script)) {
                return $this->nodeDaemonRequest($lat, $lng, $resolution);
            }

            return $this->invokeNodeProcess($script, $lat, $lng, $resolution);
        };
    }

    private function nodeIndexesFor(float $lat, float $lng, array $resolutions): array
    {
        $results = [];
        $missing = [];

        foreach ($resolutions as $resolution) {
            $cacheKey = $this->nodeCacheKey($lat, $lng, $resolution);
            if (isset($this->nodeCache[$cacheKey])) {
                $results[$resolution] = $this->nodeCache[$cacheKey];
                continue;
            }

            $missing[] = $resolution;
        }

        if ($missing !== []) {
            if ($this->ensureNodeDaemon($this->nodeScriptPath ?? $this->nodeIndexScript())) {
                $computed = [];
                foreach ($missing as $resolution) {
                    $computed[$resolution] = $this->nodeDaemonRequest($lat, $lng, $resolution);
                }
            } else {
                $computed = $this->nodeBatchIndexes($lat, $lng, $missing);
            }

            foreach ($missing as $resolution) {
                if (!array_key_exists($resolution, $computed)) {
                    $computed[$resolution] = $this->toH3UsingConverter($lat, $lng, $resolution);
                }

                $results[$resolution] = $computed[$resolution];
                $this->rememberNodeIndex($lat, $lng, $resolution, $results[$resolution]);
            }
        }

        $ordered = [];
        foreach ($resolutions as $resolution) {
            if (!array_key_exists($resolution, $results)) {
                $results[$resolution] = $this->toH3UsingConverter($lat, $lng, $resolution);
                $this->rememberNodeIndex($lat, $lng, $resolution, $results[$resolution]);
            }

            $ordered[$resolution] = $results[$resolution];
        }

        return $ordered;
    }

    private function nodeBatchIndexes(float $lat, float $lng, array $resolutions): array
    {
        if ($resolutions === []) {
            return [];
        }

        $operations = [];
        foreach ($resolutions as $resolution) {
            $operations[] = [
                'lat' => $lat,
                'lng' => $lng,
                'resolution' => $resolution,
            ];
        }

        try {
            $payload = json_encode(['operations' => $operations], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode batch payload for Node H3 index helper', previous: $exception);
        }

        $script = $this->nodeScriptPath ?? $this->nodeIndexScript();

        $process = new Process([
            'node',
            $script,
            '--batch',
        ]);
        $process->setInput($payload);
        $process->setTimeout(max(5.0, count($operations) * 0.1));
        $process->run();

        if (!$process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            $message = $errorOutput !== '' ? $errorOutput : 'Unknown error from Node index helper';

            throw new RuntimeException(sprintf('Node H3 index helper failed: %s', $message));
        }

        $output = trim($process->getOutput());

        if ($output === '') {
            throw new RuntimeException('Node H3 index helper returned an empty response');
        }

        try {
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Node H3 index helper returned invalid JSON payload', previous: $exception);
        }

        $indexes = $decoded['indexes'] ?? null;
        if (!is_array($indexes)) {
            throw new RuntimeException('Node H3 index helper returned an unexpected response shape');
        }

        if (count($indexes) !== count($operations)) {
            throw new RuntimeException('Node H3 index helper returned a mismatched number of indexes');
        }

        $results = [];
        foreach (array_values($resolutions) as $offset => $resolution) {
            $index = $indexes[$offset] ?? null;
            if (!is_string($index) || $index === '') {
                throw new RuntimeException(sprintf('Node H3 index helper returned an invalid index for resolution %d', $resolution));
            }

            $results[$resolution] = $index;
        }

        return $results;
    }

    private function toH3UsingConverter(float $lat, float $lng, int $resolution): string
    {
        $converter = $this->converter;

        return $converter($lat, $lng, $resolution);
    }

    private function invokeNodeProcess(string $script, float $lat, float $lng, int $resolution): string
    {
        $process = new Process([
            'node',
            $script,
            sprintf('%.12F', $lat),
            sprintf('%.12F', $lng),
            (string) $resolution,
        ]);
        $process->setTimeout(self::NODE_DAEMON_REQUEST_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            $message = $errorOutput !== '' ? $errorOutput : 'Unknown error from Node index helper';

            throw new RuntimeException(sprintf('Node H3 index helper failed: %s', $message));
        }

        $output = trim($process->getOutput());

        if ($output === '') {
            throw new RuntimeException('Node H3 index helper returned an empty response');
        }

        return $output;
    }

    private function ensureNodeDaemon(string $script): bool
    {
        if ($this->nodeDaemon !== null) {
            if ($this->nodeDaemon->isRunning()) {
                return true;
            }

            $this->shutdownNodeDaemon();
        }

        $process = new Process([
            'node',
            $script,
            '--daemon',
        ]);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        $input = new InputStream();
        $process->setInput($input);

        try {
            $process->start();
        } catch (\Throwable) {
            $this->shutdownNodeDaemon();

            return false;
        }

        $this->nodeDaemon = $process;
        $this->nodeDaemonInput = $input;
        $this->nodeDaemonBuffer = '';
        $this->nodeDaemonSequence = 0;

        try {
            $readyLine = $this->readNodeDaemonLine($process, self::NODE_DAEMON_START_TIMEOUT);
        } catch (RuntimeException) {
            $this->shutdownNodeDaemon();

            return false;
        }

        if (trim($readyLine) !== 'READY') {
            $this->shutdownNodeDaemon();

            return false;
        }

        return true;
    }

    private function nodeDaemonRequest(float $lat, float $lng, int $resolution): string
    {
        $process = $this->nodeDaemon;
        $input = $this->nodeDaemonInput;
        $script = $this->nodeScriptPath ?? $this->nodeIndexScript();

        if ($process === null || $input === null || !$process->isRunning()) {
            $this->shutdownNodeDaemon();

            return $this->invokeNodeProcess($script, $lat, $lng, $resolution);
        }

        $requestId = ++$this->nodeDaemonSequence;

        try {
            $payload = json_encode([
                'id' => $requestId,
                'lat' => $lat,
                'lng' => $lng,
                'resolution' => $resolution,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode payload for Node H3 daemon request', previous: $exception);
        }

        $input->write($payload . "\n");

        try {
            $line = $this->readNodeDaemonLine($process, self::NODE_DAEMON_REQUEST_TIMEOUT);
        } catch (RuntimeException $exception) {
            $this->shutdownNodeDaemon();

            throw new RuntimeException('Node H3 daemon did not respond in time', previous: $exception);
        }

        if ($line === '') {
            throw new RuntimeException('Node H3 daemon returned an empty response');
        }

        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->shutdownNodeDaemon();

            throw new RuntimeException('Node H3 daemon returned invalid JSON response', previous: $exception);
        }

        $responseId = $decoded['id'] ?? null;
        if ($responseId !== $requestId) {
            $this->shutdownNodeDaemon();

            throw new RuntimeException('Node H3 daemon returned a mismatched response identifier');
        }

        $error = $decoded['error'] ?? null;
        if (is_string($error) && $error !== '') {
            throw new RuntimeException(sprintf('Node H3 daemon reported an error: %s', $error));
        }

        $index = $decoded['index'] ?? null;
        if (!is_string($index) || $index === '') {
            $this->shutdownNodeDaemon();

            throw new RuntimeException('Node H3 daemon returned an invalid index');
        }

        return $index;
    }

    private function readNodeDaemonLine(Process $process, float $timeout): string
    {
        $deadline = microtime(true) + $timeout;

        while (true) {
            $position = strpos($this->nodeDaemonBuffer, "\n");
            if ($position !== false) {
                $line = substr($this->nodeDaemonBuffer, 0, $position);
                $this->nodeDaemonBuffer = substr($this->nodeDaemonBuffer, $position + 1);

                return $line;
            }

            if (!$process->isRunning()) {
                $errorOutput = trim($process->getErrorOutput()) ?: 'Node H3 daemon exited unexpectedly';

                throw new RuntimeException($errorOutput);
            }

            if (microtime(true) > $deadline) {
                throw new RuntimeException('Timed out waiting for Node H3 daemon response');
            }

            $this->nodeDaemonBuffer .= $process->getIncrementalOutput();
            $errorOutput = $process->getIncrementalErrorOutput();
            if ($errorOutput !== '') {
                throw new RuntimeException(sprintf('Node H3 daemon emitted error output: %s', trim($errorOutput)));
            }

            usleep(1000);
        }
    }

    private function shutdownNodeDaemon(): void
    {
        if ($this->nodeDaemonInput instanceof InputStream) {
            $this->nodeDaemonInput->close();
        }

        if ($this->nodeDaemon instanceof Process) {
            $this->nodeDaemon->stop(0.1);
        }

        $this->nodeDaemon = null;
        $this->nodeDaemonInput = null;
        $this->nodeDaemonBuffer = '';
        $this->nodeDaemonSequence = 0;
    }

    private function rememberNodeIndex(float $lat, float $lng, int $resolution, string $index): void
    {
        $cacheKey = $this->nodeCacheKey($lat, $lng, $resolution);

        if (isset($this->nodeCache[$cacheKey])) {
            return;
        }

        $this->nodeCache[$cacheKey] = $index;
        $this->nodeCacheOrder[] = $cacheKey;

        if (count($this->nodeCacheOrder) > self::NODE_CACHE_LIMIT) {
            $oldest = array_shift($this->nodeCacheOrder);
            if ($oldest !== null) {
                unset($this->nodeCache[$oldest]);
            }
        }
    }

    private function nodeCacheKey(float $lat, float $lng, int $resolution): string
    {
        return sprintf('%.12F:%.12F:%d', $lat, $lng, $resolution);
    }
}
