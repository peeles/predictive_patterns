<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShapValue extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'prediction_id',
        'feature_name',
        'value',
        'details',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'value' => 'decimal:6',
        'details' => 'array',
    ];

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class);
    }
}
