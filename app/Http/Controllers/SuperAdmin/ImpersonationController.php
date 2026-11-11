<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImpersonateRequest;
use App\Models\ImpersonationSession;
use App\Models\Tenant;
use App\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public function __construct(private ImpersonationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->service->sweepExpired();

        $query = ImpersonationSession::query()->with(['superAdmin:id,name,email', 'tenant:id,name,slug']);

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', (int) $request->query('tenant_id'));
        }
        if ($request->filled('active') && $request->boolean('active')) {
            $query->whereNull('ended_at')->where('expires_at', '>', now());
        }

        return response()->json($query->latest('started_at')->paginate(20));
    }

    /**
     * Begin a time-boxed impersonation session. Requires the impersonate
     * permission and a written reason. The returned token acts as the
     * temporary session — tenant credentials are never read or copied.
     */
    public function start(ImpersonateRequest $request, Tenant $tenant): JsonResponse
    {
        [$session, $plainToken] = $this->service->start(
            $request->user(),
            $tenant,
            $request->validated('reason'),
        );

        return response()->json([
            'message'    => 'Impersonation session started. It is time-limited and fully logged.',
            'session'    => [
                'id'         => $session->id,
                'expires_at' => $session->expires_at->toISOString(),
                'tenant'     => ['id' => $tenant->id, 'name' => $tenant->name],
            ],
            'token'      => $plainToken,
            'token_type' => 'Bearer',
            'banner'     => [
                'show'   => true,
                'text'   => "You are viewing {$tenant->name} as platform support. All actions are logged.",
                'reason' => $session->reason,
            ],
        ], 201);
    }

    public function stop(ImpersonationSession $session): JsonResponse
    {
        $this->service->end($session, 'manual');

        return response()->json(['message' => 'Impersonation session ended.']);
    }

    /**
     * Tenant-plane endpoint: lets the impersonated session itself sign out
     * early ("Exit impersonation" button on the banner).
     */
    public function stopCurrent(Request $request, \App\Support\TenantContext $context): JsonResponse
    {
        $impersonation = $context->impersonation();

        if ($impersonation === null) {
            return response()->json(['message' => 'No active impersonation session.', 'code' => 'not_impersonated'], 422);
        }

        $session = ImpersonationSession::whereKey($impersonation['session_id'])->first();

        if ($session !== null) {
            $this->service->end($session, 'manual');
        }

        return response()->json(['message' => 'Impersonation session ended.']);
    }
}
