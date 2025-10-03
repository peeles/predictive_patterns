<?php

namespace App\Models;

use App\Enums\DatasetStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property DatasetStatus $status
 */
class Dataset extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'source_type',
        'source_uri',
        'file_path',
        'checksum',
        'mime_type',
        'metadata',
        'schema_mapping',
        'status',
        'ingested_at',
        'created_by',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'schema_mapping' => 'array',
        'status' => DatasetStatus::class,
        'ingested_at' => 'immutable_datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function features(): HasMany
    {
        return $this->hasMany(Feature::class);
    }

    public function models(): HasMany
    {
        return $this->hasMany(PredictiveModel::class);
    }
}
