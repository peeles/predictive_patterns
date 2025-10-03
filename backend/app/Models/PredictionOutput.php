<?php

namespace App\Models;

use App\Enums\PredictionOutputFormat;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PredictionOutput extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'prediction_id',
        'format',
        'payload',
        'tileset_path',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'format' => PredictionOutputFormat::class,
        'payload' => 'array',
    ];

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class);
    }
}
