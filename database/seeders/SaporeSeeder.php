<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SaporeSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::firstOrCreate(['name' => 'Cabang Sudirman'], ['is_active' => true]);

        $units = [];
        foreach ([
            ['Kilogram', 'kg'], ['Gram', 'g'], ['Liter', 'L'],
            ['Mililiter', 'ml'], ['Pieces', 'pcs'], ['Butir', 'butir'], ['Porsi', 'porsi'],
        ] as [$name, $symbol]) {
            $units[$symbol] = Unit::firstOrCreate(['symbol' => $symbol], ['name' => $name]);
        }

        // 1 from = multiplier x to
        foreach ([['kg', 'g', 1000], ['L', 'ml', 1000]] as [$from, $to, $mul]) {
            UnitConversion::firstOrCreate(
                ['from_unit_id' => $units[$from]->id, 'to_unit_id' => $units[$to]->id, 'ingredient_id' => null],
                ['multiplier' => $mul]
            );
        }

        foreach (['Protein', 'Sayuran', 'Minyak', 'Tepung', 'Dairy', 'Bumbu'] as $name) {
            Category::firstOrCreate(['name' => $name, 'type' => 'ingredient']);
        }
        foreach (['Main Course', 'Paket', 'Minuman', 'Snack', 'Dessert'] as $name) {
            Category::firstOrCreate(['name' => $name, 'type' => 'menu']);
        }

        foreach ([
            ['Owner', 'owner@sapore.test', 'owner'],
            ['Manager Dewi', 'manager@sapore.test', 'manager'],
            ['Chef Andi', 'chef@sapore.test', 'chef'],
            ['Staff Inventory', 'inventory@sapore.test', 'inventory'],
            ['Kasir Rina', 'kasir@sapore.test', 'cashier'],
        ] as [$name, $email, $role]) {
            User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'password' => Hash::make('password'),
                'role' => $role,
                'branch_id' => $role === 'owner' ? null : $branch->id, // Owner = akun pusat
                // must_change_password bernilai true (bawaan kolom): password awal wajib diganti
            ]);
        }
    }
}
