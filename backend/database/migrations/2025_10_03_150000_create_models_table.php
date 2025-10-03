<?php

use App\Enums\ModelStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('dataset_id')->nullable();
            $table->string('name');
            $table->string('version')->default('1.0.0');
            $table->string('tag')->nullable();
            $table->string('area')->nullable();
            $table->enum('status', array_map(static fn (ModelStatus $status): string => $status->value, ModelStatus::cases()))
                ->default(ModelStatus::Draft->value);
            $table->json('hyperparameters')->nullable();
            $table->json('metadata')->nullable();
            $table->json('metrics')->nullable();
            $table->timestampTz('trained_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->foreign('dataset_id')->references('id')->on('datasets')->nullOnDelete();
            $table->index(['tag', 'area']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('models');
    }
};
