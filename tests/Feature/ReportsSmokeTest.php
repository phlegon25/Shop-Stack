<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\SecurityTestCase;

/**
 * Regression: the sales report aggregates join `users`, so EVERY
 * unqualified column that exists on both tables (`status`, `created_at`)
 * used to explode on SQLite *and* MySQL ("ambiguous column"). Pin all
 * three aggregate panels + both report endpoints.
 */
class ReportsSmokeTest extends SecurityTestCase
{
    private User $owner;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = $this->makeTenant();
        $this->owner = $this->makeUser($tenant, User::ROLE_OWNER, ['name' => 'Jerry Owner']);
        $this->cashier = $this->makeUser($tenant, User::ROLE_CASHIER, ['name' => 'Ama Cashier']);

        $product = $this->makeProduct($tenant);
        $this->asTenantUser($this->cashier)->postJson('/api/v1/sales', [
            'amount_paid'    => 50,
            'payment_method' => 'cash',
            'items'          => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();
    }

    public function test_sales_report_aggregates_load_with_qualified_columns(): void
    {
        // Arrays opened by default period — this SELECT joins users
        // (unqualified `status`/`created_at` used to 500 here).
        $r = $this->asTenantUser($this->owner)->getJson('/api/v1/reports/sales')
            ->assertOk();

        $payload = $r->json();
        $this->assertArrayHasKey('by_cashier', $payload);
        $this->assertArrayHasKey('by_method', $payload);
        $this->assertArrayHasKey('daily', $payload);

        $cashiers = collect($payload['by_cashier'])->pluck('cashier');
        $this->assertContains('Ama Cashier', $cashiers);
    }

    public function test_sales_report_week_and_month_periods_also_load(): void
    {
        foreach (['week', 'month'] as $period) {
            $this->asTenantUser($this->owner)
                ->getJson("/api/v1/reports/sales?period={$period}")
                ->assertOk()
                ->assertJsonStructure(['range', 'by_cashier', 'by_method', 'daily', 'by_product']);
        }
    }

    public function test_profit_loss_and_dashboard_reports_load(): void
    {
        $this->asTenantUser($this->owner)->getJson('/api/v1/reports/profit-loss')->assertOk();
        $this->asTenantUser($this->owner)->getJson('/api/v1/reports/dashboard')->assertOk();

        // Cashier cannot read reports at all.
        $this->asTenantUser($this->cashier)->getJson('/api/v1/reports/sales')->assertForbidden();
    }
}
