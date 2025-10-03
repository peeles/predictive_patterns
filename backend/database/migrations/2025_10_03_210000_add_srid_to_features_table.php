<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('features', function (Blueprint $table): void {
            $table->unsignedSmallInteger('srid')->default(4326);
        });
    }

    public function down(): void
    {
        Schema::table('features', function (Blueprint $table): void {
            $table->dropColumn('srid');
        });
    }
};
