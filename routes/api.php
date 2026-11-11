<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| Two strictly separated planes:
|   • /api/v1/...            tenant plane (tenant Sanctum tokens only)
|   • /api/v1/super-admin/   platform plane (super-admin tokens + 2FA only)
|
| Cross-plane tokens are rejected by middleware. Tenant context is derived
| exclusively from the authenticated principal — never from request input.
*/

Route::prefix('v1')->group(function () {

    /*
    |----------------------------------------------------------------------
    | Public authentication endpoints (heavily rate limited)
    |----------------------------------------------------------------------
    */
    Route::post('auth/register', [\App\Http\Controllers\Api\V1\AuthController::class, 'register'])
        ->middleware('throttle:auth.register');
    Route::post('auth/login', [\App\Http\Controllers\Api\V1\AuthController::class, 'login'])
        ->middleware('throttle:auth.login');
    Route::post('auth/forgot-password', [\App\Http\Controllers\Api\V1\AuthController::class, 'forgotPassword'])
        ->middleware('throttle:auth.reset');
    Route::post('auth/reset-password', [\App\Http\Controllers\Api\V1\AuthController::class, 'resetPassword'])
        ->middleware('throttle:auth.reset');

    // Public plan catalogue (used by the public signup page).
    Route::get('billing/plans', [\App\Http\Controllers\Api\V1\BillingController::class, 'plans']);

    // Public platform identity for the marketing site. STRICTLY non-sensitive:
    // name, currency, trial length. Payment details are NOT public — only
    // authenticated tenants ever receive them.
    Route::get('platform', fn () => response()->json([
        'name'       => config('pos.platform_name'),
        'tagline'    => 'Run Your Business. Know Your Numbers.',
        'currency'   => config('pos.currency'),
        'trial_days' => config('pos.trial_days'),
    ]));

    /*
    |----------------------------------------------------------------------
    | SUPER ADMIN PLANE — completely separate endpoints
    |----------------------------------------------------------------------
    */
    Route::prefix('super-admin')->group(function () {

        Route::post('login', [\App\Http\Controllers\SuperAdmin\AuthController::class, 'login'])
            ->middleware('throttle:sa.auth');
        Route::post('two-factor/challenge', [\App\Http\Controllers\SuperAdmin\AuthController::class, 'twoFactorChallenge'])
            ->middleware('throttle:sa.auth');

        Route::middleware(['auth:sanctum', 'throttle:sa.api'])->group(function () {

            // Pre-2FA enrollment endpoints (restricted tokens allowed).
            Route::post('two-factor/setup', [\App\Http\Controllers\SuperAdmin\AuthController::class, 'twoFactorSetup'])
                ->middleware('superadmin:setup');
            Route::post('two-factor/confirm', [\App\Http\Controllers\SuperAdmin\AuthController::class, 'twoFactorConfirm'])
                ->middleware('superadmin:setup');
            Route::post('logout', [\App\Http\Controllers\SuperAdmin\AuthController::class, 'logout'])
                ->middleware('superadmin:setup');

            // Full super-admin endpoints (require completed 2FA).
            Route::middleware('superadmin')->group(function () {
                Route::get('me', [\App\Http\Controllers\SuperAdmin\AuthController::class, 'me']);
                Route::post('change-password', [\App\Http\Controllers\SuperAdmin\AuthController::class, 'changePassword']);

                // Tenants
                Route::get('tenants', [\App\Http\Controllers\SuperAdmin\TenantController::class, 'index'])
                    ->middleware('superadmin.perm:tenants.view');
                Route::post('tenants', [\App\Http\Controllers\SuperAdmin\TenantController::class, 'store'])
                    ->middleware('superadmin.perm:tenants.create');
                Route::get('tenants/{tenant}', [\App\Http\Controllers\SuperAdmin\TenantController::class, 'show'])
                    ->middleware('superadmin.perm:tenants.view');
                Route::post('tenants/{tenant}/suspend', [\App\Http\Controllers\SuperAdmin\TenantController::class, 'suspend'])
                    ->middleware('superadmin.perm:tenants.suspend');
                Route::post('tenants/{tenant}/restore', [\App\Http\Controllers\SuperAdmin\TenantController::class, 'restore'])
                    ->middleware('superadmin.perm:tenants.suspend');
                Route::post('tenants/{tenant}/subscription', [\App\Http\Controllers\SuperAdmin\TenantController::class, 'adjustSubscription'])
                    ->middleware('superadmin.perm:subscriptions.manage');

                // Impersonation (short-lived, reasoned, fully audited)
                Route::get('impersonations', [\App\Http\Controllers\SuperAdmin\ImpersonationController::class, 'index'])
                    ->middleware('superadmin.perm:impersonate');
                Route::post('tenants/{tenant}/impersonate', [\App\Http\Controllers\SuperAdmin\ImpersonationController::class, 'start'])
                    ->middleware(['superadmin.perm:impersonate', 'throttle:impersonate']);
                Route::post('impersonations/{session}/stop', [\App\Http\Controllers\SuperAdmin\ImpersonationController::class, 'stop'])
                    ->middleware('superadmin.perm:impersonate');

                // Payment review queue — the ONLY place payment status changes.
                Route::get('payments', [\App\Http\Controllers\SuperAdmin\PaymentController::class, 'index'])
                    ->middleware('superadmin.perm:payments.review');
                Route::get('payments/{payment}', [\App\Http\Controllers\SuperAdmin\PaymentController::class, 'show'])
                    ->middleware('superadmin.perm:payments.review');
                Route::post('payments/{payment}/review', [\App\Http\Controllers\SuperAdmin\PaymentController::class, 'review'])
                    ->middleware('superadmin.perm:payments.review');
                Route::get('payments/{payment}/proof', [\App\Http\Controllers\SuperAdmin\PaymentController::class, 'proof'])
                    ->middleware('superadmin.perm:payments.review');

                // Platform payment (MoMo / bank) settings
                Route::get('settings/payment', [\App\Http\Controllers\SuperAdmin\PlatformSettingController::class, 'show'])
                    ->middleware('superadmin.perm:settings.manage');
                Route::put('settings/payment', [\App\Http\Controllers\SuperAdmin\PlatformSettingController::class, 'update'])
                    ->middleware('superadmin.perm:settings.manage');

                // Platform audit trail
                Route::get('audits', [\App\Http\Controllers\SuperAdmin\AuditController::class, 'index'])
                    ->middleware('superadmin.perm:audits.view');

                // Super-admin account management
                Route::get('admins', [\App\Http\Controllers\SuperAdmin\AdminManagementController::class, 'index'])
                    ->middleware('superadmin.perm:admins.manage');
                Route::post('admins', [\App\Http\Controllers\SuperAdmin\AdminManagementController::class, 'store'])
                    ->middleware('superadmin.perm:admins.manage');
                Route::post('admins/{admin}/deactivate', [\App\Http\Controllers\SuperAdmin\AdminManagementController::class, 'deactivate'])
                    ->middleware('superadmin.perm:admins.manage');

                // Platform dashboards & intelligence
                Route::get('dashboard', [\App\Http\Controllers\SuperAdmin\DashboardController::class, 'index'])
                    ->middleware('superadmin.perm:tenants.view');
                Route::get('revenue', [\App\Http\Controllers\SuperAdmin\RevenueController::class, 'index'])
                    ->middleware('superadmin.perm:analytics.view');
                Route::get('analytics', [\App\Http\Controllers\SuperAdmin\AnalyticsController::class, 'index'])
                    ->middleware('superadmin.perm:analytics.view');
                Route::get('search', [\App\Http\Controllers\SuperAdmin\SearchController::class, 'index'])
                    ->middleware('superadmin.perm:tenants.view');

                // Plan catalogue (never hardcoded anywhere)
                Route::get('plans', [\App\Http\Controllers\SuperAdmin\PlanController::class, 'index'])
                    ->middleware('superadmin.perm:plans.manage');
                Route::post('plans', [\App\Http\Controllers\SuperAdmin\PlanController::class, 'store'])
                    ->middleware('superadmin.perm:plans.manage');
                Route::put('plans/{plan}', [\App\Http\Controllers\SuperAdmin\PlanController::class, 'update'])
                    ->middleware('superadmin.perm:plans.manage');
                Route::delete('plans/{plan}', [\App\Http\Controllers\SuperAdmin\PlanController::class, 'destroy'])
                    ->middleware('superadmin.perm:plans.manage');

                // Subscription lifecycle across tenants
                Route::get('subscriptions', [\App\Http\Controllers\SuperAdmin\AdminSubscriptionController::class, 'index'])
                    ->middleware('superadmin.perm:subscriptions.manage');
                Route::post('subscriptions/{subscription}/extend', [\App\Http\Controllers\SuperAdmin\AdminSubscriptionController::class, 'extend'])
                    ->middleware('superadmin.perm:subscriptions.manage');

                // Broadcast notifications to tenants
                Route::post('broadcasts', [\App\Http\Controllers\SuperAdmin\BroadcastController::class, 'store'])
                    ->middleware('superadmin.perm:notifications.send');

                // Platform notification bell
                Route::get('notifications', [\App\Http\Controllers\SuperAdmin\AdminNotificationController::class, 'index'])
                    ->middleware('superadmin.perm:tenants.view');
                Route::post('notifications/{notification}/read', [\App\Http\Controllers\SuperAdmin\AdminNotificationController::class, 'markRead'])
                    ->middleware('superadmin.perm:tenants.view');
                Route::post('notifications/read-all', [\App\Http\Controllers\SuperAdmin\AdminNotificationController::class, 'markAllRead'])
                    ->middleware('superadmin.perm:tenants.view');

                // Helpdesk (platform side)
                Route::get('tickets', [\App\Http\Controllers\SuperAdmin\SupportController::class, 'index'])
                    ->middleware('superadmin.perm:support.manage');
                Route::get('tickets/{ticket}', [\App\Http\Controllers\SuperAdmin\SupportController::class, 'show'])
                    ->middleware('superadmin.perm:support.manage');
                Route::post('tickets/{ticket}/replies', [\App\Http\Controllers\SuperAdmin\SupportController::class, 'reply'])
                    ->middleware('superadmin.perm:support.manage');
                Route::post('tickets/{ticket}/assign', [\App\Http\Controllers\SuperAdmin\SupportController::class, 'assign'])
                    ->middleware('superadmin.perm:support.manage');
                Route::post('tickets/{ticket}/status', [\App\Http\Controllers\SuperAdmin\SupportController::class, 'setStatus'])
                    ->middleware('superadmin.perm:support.manage');

                // API documentation / status surface
                Route::get('api-status', [\App\Http\Controllers\SuperAdmin\ApiStatusController::class, 'index'])
                    ->middleware('superadmin.perm:api.view');
            });
        });
    });

    /*
    |----------------------------------------------------------------------
    | TENANT PLANE
    |----------------------------------------------------------------------
    | Middleware order matters: authenticate → resolve tenant context →
    | (per-route) subscription gate + permission checks.
    */
    Route::middleware(['auth:sanctum', 'throttle:tenant.api', 'tenant.context'])->group(function () {

        // Identity & account (never subscription-gated)
        Route::get('me', [\App\Http\Controllers\Api\V1\AuthController::class, 'me']);
        Route::post('auth/logout', [\App\Http\Controllers\Api\V1\AuthController::class, 'logout']);
        Route::post('auth/logout-all', [\App\Http\Controllers\Api\V1\AuthController::class, 'logoutAll']);
        Route::post('auth/change-password', [\App\Http\Controllers\Api\V1\AuthController::class, 'changePassword']);
        Route::post('impersonation/end', [\App\Http\Controllers\SuperAdmin\ImpersonationController::class, 'stopCurrent']);

        // Billing — accessible even when expired so renewal is always possible.
        /* Settings/lifecycle reads stay available during grace & expiry so the
           tenant always understands its own state. */
        Route::get('settings', [\App\Http\Controllers\Api\V1\SettingsController::class, 'show']);
        Route::put('settings', [\App\Http\Controllers\Api\V1\SettingsController::class, 'update'])
            ->middleware('permission:settings.manage');
        Route::put('settings/profile', [\App\Http\Controllers\Api\V1\SettingsController::class, 'updateProfile'])
            ->middleware('permission:settings.manage');
        Route::post('settings/logo', [\App\Http\Controllers\Api\V1\SettingsController::class, 'uploadLogo'])
            ->middleware(['permission:settings.manage', 'throttle:uploads']);
        Route::get('settings/logo', [\App\Http\Controllers\Api\V1\SettingsController::class, 'logo']);
        Route::post('settings/onboarding/complete', [\App\Http\Controllers\Api\V1\SettingsController::class, 'completeOnboarding']);

        Route::get('notifications', [\App\Http\Controllers\Api\V1\NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [\App\Http\Controllers\Api\V1\NotificationController::class, 'unreadCount']);
        Route::post('notifications/{notification}/read', [\App\Http\Controllers\Api\V1\NotificationController::class, 'markRead']);
        Route::post('notifications/read-all', [\App\Http\Controllers\Api\V1\NotificationController::class, 'markAllRead']);

        Route::get('billing/status', [\App\Http\Controllers\Api\V1\BillingController::class, 'status'])
            ->middleware('permission:billing.view');
        Route::get('billing/payments', [\App\Http\Controllers\Api\V1\BillingController::class, 'payments'])
            ->middleware('permission:billing.view');
        Route::get('billing/platform-payment-info', [\App\Http\Controllers\Api\V1\BillingController::class, 'platformPaymentInfo'])
            ->middleware('permission:billing.view');
        Route::post('billing/payments', [\App\Http\Controllers\Api\V1\BillingController::class, 'submitPayment'])
            ->middleware(['permission:billing.pay', 'throttle:payments.submit']);

        /*
        |------------------------------------------------------------------
        | Business operations — gated by subscription/suspension status.
        | (Expired tenants keep read access; mutations are rejected.)
        |------------------------------------------------------------------
        */
        Route::middleware('subscription.active')->group(function () {

            // Combined product form metadata (categories + brands + units)
            Route::get('catalog', [\App\Http\Controllers\Api\V1\CatalogController::class, 'index'])
                ->middleware('permission:products.view');
            Route::post('catalog/brands', [\App\Http\Controllers\Api\V1\CatalogController::class, 'storeBrand'])
                ->middleware('permission:brands.manage');
            Route::post('catalog/units', [\App\Http\Controllers\Api\V1\CatalogController::class, 'storeUnit'])
                ->middleware('permission:units.manage');

            // Customers, credit and receivables
            Route::get('customers', [\App\Http\Controllers\Api\V1\CustomerController::class, 'index'])
                ->middleware('permission:customers.view');
            Route::post('customers', [\App\Http\Controllers\Api\V1\CustomerController::class, 'store'])
                ->middleware('permission:customers.manage');
            Route::get('customers/receivables', [\App\Http\Controllers\Api\V1\CustomerController::class, 'receivables'])
                ->middleware('permission:customers.view');
            Route::get('customers/{customer}', [\App\Http\Controllers\Api\V1\CustomerController::class, 'show'])
                ->middleware('permission:customers.view');
            Route::put('customers/{customer}', [\App\Http\Controllers\Api\V1\CustomerController::class, 'update'])
                ->middleware('permission:customers.manage');
            Route::get('customers/{customer}/statement', [\App\Http\Controllers\Api\V1\CustomerController::class, 'statement'])
                ->middleware('permission:customers.view');

            // Categories
            Route::get('categories', [\App\Http\Controllers\Api\V1\CategoryController::class, 'index'])
                ->middleware('permission:products.view');
            Route::post('categories', [\App\Http\Controllers\Api\V1\CategoryController::class, 'store'])
                ->middleware('permission:categories.manage');
            Route::put('categories/{category}', [\App\Http\Controllers\Api\V1\CategoryController::class, 'update'])
                ->middleware('permission:categories.manage');
            Route::delete('categories/{category}', [\App\Http\Controllers\Api\V1\CategoryController::class, 'destroy'])
                ->middleware('permission:categories.manage');

            // Products
            Route::get('products', [\App\Http\Controllers\Api\V1\ProductController::class, 'index'])
                ->middleware('permission:products.view');
            Route::get('products/low-stock', [\App\Http\Controllers\Api\V1\ProductController::class, 'lowStock'])
                ->middleware('permission:products.view');
            Route::get('products/expiring', [\App\Http\Controllers\Api\V1\ProductController::class, 'expiring'])
                ->middleware('permission:products.view');
            Route::post('products', [\App\Http\Controllers\Api\V1\ProductController::class, 'store'])
                ->middleware('permission:products.manage');
            Route::get('products/{product}', [\App\Http\Controllers\Api\V1\ProductController::class, 'show'])
                ->middleware('permission:products.view');
            Route::get('products/{product}/image', [\App\Http\Controllers\Api\V1\ProductController::class, 'image'])
                ->middleware('permission:products.view');
            Route::put('products/{product}', [\App\Http\Controllers\Api\V1\ProductController::class, 'update'])
                ->middleware('permission:products.manage');
            Route::delete('products/{product}', [\App\Http\Controllers\Api\V1\ProductController::class, 'destroy'])
                ->middleware('permission:products.manage');
            Route::post('products/{product}/image', [\App\Http\Controllers\Api\V1\ProductController::class, 'uploadImage'])
                ->middleware(['permission:products.manage', 'throttle:uploads']);

            // Suppliers
            Route::get('suppliers', [\App\Http\Controllers\Api\V1\SupplierController::class, 'index'])
                ->middleware('permission:purchases.view|suppliers.manage');
            Route::post('suppliers', [\App\Http\Controllers\Api\V1\SupplierController::class, 'store'])
                ->middleware('permission:suppliers.manage');
            Route::put('suppliers/{supplier}', [\App\Http\Controllers\Api\V1\SupplierController::class, 'update'])
                ->middleware('permission:suppliers.manage');
            Route::delete('suppliers/{supplier}', [\App\Http\Controllers\Api\V1\SupplierController::class, 'destroy'])
                ->middleware('permission:suppliers.manage');

            // Sales (immutable financial records + void/return reversals)
            Route::get('sales', [\App\Http\Controllers\Api\V1\SaleController::class, 'index'])
                ->middleware('permission:sales.view');
            Route::post('sales', [\App\Http\Controllers\Api\V1\SaleController::class, 'store'])
                ->middleware('permission:sales.create');
            Route::get('sales/{sale}', [\App\Http\Controllers\Api\V1\SaleController::class, 'show'])
                ->middleware('permission:sales.view');
            Route::post('sales/{sale}/payments', [\App\Http\Controllers\Api\V1\SaleController::class, 'recordPayment'])
                ->middleware('permission:payments.manage');
            Route::post('sales/{sale}/void', [\App\Http\Controllers\Api\V1\SaleController::class, 'void'])
                ->middleware('permission:sales.void');
            Route::post('sales/{sale}/returns', [\App\Http\Controllers\Api\V1\SaleController::class, 'processReturn'])
                ->middleware('permission:sales.return');

            // Purchases
            Route::get('purchases', [\App\Http\Controllers\Api\V1\PurchaseController::class, 'index'])
                ->middleware('permission:purchases.view');
            Route::post('purchases', [\App\Http\Controllers\Api\V1\PurchaseController::class, 'store'])
                ->middleware('permission:purchases.manage');
            Route::get('purchases/payables', [\App\Http\Controllers\Api\V1\PurchaseController::class, 'payables'])
                ->middleware('permission:purchases.view');
            Route::get('purchases/{purchase}', [\App\Http\Controllers\Api\V1\PurchaseController::class, 'show'])
                ->middleware('permission:purchases.view');
            Route::post('purchases/{purchase}/payments', [\App\Http\Controllers\Api\V1\PurchaseController::class, 'recordPayment'])
                ->middleware('permission:payments.manage');
            Route::post('purchases/{purchase}/cancel', [\App\Http\Controllers\Api\V1\PurchaseController::class, 'cancel'])
                ->middleware('permission:purchases.manage');

            // Invoices (status is derived from the linked sale)
            Route::get('invoices', [\App\Http\Controllers\Api\V1\InvoiceController::class, 'index'])
                ->middleware('permission:invoices.view');
            Route::get('invoices/{invoice}', [\App\Http\Controllers\Api\V1\InvoiceController::class, 'show'])
                ->middleware('permission:invoices.view');

            // Expenses
            Route::get('expense-categories', [\App\Http\Controllers\Api\V1\ExpenseController::class, 'categories'])
                ->middleware('permission:expenses.view');
            Route::post('expense-categories', [\App\Http\Controllers\Api\V1\ExpenseController::class, 'storeCategory'])
                ->middleware('permission:expenses.manage');
            Route::get('expenses', [\App\Http\Controllers\Api\V1\ExpenseController::class, 'index'])
                ->middleware('permission:expenses.view');
            Route::post('expenses', [\App\Http\Controllers\Api\V1\ExpenseController::class, 'store'])
                ->middleware('permission:expenses.manage');
            Route::put('expenses/{expense}', [\App\Http\Controllers\Api\V1\ExpenseController::class, 'update'])
                ->middleware('permission:expenses.manage');

            // Suppliers detail (profile & credit) — index/manage siblings exist above
            Route::get('suppliers/{supplier}', [\App\Http\Controllers\Api\V1\SupplierController::class, 'show'])
                ->middleware('permission:suppliers.view|suppliers.manage');

            // Inventory ledger + adjustments
            Route::get('stock/movements', [\App\Http\Controllers\Api\V1\StockController::class, 'movements'])
                ->middleware('permission:inventory.view');
            Route::post('stock/adjustments', [\App\Http\Controllers\Api\V1\StockController::class, 'adjust'])
                ->middleware('permission:inventory.adjust');

            // Reports
            Route::get('reports/dashboard', [\App\Http\Controllers\Api\V1\ReportController::class, 'dashboard'])
                ->middleware('permission:dashboard.view|reports.view');
            Route::get('reports/profit-loss', [\App\Http\Controllers\Api\V1\ReportController::class, 'profitLoss'])
                ->middleware('permission:reports.view');
            Route::get('reports/sales', [\App\Http\Controllers\Api\V1\ReportController::class, 'salesReport'])
                ->middleware('permission:reports.view');
            Route::get('reports/sales-summary', [\App\Http\Controllers\Api\V1\ReportController::class, 'salesSummary'])
                ->middleware('permission:reports.view|dashboard.view');
            Route::get('reports/top-products', [\App\Http\Controllers\Api\V1\ReportController::class, 'topProducts'])
                ->middleware('permission:reports.view');

            // Immutable activity log (owner/accountant oversight)
            Route::get('activity', [\App\Http\Controllers\Api\V1\ActivityController::class, 'index'])
                ->middleware('permission:activity.view');

            // Helpdesk (tenant side)
            Route::get('tickets', [\App\Http\Controllers\Api\V1\TicketController::class, 'index'])
                ->middleware('permission:support.use');
            Route::post('tickets', [\App\Http\Controllers\Api\V1\TicketController::class, 'store'])
                ->middleware('permission:support.use');
            Route::get('tickets/{ticket}', [\App\Http\Controllers\Api\V1\TicketController::class, 'show'])
                ->middleware('permission:support.use');
            Route::post('tickets/{ticket}/replies', [\App\Http\Controllers\Api\V1\TicketController::class, 'reply'])
                ->middleware('permission:support.use');

            // Staff management (owner only via permission map)
            Route::get('users', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'index'])
                ->middleware('permission:users.manage');
            Route::post('users', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'store'])
                ->middleware('permission:users.manage');
            Route::put('users/{user}', [\App\Http\Controllers\Api\V1\UserManagementController::class, 'update'])
                ->middleware('permission:users.manage');
        });
    });
});
