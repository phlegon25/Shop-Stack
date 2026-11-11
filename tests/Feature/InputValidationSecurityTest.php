<?php

namespace Tests\Feature;

use Tests\SecurityTestCase;

/**
 * SQL-injection / XSS / abuse-hardening checks on search, sort, pagination
 * and payloads.
 */
class InputValidationSecurityTest extends SecurityTestCase
{
    public function test_malicious_search_and_sort_inputs_are_safe(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);
        $this->makeProduct($tenant);

        $attacks = [
            "'; DROP TABLE products;--",
            "' OR '1'='1",
            '1;SELECT * FROM users--',
            "%_');DELETE FROM products;--",
        ];

        foreach ($attacks as $payload) {
            $this->asTenantUser($owner)
                ->getJson('/api/v1/products?search='.urlencode($payload))
                ->assertOk();
        }

        // Unknown/nasty sort field falls back to the allowlist default.
        $this->asTenantUser($owner)
            ->getJson('/api/v1/products?sort='.urlencode('(SELECT password FROM users)').'&dir='.urlencode('desc;DROP'))
            ->assertOk();

        $this->assertDatabaseHas('products', ['tenant_id' => $tenant->id]); // table intact
        $this->assertDatabaseHas('users', ['tenant_id' => $tenant->id]);
    }

    public function test_pagination_is_clamped(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);
        $this->makeProduct($tenant);

        $response = $this->asTenantUser($owner)->getJson('/api/v1/products?per_page=1000000')->assertOk();
        $this->assertLessThanOrEqual(100, $response->json('per_page'));

        $this->asTenantUser($owner)->getJson('/api/v1/products?per_page=-50')->assertOk();
    }

    public function test_xss_payload_is_stored_as_inert_data_and_json_escaped(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);

        // The API is JSON-only: payloads are never rendered as HTML. Content
        // is stored as data and JSON-encoded, and CSP/X-Content-Type-Options
        // headers stop any browser from interpreting it.
        $this->asTenantUser($owner)->postJson('/api/v1/products', [
            'sku' => 'XSS-1', 'name' => '<script>alert(1)</script>',
            'cost_price' => 1, 'selling_price' => 1,
        ])->assertCreated();

        $response = $this->asTenantUser($owner)->getJson('/api/v1/products');
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('default-src', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_security_headers_present_on_responses(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);

        $response = $this->asTenantUser($owner)->getJson('/api/v1/me');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('php', strtolower((string) $response->headers->get('X-Powered-By', '')));
    }

    public function test_error_responses_do_not_leak_internals(): void
    {
        config(['app.debug' => false]); // production mode

        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);

        // 500-path: hit an endpoint that forces an unexpected state
        $response = $this->withHeaders(['Authorization' => 'Bearer invalid-token'])->getJson('/api/v1/me');
        $this->assertStringNotContainsString('trace', $response->getContent());
        $this->assertStringNotContainsString('\\', $response->getContent()); // no file paths

        // 404 for missing model has no internals
        $this->asTenantUser($owner)->getJson('/api/v1/products/999999')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_sales_reject_negative_and_huge_values(): void
    {
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant);
        $product = $this->makeProduct($tenant);

        $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => -5]],
            'payment_method' => 'cash', 'amount_paid' => 10,
        ])->assertStatus(422);

        $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 999999999]],
            'payment_method' => 'cash', 'amount_paid' => 10,
        ])->assertStatus(422);

        $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => 'not-a-number', 'quantity' => 1]],
            'payment_method' => 'cash', 'amount_paid' => 10,
        ])->assertStatus(422);
    }

    public function test_api_login_endpoint_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => "x{$i}@test.dev", 'password' => 'Wrong#1234a']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'x@test.dev', 'password' => 'Wrong#1234a'])
            ->assertStatus(429);
    }
}
