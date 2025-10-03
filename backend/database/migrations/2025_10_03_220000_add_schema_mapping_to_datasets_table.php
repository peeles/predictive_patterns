<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('datasets', function (Blueprint $table): void {
            $table->json('schema_mapping')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('datasets', function (Blueprint $table): void {
            $table->dropColumn('schema_mapping');
        });
    }
};
