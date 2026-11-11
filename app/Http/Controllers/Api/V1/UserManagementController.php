<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TenantUserStoreRequest;
use App\Http\Requests\TenantUserUpdateRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Owner-only management of staff accounts within their own tenant.
 *
 * Escalation guards:
 *  - the 'owner' role can never be granted or modified through the API
 *  - an owner cannot disable or demote themselves
 *  - all changes are audited, and role changes revoke the target's sessions
 */
class UserManagementController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        // User has no global tenant scope (auth model) — scope explicitly.
        return response()->json([
            'data' => User::where('tenant_id', $this->context->tenantId())
                ->orderBy('name')->get()->map->publicPayload(),
        ]);
    }

    public function store(TenantUserStoreRequest $request): JsonResponse
    {
        app(\App\Support\PlanLimits::class)->assertUserSlot();

        $data = $request->validated();

        $user = new User([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => $data['password'],
        ]);
        $user->tenant_id = $this->context->tenantId(); // server-derived, never client input
        $user->role   = $data['role'];
        $user->status = User::STATUS_ACTIVE;
        $user->password_changed_at = now();
        $user->save();

        $this->audit->log('user.created', [
            'subject' => $user,
            'context' => ['role' => $user->role, 'email' => $user->email],
        ]);

        return response()->json(['data' => $user->publicPayload()], 201);
    }

    public function update(TenantUserUpdateRequest $request, User $user): JsonResponse
    {
        $actor = $this->context->user();

        // Route-model binding does not scope User — verify tenancy explicitly.
        abort_if((int) $user->tenant_id !== $this->context->tenantId(), 404);

        if ($user->id === $actor->id) {
            return response()->json(['message' => 'You cannot change your own role or status.', 'code' => 'self_modification'], 422);
        }

        if ($user->role === User::ROLE_OWNER) {
            return response()->json(['message' => 'Owner accounts cannot be modified.', 'code' => 'owner_protected'], 403);
        }

        $data = $request->validated();
        $changes = [];

        if (isset($data['name']) && $data['name'] !== $user->name) {
            $user->name = $data['name'];
            $changes[] = 'name';
        }

        if (isset($data['role']) && $data['role'] !== $user->role) {
            $user->role = $data['role'];
            $changes[] = 'role:'.$data['role'];
            // Permissions changed — existing sessions must re-authenticate.
            $user->tokens()->delete();
        }

        if (isset($data['status']) && $data['status'] !== $user->status) {
            $user->status = $data['status'];
            $changes[] = 'status:'.$data['status'];
            if ($data['status'] === User::STATUS_DISABLED) {
                $user->tokens()->delete();
            }
        }

        $user->save();

        if ($changes !== []) {
            $this->audit->log('user.updated', [
                'subject' => $user,
                'context' => ['changes' => $changes],
            ]);
        }

        return response()->json(['data' => $user->publicPayload()]);
    }
}
