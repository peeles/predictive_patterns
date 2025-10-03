<?php

namespace Database\Factories;

use App\Enums\CrimeIngestionStatus;
use App\Models\CrimeIngestionRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<CrimeIngestionRun>
 */
class CrimeIngestionRunFactory extends Factory
{
    protected $model = CrimeIngestionRun::class;

    public function definition(): array
    {
        $startedAt = Carbon::instance($this->faker->dateTimeBetween('-2 months', 'now'));

        return [
            'month' => $startedAt->format('Y-m'),
            'dry_run' => $this->faker->boolean(),
            'status' => $this->faker->randomElement(array_map(
                static fn (CrimeIngestionStatus $status): string => $status->value,
                CrimeIngestionStatus::cases()
            )),
            'records_detected' => $this->faker->numberBetween(0, 5_000),
            'records_expected' => $this->faker->numberBetween(0, 5_000),
            'records_inserted' => $this->faker->numberBetween(0, 5_000),
            'records_existing' => $this->faker->numberBetween(0, 5_000),
            'archive_checksum' => $this->faker->sha256(),
            'archive_url' => $this->faker->url(),
            'error_message' => null,
            'started_at' => $startedAt,
            'finished_at' => (clone $startedAt)->addMinutes($this->faker->numberBetween(5, 120)),
        ];
    }
}

