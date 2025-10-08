<?php

namespace Database\Factories;

use App\Enums\DatasetRecordIngestionStatus;
use App\Models\DatasetRecordIngestionRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<DatasetRecordIngestionRun>
 */
class DatasetRecordIngestionRunFactory extends Factory
{
    protected $model = DatasetRecordIngestionRun::class;

    public function definition(): array
    {
        $startedAt = Carbon::instance($this->faker->dateTimeBetween('-2 months', 'now'));

        return [
            'month' => $startedAt->format('Y-m'),
            'dry_run' => $this->faker->boolean(),
            'status' => $this->faker->randomElement(array_map(
                static fn (DatasetRecordIngestionStatus $status): string => $status->value,
                DatasetRecordIngestionStatus::cases()
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
