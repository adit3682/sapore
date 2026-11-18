<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->decimal('quantity', 15, 4)->default(0);          // dalam satuan dasar bahan
            $t->decimal('reserved_quantity', 15, 4)->default(0); // soft allocation rencana produksi Confirmed
            $t->timestamps();
            $t->unique(['ingredient_id', 'branch_id']);
        });

        // Single source of truth semua perubahan stok.
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique(); // idempotency key (penting untuk APK/offline)
            $t->foreignId('ingredient_id')->constrained();
            $t->foreignId('branch_id')->constrained();
            $t->string('type', 20)->index(); // in | out | adjustment | waste | transfer
            $t->decimal('qty', 15, 4);       // jumlah sesuai input user
            $t->foreignId('unit_id')->constrained('units');
            $t->decimal('qty_base', 15, 4);  // bertanda (+/-) dalam satuan dasar bahan
            $t->decimal('unit_cost', 15, 4)->nullable();
            $t->decimal('balance_after', 15, 4)->nullable();
            $t->string('reference_type', 40)->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['reference_type', 'reference_id']);
            $t->index(['ingredient_id', 'branch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stocks');
    }
};
