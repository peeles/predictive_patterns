<?php

use App\Enums\PredictionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('predictions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('model_id')->constrained('models')->cascadeOnDelete();
            $table->foreignUuid('dataset_id')->nullable()->constrained('datasets')->nullOnDelete();
            $table->enum('status', array_map(static fn (PredictionStatus $status): string => $status->value, PredictionStatus::cases()))
                ->default(PredictionStatus::Queued->value);
            $table->json('parameters')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['model_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};
