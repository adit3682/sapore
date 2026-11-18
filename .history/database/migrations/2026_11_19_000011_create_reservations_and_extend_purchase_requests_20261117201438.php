<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Soft allocation: bahan yang "dipesan" oleh rencana produksi Confirmed
        Schema::create('production_plan_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_plan_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ingredient_id')->constrained();
            $t->decimal('qty_base', 15, 4); // satuan dasar bahan
            $t->timestamps();
            $t->unique(['production_plan_id', 'ingredient_id']);
        });

        Schema::table('purchase_requests', function (Blueprint $t) {
            $t->string('number', 30)->nullable()->unique()->after('uuid');
            $t->foreignId('approved_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $t) {
            $t->dropConstrainedForeignId('approved_by');
            $t->dropColumn(['number', 'approved_at']);
        });
        Schema::dropIfExists('production_plan_reservations');
    }
};
