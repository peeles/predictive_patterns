<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shap_values', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('prediction_id');
            $table->string('feature_name');
            $table->decimal('value', 12, 6);
            $table->json('details')->nullable();
            $table->timestampsTz();

            $table->foreign('prediction_id')->references('id')->on('predictions')->cascadeOnDelete();
            $table->index(['prediction_id', 'feature_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shap_values');
    }
};
