<?php

namespace App\Models;

use App\Enums\CrimeIngestionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $month
 * @property bool $dry_run
 * @property string $status
 * @property int $records_detected
 * @property int $records_expected
 * @property int $records_inserted
 * @property int $records_existing
 * @property string|null $archive_checksum
 * @property string|null $archive_url
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class CrimeIngestionRun extends Model
{
    use HasFactory;

    protected $table = 'crime_ingestion_runs';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'month',
        'dry_run',
        'status',
        'records_detected',
        'records_expected',
        'records_inserted',
        'records_existing',
        'archive_checksum',
        'archive_url',
        'error_message',
        'started_at',
        'finished_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'dry_run' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'status' => CrimeIngestionStatus::class,
    ];
}
