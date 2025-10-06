<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('dataset_records', function (Blueprint $table): void {
            $table->index(['lat', 'lng'], 'dataset_records_lat_lng_index');
            $table->index(['occurred_at', 'lat', 'lng'], 'dataset_records_occurred_lat_lng_index');
            $table->index(['category', 'occurred_at'], 'dataset_records_category_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::table('dataset_records', function (Blueprint $table): void {
            $table->dropIndex('dataset_records_lat_lng_index');
            $table->dropIndex('dataset_records_occurred_lat_lng_index');
            $table->dropIndex('dataset_records_category_occurred_index');
        });
    }
};
