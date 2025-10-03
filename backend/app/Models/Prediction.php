<?php

namespace App\Models;

use App\Enums\PredictionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prediction extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'model_id',
        'dataset_id',
        'status',
        'parameters',
        'metadata',
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
        'status' => PredictionStatus::class,
        'parameters' => 'array',
        'metadata' => 'array',
        'queued_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
    ];

    public function model(): BelongsTo
    {
        return $this->belongsTo(PredictiveModel::class, 'model_id');
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class, 'dataset_id');
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(PredictionOutput::class);
    }

    public function shapValues(): HasMany
    {
        return $this->hasMany(ShapValue::class)->orderBy('created_at');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
