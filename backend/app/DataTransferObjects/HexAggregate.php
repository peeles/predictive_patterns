<?php



namespace App\DataTransferObjects;

/**
 * Immutable value object wrapping aggregate metrics for a single H3 index.
 */
class HexAggregate
{
    /**
     * @param array<string, int> $categories
     */
    public function __construct(
        public readonly string $h3Index,
        public readonly int $count,
        public readonly array $categories,
    ) {
    }
}
