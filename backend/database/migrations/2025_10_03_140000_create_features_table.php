<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('dataset_id')->nullable();
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->json('geometry')->nullable();
            $table->json('properties')->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('dataset_id')->references('id')->on('datasets')->cascadeOnDelete();
            $table->index(['dataset_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
