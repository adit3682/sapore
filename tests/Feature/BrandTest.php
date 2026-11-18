<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SaporeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_logo_view_renders_wordmark_and_monogram(): void
    {
        $html = view('filament.brand.logo')->render();

        $this->assertStringContainsString('SAPORÉ', $html);
        $this->assertStringContainsString('KITCHEN SYSTEM', $html);
        $this->assertStringContainsString('sp-logo-text', $html);
    }

    public function test_user_context_shows_role_and_branch(): void
    {
        $this->seed(SaporeSeeder::class);
        $this->actingAs(User::where('email', 'chef@sapore.test')->firstOrFail());

        $html = view('filament.hooks.user-context')->render();

        $this->assertStringContainsString('Chef', $html);
        $this->assertStringContainsString('Cabang Sudirman', $html);
    }

    public function test_theme_assets_exist_and_use_brief_palette(): void
    {
        $css = file_get_contents(public_path('css/sapore.css'));

        foreach (['#0f2341', '#1a365d', '#c26944', '#78907e', '#faf7f2', '#fffdfa'] as $hex) {
            $this->assertStringContainsString($hex, $css);
        }
        $this->assertFileExists(public_path('images/sapore/favicon.svg'));
        $this->assertFileExists(public_path('images/sapore/monogram.svg'));
    }

    public function test_head_hook_links_stylesheet(): void
    {
        $html = view('filament.hooks.sapore-head')->render();

        $this->assertStringContainsString('css/sapore.css', $html);
    }

    public function test_login_page_is_reachable_and_branded(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('SAPORÉ', false);
    }
}
