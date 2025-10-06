<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DatasetRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\DatasetRecord>
 */
class DatasetRecordFactory extends Factory
{
    protected $model = DatasetRecord::class;

    public function definition(): array
    {
        $lat = (float) $this->faker->latitude(50, 55);
        $lng = (float) $this->faker->longitude(-4, 1);

        return [
            'id' => (string) Str::uuid(),
            'category' => $this->faker->randomElement(['environmental', 'transport', 'health']),
            'severity' => $this->faker->randomElement(['low', 'medium', 'high']),
            'occurred_at' => $this->faker->dateTimeBetween('-2 years', 'now'),
            'risk_score' => $this->faker->randomFloat(4, 0, 1),
            'lat' => $lat,
            'lng' => $lng,
            'h3_res6' => $this->faker->regexify('[0-9a-f]{15}'),
            'h3_res7' => $this->faker->regexify('[0-9a-f]{15}'),
            'h3_res8' => $this->faker->regexify('[0-9a-f]{15}'),
            'raw' => null,
        ];
    }
}
