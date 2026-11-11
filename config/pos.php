<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Identity
    |--------------------------------------------------------------------------
    */
    'platform_name' => env('PLATFORM_NAME', 'ShopStack'),
    'currency'      => env('PLATFORM_CURRENCY', 'GHS'),

    /*
    |--------------------------------------------------------------------------
    | Trial Subscription
    |--------------------------------------------------------------------------
    */
    'trial_days' => (int) env('TRIAL_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Auth / Session Policy
    |--------------------------------------------------------------------------
    | Token TTLs (Sanctum personal access tokens act as our API sessions).
    */
    'tokens' => [
        'tenant_hours'          => (int) env('TENANT_TOKEN_HOURS', 12),
        'tenant_remember_days'  => (int) env('TENANT_REMEMBER_DAYS', 30),
        'super_admin_hours'     => (int) env('SUPER_ADMIN_TOKEN_HOURS', 8),
        // Impersonation sessions are deliberately short-lived.
        'impersonation_minutes' => (int) env('IMPERSONATION_MINUTES', 60),
        // Time allowed to finish a pending 2FA challenge.
        'two_factor_challenge_minutes' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Policy
    |--------------------------------------------------------------------------
    */
    'passwords' => [
        'tenant_min'      => 10,
        'super_admin_min' => 14,
        'reset_token_ttl_minutes' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Login Throttling
    |--------------------------------------------------------------------------
    */
    'throttle' => [
        'login_attempts'     => 5,   // failures before lockout
        'login_lock_minutes' => 15,  // lockout duration
        'sa_login_attempts'  => 3,   // stricter for super admin
        'sa_lock_minutes'    => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles & Permissions (tenant side)
    |--------------------------------------------------------------------------
    | Deterministic role → permission map. The server is the ONLY authority;
    | any role/permission value sent by a client is ignored.
    */
    'roles' => ['owner', 'admin', 'manager', 'cashier', 'inventory', 'accountant'],

    /*
    | Permission catalogue (tenant plane). The middleware denies anything not
    | granted here. Owner always has ['*'].
    |
    |   dashboard.*  catalog (products/categories/brands/units)  suppliers.*
    |   customers.*  inventory.*  sales.*  payments.manage  purchases.*
    |   invoices.*   expenses.*   reports.*  billing.*  users.*  settings.manage
    |   activity.view  support.use
    */
    'permissions' => [
        'owner' => ['*'],

        // Administrator: full operations, but NOT tenant settings or the
        // immutable activity log (owner-only oversight surface).
        'admin' => [
            'dashboard.view',
            'products.view', 'products.manage',
            'categories.manage', 'brands.manage', 'units.manage',
            'suppliers.view', 'suppliers.manage',
            'customers.view', 'customers.manage',
            'inventory.view', 'inventory.adjust',
            'sales.view', 'sales.create', 'sales.void', 'sales.return',
            'payments.manage',
            'purchases.view', 'purchases.manage',
            'invoices.view',
            'expenses.view', 'expenses.manage',
            'reports.view', 'reports.export',
            'billing.view', 'billing.pay',
            'users.view', 'users.manage',
            'support.use',
        ],

        // Manager: runs the shop floor — no voids, no staff/settings access.
        'manager' => [
            'dashboard.view',
            'products.view', 'products.manage',
            'categories.manage', 'brands.manage', 'units.manage',
            'suppliers.view', 'suppliers.manage',
            'customers.view', 'customers.manage',
            'inventory.view', 'inventory.adjust',
            'sales.view', 'sales.create', 'sales.return',
            'payments.manage',
            'purchases.view', 'purchases.manage',
            'invoices.view',
            'expenses.view', 'expenses.manage',
            'reports.view',
            'billing.view',
            'support.use',
        ],

        // Cashier: front-of-house selling.
        'cashier' => [
            'dashboard.view',
            'products.view',
            'customers.view',
            'sales.view', 'sales.create', 'sales.return',
            'payments.manage',
            'invoices.view',
            'billing.view',
            'support.use',
        ],

        // Inventory staff: stock, suppliers, purchasing. No sales or money reports.
        'inventory' => [
            'dashboard.view',
            'products.view', 'products.manage',
            'categories.manage', 'brands.manage', 'units.manage',
            'suppliers.view', 'suppliers.manage',
            'inventory.view', 'inventory.adjust',
            'purchases.view', 'purchases.manage',
            'support.use',
        ],

        // Accountant: read-mostly financial oversight + receivables/payables.
        'accountant' => [
            'dashboard.view',
            'products.view',
            'suppliers.view',
            'customers.view', 'customers.manage',
            'sales.view',
            'payments.manage',
            'purchases.view',
            'invoices.view',
            'expenses.view', 'expenses.manage',
            'reports.view', 'reports.export',
            'billing.view', 'billing.pay',
            'activity.view',
            'support.use',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Super Admin Permissions
    |--------------------------------------------------------------------------
    */
    'super_admin_permissions' => [
        'tenants.view', 'tenants.create', 'tenants.suspend',
        'payments.review',          // approve / reject subscription payments
        'subscriptions.manage',
        'plans.manage',             // business plan catalogue (never hardcoded prices)
        'settings.manage',          // platform MoMo / bank settings + grace policy
        'impersonate',
        'audits.view',
        'admins.manage',
        'notifications.send',       // broadcast in-app notifications to tenants
        'support.manage',           // platform helpdesk (all tenants)
        'analytics.view',           // revenue, MRR/ARR, churn, growth
        'api.view',                 // API status / documentation surface
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    */
    'uploads' => [
        'product_image_max_kb' => 2048,
        'payment_proof_max_kb' => 4096,
        'allowed_image_mimes'  => ['image/jpeg', 'image/png', 'image/webp'],
        'allowed_image_ext'    => ['jpg', 'jpeg', 'png', 'webp'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowlists for dynamic query parameters (SQL-injection hardening)
    |--------------------------------------------------------------------------
    | Any sortable/filterable field outside these lists is rejected.
    */
    'sortable' => [
        'products'   => ['name', 'sku', 'selling_price', 'stock_quantity', 'created_at'],
        'sales'      => ['receipt_no', 'total', 'created_at'],
        'purchases'  => ['reference', 'total', 'created_at'],
        'customers'  => ['name', 'balance', 'created_at'],
        'expenses'   => ['date', 'amount', 'created_at'],
        'invoices'   => ['invoice_no', 'created_at'],
        'tenants'    => ['name', 'status', 'created_at'],
        'payments'   => ['amount', 'status', 'submitted_at'],
        'audits'     => ['created_at', 'action'],
        'movements'  => ['created_at', 'type'],
    ],

];
