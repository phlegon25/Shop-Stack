<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use Tests\SecurityTestCase;

/**
 * THE flagship suite: TENANT A CANNOT ACCESS TENANT B — regardless of how
 * IDs, URLs, query params, or request bodies are manipulated (IDOR/BOLA).
 */
class TenantIsolationTest extends SecurityTestCase
{
    private function twoTenants(): array
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        return [
            $tenantA,
            $this->makeUser($tenantA),           // owner A
            $this->makeProduct($tenantA),        // product A
            $tenantB,
            $this->makeUser($tenantB),           // owner B
            $this->makeProduct($tenantB),        // product B
        ];
    }

    public function test_tenant_b_cannot_read_tenant_a_product_by_id(): void
    {
        [$tA, $ownerA, $productA, $tB, $ownerB, $productB] = $this->twoTenants();

        $this->asTenantUser($ownerB)
            ->getJson("/api/v1/products/{$productA->id}")
            ->assertNotFound();
    }

    public function test_tenant_b_product_list_contains_only_its_own_products(): void
    {
        [$tA, $ownerA, $productA, $tB, $ownerB, $productB] = $this->twoTenants();

        $response = $this->asTenantUser($ownerB)->getJson('/api/v1/products');

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertContains($productB->id, $ids);
        $this->assertNotContains($productA->id, $ids);
        foreach ($response->json('data') as $row) {
            $this->assertSame($tB->id, $row['tenant_id']);
        }
    }

    public function test_tenant_b_cannot_update_tenant_a_product(): void
    {
        [$tA, $ownerA, $productA, $tB, $ownerB, $productB] = $this->twoTenants();

        $this->asTenantUser($ownerB)
            ->putJson("/api/v1/products/{$productA->id}", [
                'sku' => 'HACK', 'name' => 'Hacked', 'cost_price' => 1, 'selling_price' => 1,
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('products', ['id' => $productA->id, 'name' => 'Hacked']);
    }

    public function test_tenant_b_cannot_delete_tenant_a_product(): void
    {
        [$tA, $ownerA, $productA, $tB, $ownerB, $productB] = $this->twoTenants();

        $this->asTenantUser($ownerB)
            ->deleteJson("/api/v1/products/{$productA->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('products', ['id' => $productA->id]);
    }

    public function test_tenant_cannot_sell_another_tenants_product_in_a_sale(): void
    {
        [$tA, $ownerA, $productA, $tB, $ownerB, $productB] = $this->twoTenants();

        $this->asTenantUser($ownerB)
            ->postJson('/api/v1/sales', [
                'items'          => [['product_id' => $productA->id, 'quantity' => 1]],
                'payment_method' => 'cash',
                'amount_paid'    => 100,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_tenant_cannot_see_another_tenants_sales(): void
    {
        [$tA, $ownerA, $productA, $tB, $ownerB, $productB] = $this->twoTenants();

        $this->makeProduct($tA); // extra fixture noise
        $this->asTenantUser($ownerA)->postJson('/api/v1/sales', [
            'items'          => [['product_id' => $productA->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'amount_paid'    => 100,
        ])->assertCreated();

        $sale = Sale::first();

        // List isolation
        $list = $this->asTenantUser($ownerB)->getJson('/api/v1/sales');
        $this->assertCount(0, $list->json('data'));

        // Direct access isolation
        $this->asTenantUser($ownerB)
            ->getJson("/api/v1/sales/{$sale->id}")
            ->assertNotFound();

        // Void isolation (no cross-tenant mutation of financial records)
        $this->asTenantUser($ownerB)
            ->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'malicious'])
            ->assertNotFound();

        $this->assertSame(Sale::STATUS_COMPLETED, $sale->refresh()->status);
    }

    public function test_tenant_cannot_see_another_tenants_payment_submissions(): void
    {
        [$tA, $ownerA] = $this->twoTenants();
        $tB = \App\Models\Tenant::orderByDesc('id')->first();
        $ownerB = $tB->users()->first();

        $this->asTenantUser($ownerA)->postJson('/api/v1/billing/payments', [
            'plan_id' => 1, 'method' => 'momo', 'sender_number' => '0240000000',
            'reference' => 'REF-'.uniqid(),
        ])->assertCreated();

        $listB = $this->asTenantUser($ownerB)->getJson('/api/v1/billing/payments');
        $this->assertCount(0, $listB->json('data'));
    }

    public function test_tenant_cannot_use_another_tenants_category(): void
    {
        [$tA, $ownerA, $productA, $tB, $ownerB] = $this->twoTenants();

        $categoryA = $productA->category;

        $response = $this->asTenantUser($ownerB)->postJson('/api/v1/products', [
            'category_id'   => $categoryA->id, // belongs to tenant A
            'sku'           => 'X-1',
            'name'          => 'Sneaky',
            'cost_price'    => 1,
            'selling_price' => 1,
        ]);

        $response->assertStatus(422); // category rejected by tenant-scoped exists rule
    }

    public function test_client_supplied_tenant_id_is_never_trusted(): void
    {
        [$tA, $ownerA, $productA, $tB, $ownerB] = $this->twoTenants();

        // Try every conceivable injection point for tenant_id — context must
        // still come from the authenticated session.
        $this->asTenantUser($ownerB)
            ->postJson('/api/v1/products?tenant_id='.$tA->id, [
                'tenant_id'     => $tA->id,
                'sku'           => 'Y-1',
                'name'          => 'Injected',
                'cost_price'    => 1,
                'selling_price' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('data.tenant_id', $tB->id); // assigned to B, not A
    }

    public function test_cashier_of_tenant_a_is_equally_isolated(): void
    {
        [$tA, $ownerA, $productA, $tB] = $this->twoTenants();
        $cashierA = $this->makeUser($tA, 'cashier');

        $this->asTenantUser($cashierA)
            ->getJson('/api/v1/products/'.Product::where('tenant_id', $tB->id)->value('id'))
            ->assertNotFound();
    }
}
