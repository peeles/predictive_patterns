<?php

namespace App\Support\Filesystem;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CsvCombiner
{
    /**
     * @param array<int, UploadedFile> $files
     *
     * @return array{0: string, 1: string}
     */
    public function combine(array $files): array
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'dataset-');

        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to create temporary dataset file.');
        }

        $combinedHandle = fopen($temporaryPath, 'w+b');

        if ($combinedHandle === false) {
            throw new RuntimeException(sprintf('Unable to open temporary dataset file "%s" for writing.', $temporaryPath));
        }

        try {
            foreach ($files as $index => $file) {
                $handle = fopen($file->getRealPath(), 'rb');

                if ($handle === false) {
                    continue;
                }

                try {
                    if ($index > 0) {
                        $this->ensureTrailingNewline($combinedHandle);
                        $this->discardFirstLine($handle);
                    }

                    stream_copy_to_stream($handle, $combinedHandle);
                } finally {
                    fclose($handle);
                }
            }
        } finally {
            fclose($combinedHandle);
        }

        $fileName = sprintf('%s.csv', Str::uuid());
        $storagePath = 'datasets/' . $fileName;

        $stream = fopen($temporaryPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException(sprintf('Unable to read combined dataset file "%s".', $temporaryPath));
        }

        Storage::disk('local')->put($storagePath, $stream);
        fclose($stream);

        @unlink($temporaryPath);

        return [$storagePath, 'text/csv'];
    }

    /**
     * @param resource $handle
     */
    private function discardFirstLine($handle): void
    {
        while (! feof($handle)) {
            $character = fgetc($handle);

            if ($character === false) {
                break;
            }

            if ($character === "\n") {
                break;
            }

            if ($character === "\r") {
                $next = fgetc($handle);

                if ($next !== "\n" && $next !== false) {
                    fseek($handle, -1, SEEK_CUR);
                }

                break;
            }
        }
    }

    /**
     * @param resource $handle
     */
    private function ensureTrailingNewline($handle): void
    {
        fflush($handle);
        $currentPosition = ftell($handle);

        if ($currentPosition === false || $currentPosition === 0) {
            return;
        }

        if (fseek($handle, -1, SEEK_END) !== 0) {
            fseek($handle, 0, SEEK_END);

            return;
        }

        $lastCharacter = fgetc($handle);

        if ($lastCharacter !== "\n" && $lastCharacter !== "\r") {
            fwrite($handle, PHP_EOL);
        }

        fseek($handle, 0, SEEK_END);
    }
}
