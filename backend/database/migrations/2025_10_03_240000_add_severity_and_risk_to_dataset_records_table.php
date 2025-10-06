<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dataset_records', function (Blueprint $table): void {
            $table->string('severity', 32)->nullable()->index();
            $table->decimal('risk_score', 5, 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dataset_records', function (Blueprint $table): void {
            $table->dropColumn(['severity', 'risk_score']);
        });
    }
};
