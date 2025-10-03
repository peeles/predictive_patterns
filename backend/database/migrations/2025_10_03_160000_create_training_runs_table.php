<?php

use App\Enums\TrainingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('model_id');
            $table->enum('status', array_map(static fn (TrainingStatus $status): string => $status->value, TrainingStatus::cases()))
                ->default(TrainingStatus::Queued->value);
            $table->json('hyperparameters')->nullable();
            $table->json('metrics')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->foreign('model_id')->references('id')->on('models')->cascadeOnDelete();
            $table->index(['model_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_runs');
    }
};
