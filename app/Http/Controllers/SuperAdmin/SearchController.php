<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** One search box across the platform: tenants, owners, payment references. */
class SearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['tenants' => [], 'payments' => [], 'users' => []]);
        }

        $like = "%{$q}%";

        $tenants = Tenant::query()
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('slug', 'like', $like)->orWhere('phone', 'like', $like))
            ->limit(8)->get(['id', 'name', 'slug', 'email', 'status'])
            ->map(fn (Tenant $t) => $t->toArray() + ['lifecycle' => \App\Support\Lifecycle::badge($t)]);

        $payments = SubscriptionPayment::query()
            ->with('tenant:id,name')
            ->where(fn ($w) => $w->where('reference', 'like', $like))
            ->limit(8)->get(['id', 'tenant_id', 'amount', 'method', 'reference', 'status', 'submitted_at']);

        $users = \App\Models\User::query()
            ->with('tenant:id,name')
            ->where(fn ($w) => $w->where('email', 'like', $like)->orWhere('name', 'like', $like))
            ->limit(8)->get(['id', 'tenant_id', 'name', 'email', 'role', 'status']);

        return response()->json([
            'tenants'  => $tenants,
            'payments' => $payments,
            'users'    => $users,
        ]);
    }
}
