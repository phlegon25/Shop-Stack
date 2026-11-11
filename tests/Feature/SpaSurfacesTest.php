<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The three public surfaces must always be servable without auth and
 * must reference all of the JS/CSS assets their bootstraps declare.
 */
class SpaSurfacesTest extends TestCase
{
    public function test_landing_page_serves_public_surface(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('data-open-register')
            ->assertSee('/app/assets/app.css');

        $this->get('/landing.html')->assertOk();
    }

    public function test_tenant_app_shell_and_all_declared_assets_exist(): void
    {
        $html = $this->get('/app/index.html')->assertOk()->getContent();

        // every script/link the bootstrap declares must resolve server-side
        preg_match_all('#(?:src|href)="(/app/assets/[^"]+)"#', $html, $m);
        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $path) {
            $this->get($path)->assertOk("asset missing: {$path}");
            $this->assertFileExists(public_path($path));
        }
    }

    public function test_admin_console_shell_and_assets_exist(): void
    {
        $this->get('/app/admin-cbcorrupt.html')->assertNotFound(); // guard: random misses 404
        $html = $this->get('/app/admin.html')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#src="/app/assets/admin\.js"#', $html);

        preg_match_all('#(?:src|href)="(/app/assets/[^"]+)"#', $html, $m);
        foreach (array_unique($m[1]) as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_public_platform_metadata_is_non_sensitive(): void
    {
        $r = $this->getJson('/api/v1/platform')->assertOk();
        $json = $r->json();

        foreach ($json as $key => $_) {
            $this->assertContains($key, ['name', 'tagline', 'currency', 'trial_days'],
                "platform metadata leaked unexpected key: {$key}");
        }
    }
}
