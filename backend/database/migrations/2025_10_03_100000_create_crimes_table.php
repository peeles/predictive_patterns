<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('crimes', function (Blueprint $t) {
      $t->uuid('id')->primary();
      $t->string('category', 64)->index();
      $t->timestamp('occurred_at')->index();
      $t->decimal('lat', 9, 6);
      $t->decimal('lng', 9, 6);
      $t->string('h3_res6', 16)->index();
      $t->string('h3_res7', 16)->index();
      $t->string('h3_res8', 16)->index();
      $t->json('raw')->nullable();
      $t->timestamps();
    });
  }
};
