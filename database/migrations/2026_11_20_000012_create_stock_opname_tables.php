<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opnames', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('number', 30)->nullable()->unique(); // SO-20261120-00001
            $t->foreignId('branch_id')->constrained();
            $t->date('opname_date');
            $t->string('status', 20)->default('draft')->index(); // draft | confirmed | cancelled
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
        });

        // Semua angka dalam SATUAN DASAR bahan.
        Schema::create('stock_opname_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_opname_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ingredient_id')->constrained();
            $t->decimal('system_qty', 15, 4);              // stok sistem saat opname dimulai (snapshot)
            $t->decimal('physical_qty', 15, 4)->nullable(); // hasil hitung fisik; NULL = belum dihitung
            $t->decimal('difference', 15, 4)->nullable();   // fisik - sistem, diisi saat konfirmasi
            $t->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $t->timestamps();
            $t->unique(['stock_opname_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
        Schema::dropIfExists('stock_opnames');
    }
};
