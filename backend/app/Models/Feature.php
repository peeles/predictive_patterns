<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Feature extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dataset_id',
        'external_id',
        'name',
        'geometry',
        'properties',
        'observed_at',
        'srid',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'geometry' => 'array',
        'properties' => 'array',
        'observed_at' => 'immutable_datetime',
        'srid' => 'int',
    ];

    /**
     * @var array<string, int>
     */
    protected $attributes = [
        'srid' => 4326,
    ];

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }
}
