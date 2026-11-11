<?php

namespace Database\Seeders;

use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * "Jerry's Provision Store" — a realistic Ghanaian provision shop, seeded
 * ENTIRELY through the real service layer (sales decrement stock, credit
 * sales build receivables, purchases update cost prices...), so the demo
 * doubles as proof the business logic works. Local/dev only.
 *
 * Owner login: jerry@demo.test / Tenant#Pass2026
 */
class JerryProvisionStoreSeeder extends Seeder
{
    private Tenant $tenant;
    private User $jerry;

    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }
        if (Tenant::where('slug', 'jerrys-provision-store')->exists()) {
            return; // idempotent
        }

        $this->tenant = Tenant::create([
            'name' => "Jerry's Provision Store",
            'slug' => 'jerrys-provision-store',
            'email' => 'jerry@demo.test',
            'phone' => '0244567890',
            'business_type' => 'Provision & General Goods',
            'address' => 'Hse 12, Banana Street, Dansoman',
            'city' => 'Accra',
            'region' => 'Greater Accra',
            'country' => 'Ghana',
            'currency' => 'GHS',
        ]);

        $this->jerry = new User([
            'name' => 'Jerry Osei', 'email' => 'jerry@demo.test', 'password' => 'Tenant#Pass2026',
        ]);
        $this->jerry->tenant_id = $this->tenant->id;
        $this->jerry->role = User::ROLE_OWNER;
        $this->jerry->status = User::STATUS_ACTIVE;
        $this->jerry->password_changed_at = now();
        $this->jerry->save();

        // All tenant-scoped writes below run inside Jerry's context,
        // exactly as his HTTP requests would.
        app(TenantContext::class)->setUser($this->jerry);

        $this->seedBusiness();
        [$categories, $suppliers, $products] = $this->seedCatalogue();
        $customers = $this->seedCustomers();

        // Business plan subscription (paid by MTN MoMo — approved).
        $this->seedSubscription();

        // Restocking purchases (partial payment on the latest one).
        $this->seedPurchases($suppliers, $products);

        // Two weeks of daily trading through the real SaleService.
        $this->seedTradingHistory($products, $customers);

        // Realistic operating expenses.
        $this->seedExpenses();

        app(TenantContext::class)->clear();
    }

    private function seedBusiness(): void
    {
        $settings = new BusinessSetting();
        $settings->tenant_id = $this->tenant->id;
        $settings->currency = 'GHS';
        $settings->tax_rate = 0;
        $settings->invoice_footer = "Thank you for shopping with us! Yɛbɛhyia bio! • MTN MoMo 0244567890";
        $settings->low_stock_default = 10;
        $settings->expiry_warn_days = 14;
        $settings->onboarding_completed = true;
        $settings->save();

        foreach (['Piece', 'Pack', 'Box', 'Bottle', 'Tin', 'Sachet', 'Bag', 'Crate', 'Pair'] as $unit) {
            (new Unit(['name' => $unit]))->forceFill(['tenant_id' => $this->tenant->id])->save();
        }
        foreach (['Rent', 'Utilities', 'Salaries & Wages', 'Transport & Logistics', 'Stock Purchase',
                  'Marketing & Advertising', 'Equipment & Repairs', 'Professional Fees', 'Taxes & Levies', 'Miscellaneous'] as $cat) {
            (new ExpenseCategory(['name' => $cat, 'is_system' => true]))->forceFill(['tenant_id' => $this->tenant->id])->save();
        }
    }

    private function seedCatalogue(): array
    {
        $catDairy     = Category::create(['name' => 'Dairy & Frozen']);
        $catBeverages = Category::create(['name' => 'Beverages']);
        $catFood      = Category::create(['name' => 'Food & Provisions']);
        $catPersonal  = Category::create(['name' => 'Personal Care']);
        $catHousehold = Category::create(['name' => 'Household']);

        $suppliers = [];
        foreach ([
            ['name' => 'Fan Milk Ghana Ltd', 'phone' => '0302745000', 'email' => 'orders@fanmilk.gh'],
            ['name' => 'Nestlé Ghana', 'phone' => '0302213456'],
            ['name' => 'Unilever Ghana', 'phone' => '0302776606'],
            ['name' => 'Makola Wholesale Depot', 'phone' => '0246789000'],
        ] as $s) {
            $suppliers[$s['name']] = Supplier::create($s);
        }

        // [sku, name, category, cost, sell, wholesale, stock_target, unit, expiry(+days)|null, supplier]
        $defs = [
            ['FM-FANYOGO-ST', 'FanYogo Strawberry (Fan Milk)', 'Dairy & Frozen', 1.10, 2.00, 1.60, 150, 'Piece', 45, 'Fan Milk Ghana Ltd'],
            ['FM-FANICE-VA', 'FanIce Vanilla (Fan Milk)', 'Dairy & Frozen', 1.20, 2.00, 1.70, 120, 'Piece', 45, 'Fan Milk Ghana Ltd'],
            ['FM-FANCHOCO', 'FanChoco (Fan Milk)', 'Dairy & Frozen', 1.30, 2.50, 2.00, 90, 'Piece', 40, 'Fan Milk Ghana Ltd'],
            ['NE-MILO-400', 'Milo Tin 400g', 'Beverages', 75.00, 95.00, 88.00, 24, 'Tin', 300, 'Nestlé Ghana'],
            ['NE-MILO-SACH', 'Milo Sachet Roll (12x18g)', 'Beverages', 12.00, 18.00, 15.00, 60, 'Pack', 300, 'Nestlé Ghana'],
            ['NE-COFFEE-SACH', 'Nescafé 3-in-1 Sachet Strip', 'Beverages', 9.00, 14.00, null, 80, 'Pack', 280, 'Nestlé Ghana'],
            ['BV-COKE-500', 'Coca-Cola 500ml PET', 'Beverages', 6.50, 9.00, 8.00, 96, 'Bottle', 120, 'Makola Wholesale Depot'],
            ['BV-FANTA-500', 'Fanta Orange 500ml PET', 'Beverages', 6.50, 9.00, 8.00, 72, 'Bottle', 120, 'Makola Wholesale Depot'],
            ['BV-MALTA-CAN', 'Malta Guinness Can 330ml', 'Beverages', 5.50, 8.00, 7.00, 60, 'Piece', 180, 'Makola Wholesale Depot'],
            ['FD-RICE-5KG', 'Royal Rice 5kg Bag', 'Food & Provisions', 95.00, 120.00, 110.00, 20, 'Bag', null, 'Makola Wholesale Depot'],
            ['FD-IDEAL-MILK', 'Ideal Milk Tin 170g', 'Food & Provisions', 6.00, 9.00, 7.50, 100, 'Tin', 240, 'Makola Wholesale Depot'],
            ['FD-GINO-SACH', 'Gino Tomato Paste Sachet 70g', 'Food & Provisions', 1.50, 2.50, 2.00, 200, 'Sachet', 11, 'Makola Wholesale Depot'],
            ['FD-INDOMIE', 'Indomie Chicken Noodles 70g', 'Food & Provisions', 2.80, 4.50, 3.80, 100, 'Pack', 200, 'Makola Wholesale Depot'],
            ['FD-EGGS-CRATE', 'Eggs Crate (30 pcs)', 'Food & Provisions', 42.00, 55.00, null, 15, 'Crate', 7, 'Makola Wholesale Depot'],
            ['FD-SARDINE', 'Titus Sardines in Oil 125g', 'Food & Provisions', 11.00, 15.00, 13.00, 60, 'Tin', 400, 'Makola Wholesale Depot'],
            ['FD-SUGAR-1KG', 'Aseda Sugar 1kg', 'Food & Provisions', 12.00, 16.00, 14.50, 45, 'Bag', null, 'Makola Wholesale Depot'],
            ['PC-KEY-SOAP', 'Key Soap Bar', 'Personal Care', 4.00, 6.00, 5.00, 80, 'Piece', null, 'Unilever Ghana'],
            ['PC-PEPSODENT', 'Pepsodent Toothpaste 140g', 'Personal Care', 14.00, 20.00, 17.00, 30, 'Piece', 500, 'Unilever Ghana'],
            ['HH-OMO-500', 'Omo Washing Powder 500g', 'Household', 16.00, 23.00, 20.00, 40, 'Pack', null, 'Unilever Ghana'],
            ['HH-TISSUE-4', 'Soft Tissue Roll (4 pack)', 'Household', 10.00, 15.00, 12.50, 50, 'Pack', null, 'Unilever Ghana'],
        ];

        $products = [];
        foreach ($defs as [$sku, $name, $cat, $cost, $sell, $wholesale, $target, $unit, $expiry, $supplier]) {
            $products[$sku] = Product::create([
                'category_id' => match (true) {
                    $cat === 'Dairy & Frozen' => $catDairy->id,
                    $cat === 'Beverages'     => $catBeverages->id,
                    $cat === 'Food & Provisions' => $catFood->id,
                    $cat === 'Personal Care' => $catPersonal->id,
                    default                  => $catHousehold->id,
                },
                'sku' => $sku,
                'name' => $name,
                'barcode' => '6' . str_pad((string) crc32($sku), 11, '0', STR_PAD_LEFT),
                'cost_price' => $cost,
                'selling_price' => $sell,
                'wholesale_price' => $wholesale,
                'low_stock_threshold' => 10,
                'unit' => $unit,
                'supplier_id' => $suppliers[$supplier]->id ?? null,
                'expiry_date' => $expiry ? now()->addDays($expiry)->toDateString() : null,
                'location' => 'Shelf',
                'is_active' => true,
            ]);
        }

        return [null, $suppliers, $products];
    }

    private function seedCustomers(): array
    {
        $customers = [];
        $customers['nii'] = Customer::create([
            'name' => 'Nii Armah Stores (Wholesale)', 'phone' => '0273334455',
            'type' => 'wholesale', 'credit_limit' => 2000,
            'notes' => 'Corner shop at circle — buys bulk weekly.',
        ]);
        $customers['efua'] = Customer::create([
            'name' => 'Auntie Efua (Mamprobi)', 'phone' => '0209887766', 'credit_limit' => 300,
        ]);
        $customers['grace'] = Customer::create([
            'name' => 'Madam Grace Arthur', 'phone' => '0551234432',
        ]);

        return $customers;
    }

    private function seedSubscription(): void
    {
        $plan = Plan::where('slug', 'business')->first();

        $payment = new SubscriptionPayment([
            'plan_id' => $plan->id,
            'amount' => $plan->price,
            'method' => 'momo',
            'sender_number' => '0244567890',
            'reference' => 'JPS-MM-' . now()->format('Ymd'),
            'status' => SubscriptionPayment::STATUS_APPROVED,
            'submitted_at' => now()->subDays(25),
            'reviewed_at' => now()->subDays(25),
            'reviewed_by' => null,
            'review_note' => 'Verified via MoMo statement (demo).',
        ]);
        $payment->forceFill(['tenant_id' => $this->tenant->id])->save();

        $this->tenant->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->subDays(25),
            'ends_at' => now()->addDays(5), // EXPIRING soon — showcases the radar
            'granted_by' => 'system',
            'source_payment_id' => $payment->id,
            'note' => 'Business plan via MTN MoMo',
        ]);
    }

    private function seedPurchases(array $suppliers, array $products): void
    {
        $service = app(PurchaseService::class);

        // Opening stock order — 20 days ago, fully paid.
        $po1 = $service->create($this->jerry, [
            'supplier_id' => $suppliers['Makola Wholesale Depot']->id,
            'reference' => 'PO-OPENING-001',
            'note' => 'Opening stock',
            'items' => collect($products)->map(fn ($p) => [
                'product_id' => $p->id,
                'quantity' => 60,
                'unit_cost' => (float) $p->cost_price,
            ])->values()->all(),
            'amount_paid' => collect($products)->sum(fn ($p) => (float) $p->cost_price * 60),
            'received_at' => now()->subDays(20),
        ]);
        $this->backdate('purchases', $po1->id, now()->subDays(20), ['created_at', 'updated_at', 'received_at']);
        $this->backdateMovements($po1, now()->subDays(20));

        // Dairy restock — 6 days ago, PARTIALLY paid (shows up in Payables).
        $dairySkus = ['FM-FANYOGO-ST', 'FM-FANICE-VA', 'FM-FANCHOCO', 'BV-MALTA-CAN'];
        $po2 = $service->create($this->jerry, [
            'supplier_id' => $suppliers['Fan Milk Ghana Ltd']->id,
            'reference' => 'PO-FM-002',
            'items' => collect($dairySkus)->map(fn ($k) => [
                'product_id' => $products[$k]->id,
                'quantity' => 50,
                'unit_cost' => (float) $products[$k]->cost_price,
            ])->values()->all(),
            'amount_paid' => 100,
            'due_date' => now()->addDays(8)->toDateString(),
            'received_at' => now()->subDays(6),
        ]);
        $this->backdate('purchases', $po2->id, now()->subDays(6), ['created_at', 'updated_at', 'received_at']);
        $this->backdateMovements($po2, now()->subDays(6));
    }

    private function seedTradingHistory(array $products, array $customers): void
    {
        $sales = app(SaleService::class);
        $skus = array_keys($products);
        $basket = 0;

        // 14 days of realistic daily trading.
        for ($day = 14; $day >= 0; $day--) {
            $when = now()->subDays($day)->setTime(random_int(8, 19), random_int(0, 59));
            $salesToday = $day === 0 ? 6 : random_int(2, 5);

            for ($i = 0; $i < $salesToday; $i++) {
                $items = collect(range(1, random_int(1, 3)))
                    ->map(function () use ($skus, $products) {
                        $sku = $skus[random_int(0, count($skus) - 1)];

                        return ['product' => $products[$sku], 'quantity' => random_int(1, 3)];
                    })
                    ->unique('product.id')
                    ->values();

                $lines = $items->map(fn ($l) => [
                    'product_id' => $l['product']->id,
                    'quantity' => $l['quantity'],
                ])->all();

                $total = $items->sum(fn ($l) => (float) $l['product']->selling_price * $l['quantity']);
                $method = ['cash', 'cash', 'momo', 'cash', 'bank'][($i + $day) % 5];

                try {
                    $sale = $sales->create($this->jerry, [
                        'items' => $lines,
                        'payment_method' => $method,
                        'amount_paid' => $total + ($method === 'cash' ? [0, 1, 5][($i + $basket++) % 3] : 0),
                        'issue_invoice' => $basket % 9 === 0,
                    ]);
                } catch (\Throwable) {
                    continue; // skip baskets the live stock couldn't satisfy
                }

                $this->backdateSale($sale, $when->copy()->addMinutes($i * 37));
            }
        }

        // Credit sale 1: partial repayment done, balance outstanding.
        $nii = $customers['nii'];
        $credit1 = $sales->create($this->jerry, [
            'items' => [
                ['product_id' => $products['BV-COKE-500']->id, 'quantity' => 12],
                ['product_id' => $products['NE-MILO-SACH']->id, 'quantity' => 5],
            ],
            'price_type' => 'wholesale',
            'payment_method' => 'credit',
            'customer_id' => $nii->id,
            'amount_paid' => 0,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
        $this->backdateSale($credit1, now()->subDays(5));
        $paid1 = $sales->recordPayment($credit1, 50, 'momo', 'Part payment on account', $this->jerry);
        $paid1->forceFill(['created_at' => now()->subDays(2)])->save();

        // Credit sale 2: OVERDUE receivable (due 4 days ago).
        $credit2 = $sales->create($this->jerry, [
            'items' => [
                ['product_id' => $products['FD-RICE-5KG']->id, 'quantity' => 2],
                ['product_id' => $products['FD-EGGS-CRATE']->id, 'quantity' => 1],
            ],
            'price_type' => 'wholesale',
            'payment_method' => 'credit',
            'customer_id' => $nii->id,
            'amount_paid' => 0,
            'due_date' => now()->addDays(3)->toDateString(),
        ]);
        $this->backdateSale($credit2, now()->subDays(7));
        $credit2->refresh()->forceFill(['due_date' => now()->subDays(4)->toDateString()])->save();

        // A couple of returns (damaged FanYogo) for history realism.
        $recent = Sale::where('status', Sale::STATUS_COMPLETED)->latest('created_at')->first();
        if ($recent && ($item = $recent->items->first())) {
            try {
                $ret = $sales->processReturn($recent, [
                    ['sale_item_id' => $item->id, 'quantity' => 1],
                ], 'Damaged packaging on arrival', $this->jerry);
                $ret->forceFill(['created_at' => now()->subHours(3)])->save();
            } catch (\Throwable) {
                // non-fatal for demo data
            }
        }
    }

    private function seedExpenses(): void
    {
        $cats = ExpenseCategory::pluck('id', 'name');

        foreach ([
            ['Shop rent — September', 800, 'Rent', 13],
            ['ECG prepaid', 220, 'Utilities', 10],
            ['Data bundles (POS + phone)', 50, 'Utilities', 7],
            ['Trotro delivery to Makola', 45, 'Transport & Logistics', 6],
            ['Ice for deep freezer', 24, 'Miscellaneous', 4],
            ['Trotro delivery to Makola', 45, 'Transport & Logistics', 2],
        ] as [$name, $amount, $cat, $daysAgo]) {
            (new Expense([
                'category_id' => $cats[$cat] ?? null,
                'name' => $name,
                'amount' => $amount,
                'expense_date' => now()->subDays($daysAgo)->toDateString(),
                'payment_method' => $amount > 100 ? 'momo' : 'cash',
            ]))->forceFill([
                'user_id' => $this->jerry->id,
                'created_at' => now()->subDays($daysAgo),
                'updated_at' => now()->subDays($daysAgo),
            ])->save();
        }
    }

    private function backdateSale(Sale $sale, $when): void
    {
        $sale->forceFill(['created_at' => $when, 'updated_at' => $when])->save();
        DB::table('sale_items')->where('sale_id', $sale->id)
            ->update(['created_at' => $when, 'updated_at' => $when]);
        $this->backdateMovements($sale, $when);
        if ($sale->invoice) {
            $sale->invoice->forceFill(['issue_date' => $when->toDateString(), 'created_at' => $when, 'updated_at' => $when])->save();
        }
    }

    private function backdateMovements($source, $when): void
    {
        DB::table('stock_movements')
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->id)
            ->update(['created_at' => $when]);
    }

    private function backdate(string $table, int $id, $when, array $columns): void
    {
        $payload = [];
        foreach ($columns as $col) {
            $payload[$col] = $col === 'received_at' ? $when : $when;
        }
        DB::table($table)->where('id', $id)->update($payload);
    }
}
