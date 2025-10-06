<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @method static factory()
 */
class DatasetRecord extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'category',
        'severity',
        'occurred_at',
        'risk_score',
        'lat',
        'lng',
        'h3_res6',
        'h3_res7',
        'h3_res8',
        'raw',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'occurred_at' => 'datetime',
        'risk_score' => 'float',
        'raw' => 'array',
    ];

    /**
     * @return void
     */
    protected static function booted(): void
    {
        static::creating(static function (self $model): void {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }
}
