<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sanitizes error messages for production environments.
 *
 * Prevents information leakage by hiding file paths, internal system details,
 * and other sensitive information from API responses while maintaining
 * detailed logging for debugging.
 */
class ErrorSanitizer
{
    /**
     * Sanitize an error message for the current environment.
     *
     * In production: Returns generic user-friendly message, logs full details
     * In non-production: Returns full message for debugging
     *
     * @param string $detailedMessage Full error message with system details
     * @param string $sanitizedMessage Generic user-friendly message
     * @param array<string, mixed> $context Additional context for logging
     *
     * @return string Message appropriate for current environment
     */
    public static function sanitize(
        string $detailedMessage,
        string $sanitizedMessage,
        array $context = []
    ): string {
        if (self::shouldSanitize()) {
            // Log full details for debugging
            Log::warning('Sanitized error shown to user', array_merge([
                'detailed_message' => $detailedMessage,
                'sanitized_message' => $sanitizedMessage,
            ], $context));

            return $sanitizedMessage;
        }

        return $detailedMessage;
    }

    /**
     * Create a sanitized RuntimeException.
     *
     * @param string $detailedMessage Full error message
     * @param string $sanitizedMessage Generic message
     * @param array<string, mixed> $context Logging context
     *
     * @return \RuntimeException
     */
    public static function exception(
        string $detailedMessage,
        string $sanitizedMessage,
        array $context = []
    ): \RuntimeException {
        $message = self::sanitize($detailedMessage, $sanitizedMessage, $context);

        return new \RuntimeException($message);
    }

    /**
     * Sanitize exception messages for API responses.
     *
     * Wraps existing exceptions with sanitized messages for production.
     *
     * @param Throwable $exception Original exception
     * @param string $sanitizedMessage Generic user message
     * @param array<string, mixed> $context Logging context
     *
     * @return Throwable Original or wrapped exception
     */
    public static function wrapException(
        Throwable $exception,
        string $sanitizedMessage,
        array $context = []
    ): Throwable {
        if (self::shouldSanitize()) {
            Log::warning('Exception sanitized for user', array_merge([
                'original_message' => $exception->getMessage(),
                'sanitized_message' => $sanitizedMessage,
                'exception_class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ], $context));

            return new \RuntimeException($sanitizedMessage, (int) $exception->getCode(), $exception);
        }

        return $exception;
    }

    /**
     * Sanitize file path references from error messages.
     *
     * Replaces absolute paths with generic references.
     *
     * @param string $message Message potentially containing file paths
     * @param string $replacement Replacement text (default: "[file]")
     *
     * @return string Sanitized message
     */
    public static function sanitizePath(string $message, string $replacement = '[file]'): string
    {
        if (! self::shouldSanitize()) {
            return $message;
        }

        // Replace common path patterns
        $patterns = [
            '#/var/www/[^\s"\']+#' => $replacement,
            '#/storage/[^\s"\']+#' => $replacement,
            '#/app/[^\s"\']+#' => $replacement,
            '#/home/[^\s"\']+#' => $replacement,
            '#[A-Z]:\\\\[^\s"\']+#' => $replacement, // Windows paths
            '#storage/app/[^\s"\']+#' => $replacement,
            '#models/[a-f0-9-]+/[^\s"\']+#' => '[model-artifact]',
            '#datasets/[a-f0-9-]+/[^\s"\']+#' => '[dataset-file]',
        ];

        $sanitized = $message;
        foreach ($patterns as $pattern => $replace) {
            $sanitized = preg_replace($pattern, $replace, $sanitized) ?? $sanitized;
        }

        return $sanitized;
    }

    /**
     * Determine if error messages should be sanitized.
     *
     * @return bool True if in production environment
     */
    private static function shouldSanitize(): bool
    {
        return app()->environment('production');
    }

    /**
     * Common sanitized error messages.
     */
    public const ERROR_RESOURCE_NOT_FOUND = 'The requested resource could not be found.';
    public const ERROR_FILE_NOT_FOUND = 'The required file could not be accessed.';
    public const ERROR_ARTIFACT_NOT_FOUND = 'Model artifact not found.';
    public const ERROR_DATASET_NOT_FOUND = 'Dataset not found.';
    public const ERROR_INVALID_DATA = 'The data provided is invalid or corrupt.';
    public const ERROR_PROCESSING_FAILED = 'Processing failed. Please try again.';
    public const ERROR_EXTERNAL_SERVICE = 'An external service is temporarily unavailable.';
    public const ERROR_MISSING_FIELD = 'Required data is missing.';
    public const ERROR_INVALID_FORMAT = 'Data format is invalid.';
}