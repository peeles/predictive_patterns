<?php

use App\Enums\CrimeIngestionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('crime_ingestion_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('month', 7);
            $table->boolean('dry_run')->default(false);
            $table->string('status', 32)->default(CrimeIngestionStatus::Pending->value);
            $table->unsignedBigInteger('records_detected')->default(0);
            $table->unsignedBigInteger('records_expected')->default(0);
            $table->unsignedBigInteger('records_inserted')->default(0);
            $table->unsignedBigInteger('records_existing')->default(0);
            $table->string('archive_checksum')->nullable();
            $table->string('archive_url')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('month');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crime_ingestion_runs');
    }
};
