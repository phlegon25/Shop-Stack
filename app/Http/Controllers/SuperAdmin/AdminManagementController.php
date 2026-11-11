<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdminStoreRequest;
use App\Models\SuperAdmin;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminManagementController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => SuperAdmin::orderBy('name')->get()->map->publicPayload(),
        ]);
    }

    public function store(SuperAdminStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $admin = SuperAdmin::create([
            'name'        => $data['name'],
            'email'       => $data['email'],
            'password'    => $data['password'],
            'permissions' => array_values(array_unique($data['permissions'])),
        ]);

        $this->audit->log('sa.admin_created', [
            'subject' => $admin,
            'context' => ['email' => $admin->email, 'permissions' => $admin->permissions],
        ]);

        return response()->json([
            'data'    => $admin->publicPayload(),
            'message' => 'Administrator created. They must enroll in two-factor authentication on first sign-in.',
        ], 201);
    }

    /** Deactivation revokes all sessions immediately. */
    public function deactivate(Request $request, SuperAdmin $admin): JsonResponse
    {
        if ($admin->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot deactivate your own account.', 'code' => 'self_modification'], 422);
        }

        $admin->forceFill(['is_active' => false])->save();
        $admin->tokens()->delete();

        $this->audit->log('sa.admin_deactivated', ['subject' => $admin, 'context' => ['email' => $admin->email]]);

        return response()->json(['message' => 'Administrator deactivated and sessions revoked.']);
    }
}
