<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('crimes', function (Blueprint $table): void {
            $table->index(['lat', 'lng'], 'crimes_lat_lng_index');
            $table->index(['occurred_at', 'lat', 'lng'], 'crimes_occurred_lat_lng_index');
            $table->index(['category', 'occurred_at'], 'crimes_category_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::table('crimes', function (Blueprint $table): void {
            $table->dropIndex('crimes_lat_lng_index');
            $table->dropIndex('crimes_occurred_lat_lng_index');
            $table->dropIndex('crimes_category_occurred_index');
        });
    }
};
