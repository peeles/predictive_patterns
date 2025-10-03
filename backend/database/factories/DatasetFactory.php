<?php

namespace Database\Factories;

use App\Enums\DatasetStatus;
use App\Models\Dataset;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Dataset>
 */
class DatasetFactory extends Factory
{
    protected $model = Dataset::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'source_type' => 'url',
            'source_uri' => $this->faker->url(),
            'metadata' => ['source' => 'factory'],
            'status' => DatasetStatus::Ready,
            'ingested_at' => now(),
        ];
    }
}
