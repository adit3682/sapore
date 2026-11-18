<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_plans', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('branch_id')->constrained();
            $t->date('plan_date');
            $t->string('status', 20)->default('draft')->index(); // draft | confirmed | done
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('production_plan_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('production_plan_id')->constrained()->cascadeOnDelete();
            $t->string('item_type', 20); // menu | package
            $t->unsignedBigInteger('item_id');
            $t->decimal('qty', 15, 2);
            $t->timestamps();
            $t->index(['item_type', 'item_id']);
        });

        Schema::create('purchase_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('branch_id')->constrained();
            $t->foreignId('production_plan_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 20)->default('draft')->index();
            $t->decimal('total_estimated', 15, 2)->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('purchase_request_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ingredient_id')->constrained();
            $t->decimal('qty', 15, 4);
            $t->foreignId('unit_id')->constrained('units');
            $t->decimal('estimated_cost', 15, 2)->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('production_plan_items');
        Schema::dropIfExists('production_plans');
    }
};
