<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Crime;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\Crime>
 */
class CrimeFactory extends Factory
{
    protected $model = Crime::class;

    public function definition(): array
    {
        $lat = (float) $this->faker->latitude(50, 55);
        $lng = (float) $this->faker->longitude(-4, 1);

        return [
            'id' => (string) Str::uuid(),
            'category' => $this->faker->randomElement(['burglary', 'assault', 'theft']),
            'occurred_at' => $this->faker->dateTimeBetween('-2 years', 'now'),
            'lat' => $lat,
            'lng' => $lng,
            'h3_res6' => $this->faker->regexify('[0-9a-f]{15}'),
            'h3_res7' => $this->faker->regexify('[0-9a-f]{15}'),
            'h3_res8' => $this->faker->regexify('[0-9a-f]{15}'),
            'raw' => null,
        ];
    }
}
