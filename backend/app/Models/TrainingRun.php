<?php

namespace App\Models;

use App\Enums\TrainingStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingRun extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'model_id',
        'status',
        'hyperparameters',
        'metrics',
        'error_message',
        'queued_at',
        'started_at',
        'finished_at',
        'initiated_by',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => TrainingStatus::class,
        'hyperparameters' => 'array',
        'metrics' => 'array',
        'queued_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
    ];

    public function model(): BelongsTo
    {
        return $this->belongsTo(PredictiveModel::class, 'model_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
