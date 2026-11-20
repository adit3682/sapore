<?php

namespace Tests\Feature;

use App\Filament\Resources\BranchResource;
use App\Filament\Resources\IngredientResource;
use App\Filament\Resources\MenuResource;
use App\Filament\Resources\RecipeResource;
use App\Filament\Resources\StockOpnameResource;
use App\Filament\Resources\UserResource;
use App\Filament\Widgets\CashierShortcutWidget;
use App\Filament\Widgets\SaporeStats;
use App\Models\Ingredient;
use App\Models\User;
use App\Support\Access;
use Database\Seeders\SaporeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SaporeSeeder::class);
    }

    private function u(string $name): User
    {
        return User::where('email', "{$name}@sapore.test")->firstOrFail();
    }

    public function test_matrix_for_every_role(): void
    {
        $owner = $this->u('owner');
        $manager = $this->u('manager');
        $chef = $this->u('chef');
        $inv = $this->u('inventory');
        $cashier = $this->u('kasir');

        // Cabang & pengguna
        $this->assertTrue(Access::can($owner, 'branches', 'delete'));
        $this->assertFalse(Access::can($manager, 'branches', 'view'));
        $this->assertTrue(Access::can($manager, 'users', 'create'));
        $this->assertFalse(Access::can($chef, 'users', 'view'));

        // Master data: Chef hanya baca
        $this->assertTrue(Access::can($inv, 'master_data', 'update'));
        $this->assertTrue(Access::can($chef, 'master_data', 'view'));
        $this->assertFalse(Access::can($chef, 'master_data', 'update'));
        $this->assertFalse(Access::can($cashier, 'master_data', 'view'));

        // Resep vs Menu: pemisahan wewenang resep dan harga jual
        $this->assertTrue(Access::can($chef, 'recipes', 'update'));
        $this->assertFalse(Access::can($inv, 'recipes', 'view'));
        $this->assertTrue(Access::can($chef, 'menus', 'view'));
        $this->assertFalse(Access::can($chef, 'menus', 'create'));
        $this->assertFalse(Access::can($chef, 'menus', 'update'));
        $this->assertTrue(Access::can($manager, 'menus', 'update'));

        // Rencana produksi
        $this->assertTrue(Access::can($chef, 'production_plans', 'update'));
        $this->assertTrue(Access::can($chef, 'production_plans', 'generate_pr'));
        $this->assertTrue(Access::can($inv, 'production_plans', 'view'));
        $this->assertFalse(Access::can($inv, 'production_plans', 'update'));
        $this->assertFalse(Access::can($cashier, 'production_plans', 'view'));

        // Stok: Waste untuk Owner/Manager/Inventory, Penyesuaian hanya Inventory
        $this->assertTrue(Access::can($owner, 'stock', 'waste'));
        $this->assertFalse(Access::can($owner, 'stock', 'adjust'));
        $this->assertTrue(Access::can($inv, 'stock', 'adjust'));
        $this->assertFalse(Access::can($chef, 'stock', 'waste'));

        // Penerimaan, opname
        $this->assertTrue(Access::can($inv, 'goods_receipts', 'create'));
        $this->assertFalse(Access::can($chef, 'goods_receipts', 'view'));
        $this->assertTrue(Access::can($inv, 'stock_opnames', 'confirm'));
        $this->assertFalse(Access::can($chef, 'stock_opnames', 'view'));

        // Purchase Request
        $this->assertTrue(Access::can($manager, 'purchase_requests', 'approve'));
        $this->assertFalse(Access::can($chef, 'purchase_requests', 'approve'));
        $this->assertTrue(Access::can($chef, 'purchase_requests', 'create'));
        $this->assertTrue(Access::can($inv, 'purchase_requests', 'submit'));
        $this->assertFalse(Access::can($cashier, 'purchase_requests', 'view'));

        // Penjualan
        $this->assertTrue(Access::can($cashier, 'pos', 'create'));
        $this->assertFalse(Access::can($inv, 'pos', 'create'));
        $this->assertTrue(Access::can($cashier, 'orders', 'void'));
        $this->assertFalse(Access::can($chef, 'orders', 'view'));
    }

    public function test_guest_and_inactive_users_are_denied_everything(): void
    {
        $this->assertFalse(Access::can(null, 'recipes', 'view'));

        $chef = $this->u('chef');
        $chef->update(['is_active' => false]);
        $this->assertFalse(Access::can($chef->fresh(), 'recipes', 'view'));
    }

    public function test_branch_scope(): void
    {
        $branchId = $this->u('manager')->branch_id;

        $this->assertNull(Access::branchScope($this->u('owner')));                 // akun pusat: semua cabang
        $this->assertSame($branchId, Access::branchScope($this->u('manager')));
        $this->assertSame($branchId, Access::branchScope($this->u('kasir')));
        $this->assertSame(-1, Access::branchScope(null));

        $orphan = $this->u('chef');
        $orphan->forceFill(['branch_id' => null])->save();
        $this->assertSame(-1, Access::branchScope($orphan->fresh()));              // non-Owner tanpa cabang: tidak melihat apa pun

        $owner = $this->u('owner');
        $owner->forceFill(['branch_id' => $branchId])->save();
        $this->assertNull(Access::branchScope($owner->fresh()));                  // Owner selalu pusat, tidak terikat cabang
    }

    public function test_resources_follow_the_matrix(): void
    {
        $this->actingAs($this->u('chef'));
        $this->assertTrue(RecipeResource::canViewAny());
        $this->assertTrue(RecipeResource::canCreate());
        $this->assertTrue(MenuResource::canViewAny());
        $this->assertFalse(MenuResource::canCreate());
        $this->assertFalse(IngredientResource::canEdit(new Ingredient()));
        $this->assertFalse(StockOpnameResource::canViewAny());
        $this->assertFalse(BranchResource::canViewAny());
        $this->assertFalse(UserResource::canViewAny());

        $this->actingAs($this->u('inventory'));
        $this->assertFalse(RecipeResource::canViewAny());
        $this->assertTrue(IngredientResource::canEdit(new Ingredient()));
        $this->assertTrue(StockOpnameResource::canViewAny());

        $this->actingAs($this->u('manager'));
        $this->assertFalse(BranchResource::canViewAny());
        $this->assertTrue(UserResource::canViewAny());
        $this->assertTrue(MenuResource::canCreate());

        $this->actingAs($this->u('owner'));
        $this->assertTrue(BranchResource::canViewAny());
        $this->assertTrue(BranchResource::canCreate());
        $this->assertFalse(BranchResource::canDeleteAny()); // cabang dinonaktifkan, tidak dihapus
    }

    public function test_dashboard_widgets_are_role_gated(): void
    {
        $this->actingAs($this->u('kasir'));
        $this->assertFalse(SaporeStats::canView());          // angka usaha bukan untuk Kasir
        $this->assertTrue(CashierShortcutWidget::canView());

        $this->actingAs($this->u('chef'));
        $this->assertTrue(SaporeStats::canView());
        $this->assertFalse(CashierShortcutWidget::canView());
    }
}
