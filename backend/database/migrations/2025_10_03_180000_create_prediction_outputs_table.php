<?php

use App\Enums\PredictionOutputFormat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prediction_outputs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('prediction_id');
            $table->enum('format', array_map(static fn (PredictionOutputFormat $format): string => $format->value, PredictionOutputFormat::cases()));
            $table->json('payload')->nullable();
            $table->string('tileset_path')->nullable();
            $table->timestampsTz();

            $table->foreign('prediction_id')->references('id')->on('predictions')->cascadeOnDelete();
            $table->index(['prediction_id', 'format']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prediction_outputs');
    }
};
