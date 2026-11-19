<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            // jumlah yang sudah dikembalikan ke pelanggan (total, termasuk pajak & service)
            $t->decimal('refunded_total', 15, 2)->default(0)->after('change_amount');
        });

        Schema::create('order_returns', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique(); // idempotensi
            $t->string('number', 30)->nullable()->unique(); // RTN-20261123-00001
            $t->foreignId('order_id')->constrained();
            $t->foreignId('branch_id')->constrained();
            $t->string('mode', 10);           // waste | restock
            $t->string('refund_method', 20);  // cash | qris | card | transfer
            $t->text('reason');
            $t->decimal('subtotal_refund', 15, 2);
            $t->decimal('tax_refund', 15, 2)->default(0);
            $t->decimal('service_refund', 15, 2)->default(0);
            $t->decimal('total_refund', 15, 2);
            $t->decimal('cost_refund', 15, 4)->default(0); // HPP item yang diretur (untuk laporan margin)
            $t->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('returned_at');
            $t->timestamps();
            $t->index(['branch_id', 'returned_at']);
        });

        Schema::create('order_return_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_return_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_item_id')->constrained('order_items');
            $t->string('item_type', 20);
            $t->unsignedBigInteger('item_id');
            $t->string('name');
            $t->decimal('unit_price', 15, 2);
            $t->decimal('unit_cost', 15, 4)->default(0);
            $t->decimal('qty', 10, 2);
            $t->decimal('line_total', 15, 2);
            $t->timestamps();
            $t->index(['item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_return_items');
        Schema::dropIfExists('order_returns');
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('refunded_total'));
    }
};
