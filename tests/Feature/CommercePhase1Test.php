<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Plan;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Tests\SecurityTestCase;

/**
 * End-to-end coverage for extended commerce: credit sales & receivables,
 * partial payments, payables, tax, invoices, P&L, plan limits and the
 * subscription lifecycle engine.
 */
class CommercePhase1Test extends SecurityTestCase
{
    private function registerCustomer(User $owner, array $overrides = []): array
    {
        return $this->asTenantUser($owner)->postJson('/api/v1/customers', array_merge([
            'name' => 'Ama Boateng',
            'phone' => '0241111111',
        ], $overrides))->assertCreated()->json('data');
    }

    public function test_credit_sale_creates_invoice_and_customer_debt(): void
    {
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant);
        $product = $this->makeProduct($tenant, ['selling_price' => 50, 'stock_quantity' => 10]);
        $customer = $this->registerCustomer($owner, ['credit_limit' => 500]);

        $response = $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'credit',
            'customer_id' => $customer['id'],
            'amount_paid' => 0,
            'due_date' => now()->addDays(7)->toDateString(),
        ])->assertCreated();

        $sale = Sale::findOrFail($response->json('data.id'));

        $this->assertSame('credit', $sale->payment_method);
        $this->assertSame(100.0, $sale->balanceDue());
        $this->assertSame('unpaid', $sale->paymentStatus());

        // Debt ledger moved exactly once.
        $this->assertSame(100.0, (float) Customer::findOrFail($customer['id'])->balance);

        // Invoice auto-issued for the credit sale.
        $invoice = Invoice::where('sale_id', $sale->id)->firstOrFail();
        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{6}$/', $invoice->invoice_no);
    }

    public function test_partial_payment_reduces_balance_and_overpayment_is_rejected(): void
    {
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant);
        $product = $this->makeProduct($tenant, ['selling_price' => 80]);
        $customer = $this->registerCustomer($owner);

        $sale = $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'momo',
            'customer_id' => $customer['id'],
            'amount_paid' => 30,
        ])->assertCreated()->json('data');

        $pay = $this->asTenantUser($owner)->postJson("/api/v1/sales/{$sale['id']}/payments", [
            'amount' => 20, 'method' => 'cash',
        ])->assertCreated();

        $this->assertSame(30.0, (float) $pay->json('balance_due'));
        $this->assertSame(30.0, (float) Customer::findOrFail($customer['id'])->balance);

        $this->asTenantUser($owner)->postJson("/api/v1/sales/{$sale['id']}/payments", [
            'amount' => 31, 'method' => 'cash',
        ])->assertUnprocessable();

        $this->asTenantUser($owner)->postJson("/api/v1/sales/{$sale['id']}/payments", [
            'amount' => 30, 'method' => 'bank',
        ])->assertCreated();

        $this->assertSame(0.0, (float) Customer::findOrFail($customer['id'])->balance);
        $this->assertSame('paid', Sale::findOrFail($sale['id'])->paymentStatus());
    }

    public function test_credit_limit_and_missing_customer_are_rejected(): void
    {
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant);
        $product = $this->makeProduct($tenant, ['selling_price' => 100]);
        $customer = $this->registerCustomer($owner, ['credit_limit' => 50]);

        $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'credit',
            'customer_id' => $customer['id'],
            'amount_paid' => 0,
        ])->assertUnprocessable(); // exceeds 50 cedi limit

        // No customer attached at all → still rejected for under-payment.
        $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'amount_paid' => 40,
        ])->assertUnprocessable();
    }

    public function test_voiding_a_credit_sale_releases_the_debt(): void
    {
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant);
        $product = $this->makeProduct($tenant, ['selling_price' => 60, 'stock_quantity' => 3]);
        $customer = $this->registerCustomer($owner);

        $sale = $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'credit', 'customer_id' => $customer['id'], 'amount_paid' => 0,
        ])->assertCreated()->json('data');

        $this->assertSame(120.0, (float) Customer::findOrFail($customer['id'])->balance);

        $this->asTenantUser($owner)->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Mistake'])
            ->assertOk();

        $this->assertSame(0.0, (float) Customer::findOrFail($customer['id'])->balance);
        $this->assertSame(3, $product->refresh()->stock_quantity); // restored
    }

    public function test_purchase_partial_payment_and_payables(): void
    {
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant);
        $product = $this->makeProduct($tenant, ['stock_quantity' => 0]);

        $supplier = $this->asTenantUser($owner)->postJson('/api/v1/suppliers', [
            'name' => 'Kumasi Wholesale', 'phone' => '0322000000',
        ])->assertCreated()->json('data');

        $purchase = $this->asTenantUser($owner)->postJson('/api/v1/purchases', [
            'supplier_id' => $supplier['id'],
            'reference' => 'PO-001',
            'amount_paid' => 100,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 40]],
        ])->assertCreated()->json('data');

        $this->assertSame(400.0, (float) $purchase['total']);
        $this->assertSame('partial', Purchase::findOrFail($purchase['id'])->paymentStatus());

        $payables = $this->asTenantUser($owner)->getJson('/api/v1/purchases/payables')->assertOk();
        $this->assertSame(300.0, (float) $payables->json('total_outstanding'));

        $this->asTenantUser($owner)->postJson("/api/v1/purchases/{$purchase['id']}/payments", [
            'amount' => 300, 'method' => 'bank',
        ])->assertCreated();

        $this->assertSame('paid', Purchase::findOrFail($purchase['id'])->paymentStatus());

        // A paid purchase can no longer be cancelled.
        $this->asTenantUser($owner)->postJson("/api/v1/purchases/{$purchase['id']}/cancel")
            ->assertUnprocessable();
    }

    public function test_tax_applies_from_business_settings(): void
    {
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant);
        $product = $this->makeProduct($tenant, ['selling_price' => 100]);

        app(TenantContext::class)->setUser($owner);
        $settings = new BusinessSetting();
        $settings->tenant_id = $tenant->id;
        $settings->tax_rate  = 21;
        $settings->save();
        app(TenantContext::class)->clear();

        $sale = $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash', 'amount_paid' => 121,
            'taxable' => true,
        ])->assertCreated()->json('data');

        $this->assertSame('21.00', Sale::findOrFail($sale['id'])->tax);
        $this->assertSame('121.00', Sale::findOrFail($sale['id'])->total);
    }

    public function test_profit_loss_is_exact_and_never_fakes_profit(): void
    {
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant);
        $product = $this->makeProduct($tenant, ['cost_price' => 10, 'selling_price' => 20]);

        $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'cash', 'amount_paid' => 40,
        ])->assertCreated();

        $this->asTenantUser($owner)->postJson('/api/v1/expenses', [
            'name' => 'Trotro delivery', 'amount' => 15,
            'expense_date' => today()->toDateString(),
        ])->assertCreated();

        $pl = $this->asTenantUser($owner)->getJson('/api/v1/reports/profit-loss?period=day')->assertOk();

        $this->assertSame(40.0, (float) $pl->json('revenue'));
        $this->assertSame(20.0, (float) $pl->json('cogs'));
        $this->assertSame(20.0, (float) $pl->json('gross_profit'));
        $this->assertSame(15.0, (float) $pl->json('expenses.total'));
        $this->assertSame(5.0, (float) $pl->json('net_profit'));
        $this->assertTrue($pl->json('profit_available'));

        // Remove cost knowledge → profit MUST disappear, not be estimated.
        \DB::table('sale_items')->update(['unit_cost' => null]);

        $pl2 = $this->asTenantUser($owner)->getJson('/api/v1/reports/profit-loss?period=day')->assertOk();
        $this->assertFalse($pl2->json('profit_available'));
        $this->assertNull($pl2->json('gross_profit'));
        $this->assertNull($pl2->json('net_profit'));
        $this->assertSame(40.0, (float) $pl2->json('revenue')); // revenue is always real
    }

    public function test_monitor_reminds_once_then_expires_into_grace_then_suspends(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);
        $product = $this->makeProduct($tenant); // must survive suspension intact

        // 3 days until trial end → reminder fires exactly once across two runs.
        $tenant->subscriptions()->update(['ends_at' => now()->addDays(3)]);
        $this->artisan('subscriptions:monitor')->assertSuccessful();
        $this->artisan('subscriptions:monitor')->assertSuccessful();
        $this->assertSame(1, Notification::where('tenant_id', $tenant->id)
            ->where('type', 'subscription_reminder')->count());

        // Push end into the past → EXPIRED + grace window opens.
        $tenant->subscriptions()->update(['ends_at' => now()->subHours(2)]);
        $this->artisan('subscriptions:monitor')->assertSuccessful();

        $sub = $tenant->subscriptions()->first();
        $this->assertSame(Subscription::STATUS_EXPIRED, $sub->status);
        $this->assertNotNull($sub->grace_ends_at);
        // Tenant still fully operational during grace.
        $this->assertTrue($tenant->refresh()->hasOperationalAccess());

        // Grace elapses → automatic suspension, data intact.
        $tenant->subscriptions()->update(['grace_ends_at' => now()->subHour()]);
        $this->artisan('subscriptions:monitor')->assertSuccessful();

        $tenant->refresh();
        $this->assertTrue($tenant->isSuspended());
        $this->assertDatabaseHas('products', ['id' => $product->id, 'tenant_id' => $tenant->id, 'is_active' => 1]); // NEVER deleted
    }

    public function test_extension_revives_a_grace_suspended_tenant(): void
    {
        $tenant = $this->makeTenant();
        $tenant->subscriptions()->update(['ends_at' => now()->subDay(), 'status' => Subscription::STATUS_EXPIRED, 'grace_ends_at' => now()->subHour()]);
        $this->artisan('subscriptions:monitor')->assertSuccessful();
        $this->assertTrue($tenant->refresh()->isSuspended());

        $admin = $this->makeSuperAdmin(['permissions' => ['subscriptions.manage']]);
        $subId = $tenant->subscriptions()->first()->id;

        $this->asSuperAdmin($admin)->postJson("/api/v1/super-admin/subscriptions/{$subId}/extend", [
            'days' => 30, 'note' => 'Payment confirmed offline',
        ])->assertOk();

        $this->assertFalse($tenant->refresh()->isSuspended());
        $this->assertTrue($tenant->hasOperationalAccess());
        $this->assertSame(Subscription::STATUS_ACTIVE, $tenant->subscriptions()->first()->status);
    }

    public function test_plan_product_limit_is_enforced(): void
    {
        $tenant = $this->makeTenant();
        $owner  = $this->makeUser($tenant);

        // Cap the tenant's current plan at 1 product.
        $plan = $tenant->currentSubscription->plan;
        $plan->forceFill(['product_limit' => 1])->save();

        $this->asTenantUser($owner)->postJson('/api/v1/products', [
            'sku' => 'P-1', 'name' => 'First', 'cost_price' => 5, 'selling_price' => 8,
        ])->assertCreated();

        $this->asTenantUser($owner)->postJson('/api/v1/products', [
            'sku' => 'P-2', 'name' => 'Second', 'cost_price' => 5, 'selling_price' => 8,
        ])->assertUnprocessable();
    }

    public function test_cashier_cannot_manage_customers_or_expenses(): void
    {
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant);
        $cashier = $this->makeUser($tenant, User::ROLE_CASHIER);

        $this->asTenantUser($cashier)->postJson('/api/v1/customers', ['name' => 'X'])
            ->assertForbidden();
        $this->asTenantUser($cashier)->postJson('/api/v1/expenses', [
            'name' => 'Fuel', 'amount' => 10, 'expense_date' => today()->toDateString(),
        ])->assertForbidden();
        $this->asTenantUser($cashier)->getJson('/api/v1/activity')->assertForbidden();

        // …but the owner can.
        $this->asTenantUser($owner)->getJson('/api/v1/activity')->assertOk();
    }

    public function test_receivables_lists_overdue_sales_with_days(): void
    {
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant);
        $product = $this->makeProduct($tenant, ['selling_price' => 90]);
        $customer = $this->registerCustomer($owner);

        $sale = $this->asTenantUser($owner)->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'credit', 'customer_id' => $customer['id'],
            'amount_paid' => 0, 'due_date' => today()->addDays(10)->toDateString(),
        ])->assertCreated()->json('data');

        // Simulate the debt ageing: the API never accepts past due dates.
        Sale::findOrFail($sale['id'])->forceFill(['due_date' => today()->subDays(5)])->save();

        $rows = $this->asTenantUser($owner)->getJson('/api/v1/customers/receivables')->assertOk();

        $this->assertSame(90.0, (float) $rows->json('total_outstanding'));
        $this->assertSame(5, $rows->json('data.0.days_overdue'));
        $this->assertSame('Ama Boateng', $rows->json('data.0.customer.name'));
    }

    public function test_tickets_round_trip_between_tenant_and_platform(): void
    {
        $tenant  = $this->makeTenant();
        $owner   = $this->makeUser($tenant);

        $ticket = $this->asTenantUser($owner)->postJson('/api/v1/tickets', [
            'subject' => 'Receipt printer question', 'body' => 'How do I print?',
        ])->assertCreated()->json('data');

        // Platform sees the ticket & answers publicly.
        $admin = $this->makeSuperAdmin(['permissions' => ['support.manage']]);
        $this->asSuperAdmin($admin)->postJson("/api/v1/super-admin/tickets/{$ticket['id']}/replies", [
            'body' => 'We will send instructions.', 'is_internal' => false,
        ])->assertCreated();

        // Internal notes stay invisible to the tenant.
        $this->asSuperAdmin($admin)->postJson("/api/v1/super-admin/tickets/{$ticket['id']}/replies", [
            'body' => 'INTERNAL: check their config later', 'is_internal' => true,
        ])->assertCreated();

        $replies = $this->asTenantUser($owner)->getJson("/api/v1/tickets/{$ticket['id']}")->assertOk()->json('data.replies');
        $this->assertCount(2, $replies); // opening + public answer only

        // Tenant got notified about the public reply.
        $this->assertTrue(Notification::where('tenant_id', $tenant->id)
            ->where('type', 'support_reply')->exists());
    }
}
