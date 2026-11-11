<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubscriptionAdjustRequest;
use App\Http\Requests\SuperAdminTenantStoreRequest;
use App\Http\Requests\TenantSuspendRequest;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SubscriptionService;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
        private SubscriptionService $subscriptions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Tenant::query()->with('currentSubscription.plan')
            ->withCount('users');

        if ($request->filled('status') && in_array($request->query('status'), ['active', 'suspended'], true)) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $term = ListQuery::like((string) $request->query('search'));
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('slug', 'like', $term)->orWhere('email', 'like', $term));
        }

        ListQuery::applySort($query, $request, 'tenants', 'created_at', 'desc');

        // Server-side lifecycle resolution — the console renders 🟢🔵🟡🟠🔴⚫
        // straight from the stage, with zero client-side date logic.
        $paginator = $query->paginate(ListQuery::perPage($request));
        $paginator->through(function (Tenant $t) {
            return $t->toArray() + ['lifecycle' => \App\Support\Lifecycle::badge($t)];
        });

        return response()->json($paginator);
    }

    public function store(SuperAdminTenantStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        [$tenant, $owner] = DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'name'  => $data['name'],
                'slug'  => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'],
            ]);

            $owner = new User([
                'name'     => $data['owner_name'],
                'email'    => $data['email'],
                'password' => $data['password'],
            ]);
            $owner->tenant_id = $tenant->id;
            $owner->role      = User::ROLE_OWNER;
            $owner->status    = User::STATUS_ACTIVE;
            $owner->password_changed_at = now();
            $owner->save();

            return [$tenant, $owner];
        });

        if ($data['grant_trial'] ?? true) {
            $this->subscriptions->startTrial($tenant);
        }

        $this->audit->log('tenant.created', [
            'subject' => $tenant,
            'context' => ['name' => $tenant->name, 'owner_email' => $owner->email],
        ]);

        return response()->json(['data' => $tenant->load('currentSubscription.plan')], 201);
    }

    /**
     * Full tenant dossier for the 15-tab detail screen, delivered as
     * sectioned server data (overview / business / users / subscription /
     * lifecycle / payments / usage / activity).
     */
    public function show(Tenant $tenant): JsonResponse
    {
        $tenant->load(['currentSubscription.plan']);
        $tenant->loadCount(['users', 'subscriptions', 'payments']);

        $usage = [
            'users'     => User::where('tenant_id', $tenant->id)->count(),
            'products'  => DB::table('products')->where('tenant_id', $tenant->id)->where('is_active', true)->count(),
            'sales'     => DB::table('sales')->where('tenant_id', $tenant->id)->where('status', 'completed')->count(),
            'sales_total' => round((float) DB::table('sales')->where('tenant_id', $tenant->id)->where('status', 'completed')->sum('total'), 2),
            'customers' => DB::table('customers')->where('tenant_id', $tenant->id)->count(),
        ];

        $lifecycle = \App\Support\Lifecycle::stage($tenant);

        return response()->json([
            'data'     => $tenant,
            'lifecycle' => [
                'stage'     => $lifecycle['stage'],
                'days_left' => $lifecycle['days_left'] ?? null,
                'detail'    => $lifecycle['detail'] ?? null,
            ],
            'users'    => User::where('tenant_id', $tenant->id)
                ->orderBy('name')->get(['id', 'name', 'email', 'role', 'status', 'last_login_at']),
            'subscriptions' => $tenant->subscriptions()
                ->with('plan:id,name,price')->latest('created_at')->limit(12)->get(),
            'payments' => $tenant->payments()->latest('submitted_at')->limit(12)
                ->get(['id', 'plan_id', 'amount', 'method', 'reference', 'status', 'submitted_at', 'reviewed_at', 'review_note']),
            'usage'    => $usage,
            'recent_activity' => \App\Models\Audit::where('tenant_id', $tenant->id)
                ->latest('created_at')->limit(15)
                ->get(['action', 'actor_label', 'actor_email', 'created_at', 'impersonation_session_id']),
        ]);
    }

    /** Dangerous action — requires explicit confirmation (validated). */
    public function suspend(TenantSuspendRequest $request, Tenant $tenant): JsonResponse
    {
        if ($tenant->isSuspended()) {
            return response()->json(['message' => 'Tenant is already suspended.', 'code' => 'already_suspended'], 422);
        }

        $tenant->forceFill([
            'status'            => Tenant::STATUS_SUSPENDED,
            'suspended_at'      => now(),
            'suspension_reason' => $request->validated('reason'),
            'suspended_by'      => $request->user()->id,
        ])->save();

        $this->audit->log('tenant.suspended', [
            'subject' => $tenant,
            'tenant_id' => $tenant->id,
            'context' => ['reason' => $request->validated('reason')],
        ]);

        return response()->json(['data' => $tenant->fresh(), 'message' => 'Tenant suspended.']);
    }

    public function restore(Request $request, Tenant $tenant): JsonResponse
    {
        $tenant->forceFill([
            'status'            => Tenant::STATUS_ACTIVE,
            'suspended_at'      => null,
            'suspension_reason' => null,
            'suspended_by'      => null,
        ])->save();

        $this->audit->log('tenant.restored', [
            'subject' => $tenant,
            'tenant_id' => $tenant->id,
        ]);

        return response()->json(['data' => $tenant->fresh(), 'message' => 'Tenant restored.']);
    }

    /** Grant/extend a subscription manually (fully audited). */
    public function adjustSubscription(SubscriptionAdjustRequest $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validated();

        $subscription = DB::transaction(function () use ($tenant, $data, $request) {
            $plan = \App\Models\Plan::whereKey($data['plan_id'])->where('is_active', true)->firstOrFail();

            $currentEnd = $tenant->subscriptions()
                ->whereIn('status', [Subscription::STATUS_TRIALING, Subscription::STATUS_ACTIVE])
                ->where('ends_at', '>', now())
                ->max('ends_at');

            $endsAt = ($currentEnd ? \Carbon\Carbon::parse($currentEnd) : now())->addDays($plan->duration_days);

            return $tenant->subscriptions()->create([
                'plan_id'    => $plan->id,
                'status'     => Subscription::STATUS_ACTIVE,
                'starts_at'  => now(),
                'ends_at'    => $endsAt,
                'granted_by' => 'super_admin:'.$request->user()->id,
                'note'       => $data['note'],
            ]);
        });

        $this->audit->log('subscription.adjusted', [
            'subject'   => $tenant,
            'tenant_id' => $tenant->id,
            'context'   => ['plan_id' => $data['plan_id'], 'note' => $data['note'], 'ends_at' => $subscription->ends_at->toISOString()],
        ]);

        return response()->json(['data' => $subscription->load('plan'), 'message' => 'Subscription updated.'], 201);
    }
}
