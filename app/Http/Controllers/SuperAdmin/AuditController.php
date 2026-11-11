<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Audit::query();

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', (int) $request->query('tenant_id'));
        }
        if ($request->filled('actor_type') && in_array($request->query('actor_type'), ['user', 'super_admin', 'system'], true)) {
            $query->where('actor_type', $request->query('actor_type'));
        }
        if ($request->filled('action')) {
            $query->where('action', 'like', ListQuery::like((string) $request->query('action')));
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to')?->endOfDay());
        }

        ListQuery::applySort($query, $request, 'audits', 'created_at', 'desc');

        return response()->json($query->paginate(ListQuery::perPage($request, 25, 200)));
    }
}
