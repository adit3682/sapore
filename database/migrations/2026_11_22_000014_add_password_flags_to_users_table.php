<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // true = wajib ganti password sebelum memakai aplikasi (akun seeder, akun baru, password direset admin).
            // Akun yang sudah ada ikut menjadi true: password lamanya mungkin masih "password".
            $t->boolean('must_change_password')->default(true)->after('is_active');
            $t->timestamp('password_changed_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['must_change_password', 'password_changed_at']);
        });
    }
};
