<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name');
            $t->decimal('yield_qty', 15, 4)->default(1);
            $t->foreignId('yield_unit_id')->constrained('units');
            $t->decimal('total_cost', 15, 4)->default(0);
            $t->decimal('cost_per_portion', 15, 4)->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 20)->default('draft')->index(); // draft | active | archived
            $t->text('instructions')->nullable();
            $t->string('photo_path')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        // Bahan langsung ATAU sub-resep (salah satu terisi, divalidasi di aplikasi)
        Schema::create('recipe_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ingredient_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('sub_recipe_id')->nullable()->constrained('recipes')->restrictOnDelete();
            $t->decimal('qty', 15, 4);
            $t->foreignId('unit_id')->constrained('units');
            $t->string('notes')->nullable();
            $t->timestamps();
        });

        // Riwayat perubahan resep (snapshot)
        Schema::create('recipe_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->json('snapshot');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['recipe_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_versions');
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('recipes');
    }
};
