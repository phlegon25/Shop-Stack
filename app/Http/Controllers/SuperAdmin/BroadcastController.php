<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Send in-app notifications from the platform to one tenant or everyone. */
class BroadcastController extends Controller
{
    public function __construct(
        private NotificationService $notify,
        private AuditLogger $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope'     => ['required', \Illuminate\Validation\Rule::in(['tenant', 'all'])],
            'tenant_id' => ['required_if:scope,tenant', 'integer', \Illuminate\Validation\Rule::exists('tenants', 'id')],
            'title'     => ['required', 'string', 'max:160'],
            'body'      => ['required', 'string', 'max:1000'],
            'type'      => ['sometimes', 'string', 'max:60', 'regex:/^[a-z_]+$/'],
        ]);

        $targets = $data['scope'] === 'all'
            ? Tenant::where('status', Tenant::STATUS_ACTIVE)->pluck('id')
            : collect([(int) $data['tenant_id']]);

        foreach ($targets as $tenantId) {
            $this->notify->toTenant($tenantId, $data['type'] ?? 'broadcast', $data['title'], $data['body']);
        }

        $this->audit->log('broadcast.sent', [
            'context' => ['scope' => $data['scope'], 'tenants' => $targets->count(), 'title' => $data['title']],
        ]);

        return response()->json(['message' => "Sent to {$targets->count()} tenant(s)."], 201);
    }
}
