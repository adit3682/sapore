<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pengaturan umum yang diubah dari antarmuka (menimpa nilai bawaan di .env / config).
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 60)->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });

        // Tarif khusus cabang. NULL = ikuti pengaturan umum.
        Schema::table('branches', function (Blueprint $t) {
            $t->decimal('tax_rate', 5, 2)->nullable()->after('phone');
            $t->decimal('service_rate', 5, 2)->nullable()->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('branches', fn (Blueprint $t) => $t->dropColumn(['tax_rate', 'service_rate']));
        Schema::dropIfExists('settings');
    }
};
