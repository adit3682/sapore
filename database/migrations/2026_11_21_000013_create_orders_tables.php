<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique(); // dibuat klien (APK/POS) = kunci idempotensi
            $t->string('number', 30)->nullable()->unique(); // ORD-2026-00001
            $t->foreignId('branch_id')->constrained();
            $t->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('order_type', 20)->default('dine_in'); // dine_in | take_away
            $t->string('table_label', 30)->nullable();
            $t->string('status', 20)->default('open')->index(); // open | paid | void
            $t->decimal('subtotal', 15, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->default(0);
            $t->decimal('tax_amount', 15, 2)->default(0);
            $t->decimal('service_rate', 5, 2)->default(0);
            $t->decimal('service_amount', 15, 2)->default(0);
            $t->decimal('total', 15, 2)->default(0);
            $t->string('payment_method', 20)->nullable(); // cash | qris | card | transfer
            $t->decimal('amount_paid', 15, 2)->nullable();
            $t->decimal('change_amount', 15, 2)->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['branch_id', 'status', 'paid_at']);
        });

        // Harga & nama disalin saat transaksi (snapshot), agar laporan lama tidak berubah bila harga/menu diedit.
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('item_type', 20); // menu | package
            $t->unsignedBigInteger('item_id');
            $t->string('name');
            $t->decimal('unit_price', 15, 2);
            $t->decimal('unit_cost', 15, 4)->default(0); // HPP per unit saat dijual (untuk laporan margin)
            $t->decimal('qty', 10, 2);
            $t->decimal('line_total', 15, 2);
            $t->timestamps();
            $t->index(['item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
