<?php

namespace App\Support\Phpml;

use Phpml\Preprocessing\Imputer;
use Phpml\Preprocessing\Imputer\Strategy\MeanStrategy;
use Phpml\Preprocessing\Imputer\Strategy\MedianStrategy;
use Phpml\Preprocessing\Imputer\Strategy\MostFrequentStrategy;
use Phpml\Preprocessing\Imputer\Strategy;
use ReflectionClass;
use ReflectionException;

class ImputerFactory
{
    public static function create(
        int|string $strategy,
        mixed $missingValue = null,
        array $statistics = [],
        mixed $fillValue = 0.0
    ): Imputer {
        $resolvedStrategy = self::resolveStrategy($strategy, $fillValue);

        $imputer = new Imputer($missingValue, $resolvedStrategy);

        if ($statistics !== []) {
            $normalizedStatistics = array_values(array_map('floatval', $statistics));

            self::seedStatistics($imputer, $normalizedStatistics);
        }

        self::ensureHasSamples($imputer, max(1, count($statistics)));

        return $imputer;
    }

    /**
     * Ensure the imputer has been fitted with at least one sample.
     *
     * @param Imputer $imputer
     * @param list<float> $statistics
     *
     * @return void
     */
    private static function seedStatistics(Imputer $imputer, array $statistics): void
    {
        if (method_exists($imputer, 'setStatistics')) {
            $imputer->setStatistics($statistics);

            return;
        }

        try {
            $reflection = new ReflectionClass($imputer);

            if ($reflection->hasProperty('statistics')) {
                $property = $reflection->getProperty('statistics');

                if (! $property->isPublic()) {
                    $property->setAccessible(true);
                }

                $property->setValue($imputer, $statistics);
            }
        } catch (ReflectionException) {
            // If reflection fails we leave the imputer without pre-set statistics.
        }
    }


    private static function ensureHasSamples(Imputer $imputer, int $featureCount): void
    {
        try {
            $reflection = new ReflectionClass($imputer);

            if (! $reflection->hasProperty('samples')) {
                return;
            }

            $property = $reflection->getProperty('samples');

            if (! $property->isPublic()) {
                $property->setAccessible(true);
            }

            $current = $property->getValue($imputer);

            if (is_array($current) && $current !== []) {
                return;
            }

            $placeholder = array_fill(0, $featureCount, 0.0);
            $property->setValue($imputer, [$placeholder]);
        } catch (ReflectionException) {
            // Ignore reflection failures – the imputer will throw a runtime exception if it cannot be used.
        }
    }

    public static function resolveStrategy(int|string $strategy, mixed $fillValue = 0.0): object
    {
        if (is_string($strategy)) {
            $strategy = strtolower(trim($strategy));

            return match ($strategy) {
                'median' => new MedianStrategy(),
                'most_frequent', 'mostfrequent' => new MostFrequentStrategy(),
                'constant' => self::makeConstantStrategy($fillValue),
                default => new MeanStrategy(),
            };
        }

        return match ((int) $strategy) {
            1 => new MedianStrategy(),
            2 => new MostFrequentStrategy(),
            3 => self::makeConstantStrategy($fillValue),
            default => new MeanStrategy(),
        };
    }

    private static function makeConstantStrategy(mixed $fillValue): object
    {
        $constantStrategyClass = 'Phpml\\Preprocessing\\Imputer\\Strategy\\ConstantStrategy';

        if (class_exists($constantStrategyClass)) {
            return new $constantStrategyClass($fillValue);
        }

        $strategyInterface = 'Phpml\\Preprocessing\\Imputer\\Strategy';

        if (interface_exists($strategyInterface)) {
            return new class($fillValue) implements Strategy {
                public function __construct(private readonly mixed $fillValue)
                {
                }

                public function replaceValue(array $column): float
                {
                    return (float) $this->fillValue;
                }
            };
        }

        return new class($fillValue) {
            public function __construct(private readonly mixed $fillValue)
            {
            }

            public function replaceValue(array $column): float
            {
                return (float) $this->fillValue;
            }
        };
    }
}
