<?php

namespace App\Models;

use App\Enums\ModelStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PredictiveModel extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'models';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dataset_id',
        'name',
        'version',
        'tag',
        'area',
        'status',
        'hyperparameters',
        'metadata',
        'metrics',
        'trained_at',
        'created_by',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'hyperparameters' => 'array',
        'metadata' => 'array',
        'metrics' => 'array',
        'trained_at' => 'immutable_datetime',
        'status' => ModelStatus::class,
    ];

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function trainingRuns(): HasMany
    {
        return $this->hasMany(TrainingRun::class, 'model_id');
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(Prediction::class, 'model_id');
    }
}
