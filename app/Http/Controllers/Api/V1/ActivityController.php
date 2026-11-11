<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Support\ListQuery;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The tenant's own immutable activity log. Read-only by design — there is
 * no update/delete surface anywhere in the codebase.
 */
class ActivityController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $query = Audit::query()->where('tenant_id', $this->context->tenantId());

        if ($request->filled('action')) {
            // Pattern allowlist prefix *. e.g. "sale."
            $a = mb_substr((string) $request->query('action'), 0, 60);
            $query->where('action', 'like', str_replace(['%', '_'], '', $a) . '%');
        }
        if ($request->filled('user')) {
            $q = mb_substr((string) $request->query('user'), 0, 120);
            $query->where(fn ($sub) => $sub->where('actor_email', 'like', "%{$q}%")->orWhere('actor_label', 'like', "%{$q}%"));
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        ListQuery::applySort($query, $request, 'audits', 'created_at', 'desc');

        return response()->json($query->paginate(ListQuery::perPage($request)));
    }
}
