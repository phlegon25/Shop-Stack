<?php

namespace Tests\Feature;

use App\Models\Sale;
use Tests\SecurityTestCase;

/**
 * Sales are immutable; corrections happen only via void/return reversals
 * with transactions, and stock can never go negative.
 */
class FinancialIntegrityTest extends SecurityTestCase
{
    private function setupTenantWithStock(int $stock = 10): array
    {
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant);
        $product = $this->makeProduct($tenant, ['stock_quantity' => $stock, 'selling_price' => 25]);

        return [$tenant, $owner, $product];
    }

    public function test_sale_updates_stock_and_writes_ledger_in_one_transaction(): void
    {
        [$tenant, $owner, $product] = $this->setupTenantWithStock();

        $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items'          => [
                ['product_id' => $product->id, 'quantity' => 4],
            ],
            'payment_method' => 'cash',
            'amount_paid'    => 100,
        ])->assertCreated()
          ->assertJsonPath('data.total', '100.00');

        $this->assertSame(6, $product->refresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id, 'type' => 'sale', 'quantity_change' => -4, 'quantity_after' => 6,
        ]);
    }

    public function test_sale_totals_are_computed_server_side_never_from_client(): void
    {
        [$tenant, $owner, $product] = $this->setupTenantWithStock();

        // Client tries to dictate the totals.
        $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items'          => [['product_id' => $product->id, 'quantity' => 2, 'line_total' => 0.01]],
            'subtotal'       => 0.01,
            'total'          => 0.01,
            'unit_price'     => 0.01,
            'payment_method' => 'cash',
            'amount_paid'    => 100,
        ])->assertCreated()
          ->assertJsonPath('data.total', '50.00')  // 2 × 25 from DB
          ->assertJsonPath('data.subtotal', '50.00');
    }

    public function test_stock_cannot_go_negative(): void
    {
        [$tenant, $owner, $product] = $this->setupTenantWithStock(stock: 2);

        $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items'          => [['product_id' => $product->id, 'quantity' => 5]],
            'payment_method' => 'cash',
            'amount_paid'    => 500,
        ])->assertStatus(422);

        $this->assertSame(2, $product->refresh()->stock_quantity);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_void_restores_stock_and_is_audited(): void
    {
        [$tenant, $owner, $product] = $this->setupTenantWithStock();

        $saleId = $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
            'payment_method' => 'cash', 'amount_paid' => 100,
        ])->assertCreated()->json('data.id');

        $this->asTenantUser($owner)->postJson("/api/v1/sales/{$saleId}/void", ['reason' => 'Wrong till entry'])
            ->assertOk();

        $sale = Sale::find($saleId);
        $this->assertSame('voided', $sale->status);
        $this->assertSame(10, $product->refresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', ['type' => 'sale_void', 'quantity_change' => 3]);
        $this->assertDatabaseHas('audits', ['action' => 'sale.voided', 'subject_id' => $saleId]);
    }

    public function test_sale_cannot_be_voided_twice_and_original_record_survives(): void
    {
        [$tenant, $owner, $product] = $this->setupTenantWithStock();

        $saleId = $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'amount_paid' => 25,
        ])->assertCreated()->json('data.id');

        $this->asTenantUser($owner)->postJson("/api/v1/sales/{$saleId}/void", ['reason' => 'First void'])->assertOk();

        // Second void rejected; the reversal record (not deletion) is what exists.
        $this->asTenantUser($owner)->postJson("/api/v1/sales/{$saleId}/void", ['reason' => 'Double void'])->assertStatus(422);

        $sale = Sale::find($saleId);
        $this->assertSame('voided', $sale->status);
        $this->assertNotNull($sale->voided_at);
        $this->assertEquals('25.00', $sale->total); // financial history intact
    }

    public function test_there_is_no_endpoint_to_edit_a_completed_sale(): void
    {
        [$tenant, $owner, $product] = $this->setupTenantWithStock();

        $saleId = $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'amount_paid' => 25,
        ])->assertCreated()->json('data.id');

        $this->asTenantUser($owner)->putJson("/api/v1/sales/{$saleId}", ['total' => 0.01])->assertStatus(405);
        $this->asTenantUser($owner)->patchJson("/api/v1/sales/{$saleId}", ['total' => 0.01])->assertStatus(405);
        $this->asTenantUser($owner)->deleteJson("/api/v1/sales/{$saleId}")->assertStatus(405);

        $this->assertEquals('25.00', Sale::find($saleId)->total);
    }

    public function test_partial_return_restores_only_returned_quantities(): void
    {
        [$tenant, $owner, $product] = $this->setupTenantWithStock();

        $sale = $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
            'payment_method' => 'cash', 'amount_paid' => 125,
        ])->assertCreated()->json('data');

        $itemId = $sale['items'][0]['id'];

        $this->asTenantUser($owner)->postJson("/api/v1/sales/{$sale['id']}/returns", [
            'reason' => 'Customer changed mind',
            'items'  => [['sale_item_id' => $itemId, 'quantity' => 2]],
        ])->assertCreated()->assertJsonPath('data.total', '50.00');

        $this->assertSame(7, $product->refresh()->stock_quantity); // 10 - 5 + 2

        // Returning MORE than remains must fail.
        $this->asTenantUser($owner)->postJson("/api/v1/sales/{$sale['id']}/returns", [
            'reason' => 'Try again',
            'items'  => [['sale_item_id' => $itemId, 'quantity' => 4]], // only 3 remain
        ])->assertStatus(422);

        $this->assertDatabaseHas('audits', ['action' => 'sale.returned']);
    }

    public function test_purchase_increments_stock_and_cancel_reverses_it(): void
    {
        [$tenant, $owner, $product] = $this->setupTenantWithStock(stock: 5);

        $id = $this->asTenantUser($owner)->postJson('/api/v1/purchases', [
            'reference' => 'PO-100',
            'items'     => [['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 8]],
        ])->assertCreated()->json('data.id');

        $this->assertSame(25, $product->refresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', ['type' => 'purchase', 'quantity_change' => 20]);

        $this->asTenantUser($owner)->postJson("/api/v1/purchases/{$id}/cancel")->assertOk();
        $this->assertSame(5, $product->refresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', ['type' => 'purchase_cancel', 'quantity_change' => -20]);
        $this->assertDatabaseHas('audits', ['action' => 'purchase.cancelled']);
    }

    public function test_receipt_numbers_are_sequential_per_tenant(): void
    {
        [$tenant, $owner, $product] = $this->setupTenantWithStock(100);
        $tenantB = $this->makeTenant();
        $ownerB  = $this->makeUser($tenantB);
        $productB = $this->makeProduct($tenantB, ['stock_quantity' => 100]);

        $r1 = $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'amount_paid' => 25,
        ])->json('data.receipt_no');

        $r2 = $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'amount_paid' => 25,
        ])->json('data.receipt_no');

        // Tenant B starts their own sequence independently.
        $rB = $this->asTenantUser($ownerB)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $productB->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'amount_paid' => 25,
        ])->json('data.receipt_no');

        $this->assertSame(1, $r1);
        $this->assertSame(2, $r2);
        $this->assertSame(1, $rB);
    }
}
