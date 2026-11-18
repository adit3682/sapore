<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('number', 30)->nullable()->unique(); // GR-20261118-00001, diisi setelah id ada
            $t->foreignId('branch_id')->constrained();
            $t->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $t->date('receipt_date');
            $t->text('notes')->nullable();
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('goods_receipt_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ingredient_id')->constrained();
            $t->decimal('qty', 15, 4);
            $t->foreignId('unit_id')->constrained('units');
            $t->decimal('unit_price', 15, 4); // harga per satuan yang diinput (bukan per satuan dasar)
            $t->decimal('subtotal', 15, 2);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
    }
};
