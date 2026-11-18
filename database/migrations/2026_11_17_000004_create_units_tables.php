<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $t) {
            $t->id();
            $t->string('name', 50);
            $t->string('symbol', 10)->unique();
            $t->timestamps();
        });

        // 1 from_unit = multiplier x to_unit  (contoh: 1 kg = 1000 g)
        // ingredient_id diisi bila konversi khusus bahan (contoh: 1 butir telur = 0.06 kg)
        Schema::create('unit_conversions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('from_unit_id')->constrained('units')->cascadeOnDelete();
            $t->foreignId('to_unit_id')->constrained('units')->cascadeOnDelete();
            $t->decimal('multiplier', 18, 6);
            $t->unsignedBigInteger('ingredient_id')->nullable()->index();
            $t->timestamps();
            $t->unique(['from_unit_id', 'to_unit_id', 'ingredient_id'], 'uc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_conversions');
        Schema::dropIfExists('units');
    }
};
