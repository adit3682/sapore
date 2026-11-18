<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->string('address')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('ingredients', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name');
            $t->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $t->foreignId('base_unit_id')->constrained('units');
            $t->decimal('min_stock', 15, 4)->default(0);
            $t->decimal('max_stock', 15, 4)->nullable();
            $t->decimal('avg_cost', 15, 4)->default(0);  // per satuan dasar
            $t->decimal('last_cost', 15, 4)->default(0); // per satuan dasar
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::table('unit_conversions', function (Blueprint $t) {
            $t->foreign('ingredient_id')->references('id')->on('ingredients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('unit_conversions', fn (Blueprint $t) => $t->dropForeign(['ingredient_id']));
        Schema::dropIfExists('ingredients');
        Schema::dropIfExists('suppliers');
    }
};
