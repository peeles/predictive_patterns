<?php

declare(strict_types=1);

use App\Support\ErrorSanitizer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    Log::spy();
});

it('returns detailed message in non-production environments', function (): void {
    Config::set('app.env', 'local');

    $detailed = 'Model artifact "/storage/app/models/abc-123/artifact.json" was not found.';
    $sanitized = 'Model artifact not found.';

    $result = ErrorSanitizer::sanitize($detailed, $sanitized);

    expect($result)->toBe($detailed);
    Log::shouldNotHaveReceived('warning');
});

it('returns sanitized message in production environment', function (): void {
    Config::set('app.env', 'production');

    $detailed = 'Model artifact "/storage/app/models/abc-123/artifact.json" was not found.';
    $sanitized = 'Model artifact not found.';

    $result = ErrorSanitizer::sanitize($detailed, $sanitized, ['model_id' => 'abc-123']);

    expect($result)->toBe($sanitized);
    Log::shouldHaveReceived('warning')->once()
        ->with('Sanitized error shown to user', [
            'detailed_message' => $detailed,
            'sanitized_message' => $sanitized,
            'model_id' => 'abc-123',
        ]);
});

it('creates sanitized exception in production', function (): void {
    Config::set('app.env', 'production');

    $detailed = 'Dataset file "/storage/app/datasets/xyz-789/data.csv" not found.';
    $sanitized = ErrorSanitizer::ERROR_DATASET_NOT_FOUND;

    $exception = ErrorSanitizer::exception($detailed, $sanitized, ['dataset_id' => 'xyz-789']);

    expect($exception)->toBeInstanceOf(RuntimeException::class);
    expect($exception->getMessage())->toBe($sanitized);
    Log::shouldHaveReceived('warning')->once();
});

it('creates exception with detailed message in non-production', function (): void {
    Config::set('app.env', 'testing');

    $detailed = 'Dataset file "/storage/app/datasets/xyz-789/data.csv" not found.';
    $sanitized = ErrorSanitizer::ERROR_DATASET_NOT_FOUND;

    $exception = ErrorSanitizer::exception($detailed, $sanitized);

    expect($exception)->toBeInstanceOf(RuntimeException::class);
    expect($exception->getMessage())->toBe($detailed);
});

it('sanitizes file paths from error messages', function (): void {
    Config::set('app.env', 'production');

    $message = 'Unable to open /var/www/html/storage/app/datasets/test-123/data.csv for reading';
    $sanitized = ErrorSanitizer::sanitizePath($message);

    expect($sanitized)->not()->toContain('/var/www');
    expect($sanitized)->not()->toContain('storage/app');
    expect($sanitized)->toContain('[file]');
});

it('sanitizes model artifact paths', function (): void {
    Config::set('app.env', 'production');

    $message = 'Model file models/abc-123-def-456/20240315120000.model not found';
    $sanitized = ErrorSanitizer::sanitizePath($message);

    expect($sanitized)->not()->toContain('models/abc-123-def-456');
    expect($sanitized)->toContain('[model-artifact]');
});

it('sanitizes dataset file paths', function (): void {
    Config::set('app.env', 'production');

    $message = 'Cannot access datasets/xyz-789-abc/file.csv';
    $sanitized = ErrorSanitizer::sanitizePath($message);

    expect($sanitized)->not()->toContain('datasets/xyz-789-abc');
    expect($sanitized)->toContain('[dataset-file]');
});

it('does not sanitize paths in non-production', function (): void {
    Config::set('app.env', 'local');

    $message = 'Unable to open /var/www/html/storage/app/datasets/test-123/data.csv';
    $sanitized = ErrorSanitizer::sanitizePath($message);

    expect($sanitized)->toBe($message);
});

it('wraps exceptions with sanitized messages in production', function (): void {
    Config::set('app.env', 'production');

    $original = new RuntimeException('File /storage/app/models/test/artifact.json missing');
    $sanitized = 'Required file not found.';

    $wrapped = ErrorSanitizer::wrapException($original, $sanitized, ['context' => 'test']);

    expect($wrapped)->toBeInstanceOf(RuntimeException::class);
    expect($wrapped->getMessage())->toBe($sanitized);
    expect($wrapped->getPrevious())->toBe($original);

    Log::shouldHaveReceived('warning')->once()
        ->with('Exception sanitized for user', Mockery::on(function ($arg) use ($original, $sanitized) {
            return $arg['original_message'] === $original->getMessage()
                && $arg['sanitized_message'] === $sanitized
                && $arg['exception_class'] === RuntimeException::class
                && $arg['context'] === 'test';
        }));
});

it('does not wrap exceptions in non-production', function (): void {
    Config::set('app.env', 'development');

    $original = new RuntimeException('File /storage/app/models/test/artifact.json missing');
    $sanitized = 'Required file not found.';

    $wrapped = ErrorSanitizer::wrapException($original, $sanitized);

    expect($wrapped)->toBe($original);
    Log::shouldNotHaveReceived('warning');
});