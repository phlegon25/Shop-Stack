<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Full plan catalogue management. Tenancy-app behaviour (limits, pricing,
 * trial length) all reads from these rows — plans are NEVER hardcoded.
 */
class PlanController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Plan::orderBy('sort_order')
                ->withCount(['subscriptions as active_subscriptions' => fn ($q) => $q->where('status', 'active')->where('ends_at', '>', now())])
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $plan = Plan::create($this->payload($request));

        $this->audit->log('plan.created', ['subject' => $plan, 'context' => ['name' => $plan->name, 'price' => (float) $plan->price]]);

        return response()->json(['data' => $plan], 201);
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $old = $plan->only(['name', 'price', 'duration_days', 'is_active']);
        $plan->update($this->payload($request, $plan));

        $this->audit->log('plan.updated', [
            'subject' => $plan,
            'context' => ['before' => $old, 'fields' => array_keys($request->all())],
        ]);

        return response()->json(['data' => $plan->fresh(), 'message' => 'Plan updated.']);
    }

    public function destroy(Plan $plan): JsonResponse
    {
        if ($plan->subscriptions()->exists()) {
            $plan->update(['is_active' => false]);

            $this->audit->log('plan.retired', ['subject' => $plan]);

            return response()->json([
                'message' => 'Plan has subscription history and was retired (hidden) instead of deleted.',
                'data'    => $plan,
            ], 422);
        }

        $this->audit->log('plan.deleted', ['subject' => $plan, 'context' => ['name' => $plan->name]]);
        $plan->delete();

        return response()->json(['message' => 'Plan deleted.']);
    }

    private function payload(Request $request, ?Plan $plan = null): array
    {
        return $request->validate([
            'name'          => ['required', 'string', 'max:80'],
            'slug'          => ['required', 'string', 'max:80', 'regex:/^[a-z0-9\-]+$/',
                Rule::unique('plans', 'slug')->ignore($plan?->id)],
            'description'   => ['nullable', 'string', 'max:300'],
            'price'         => ['required', 'numeric', 'min:0', 'max:1000000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3660'],
            'trial_days'    => ['nullable', 'integer', 'min:0', 'max:365'],
            'product_limit' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'user_limit'    => ['nullable', 'integer', 'min:1', 'max:100000'],
            'storage_mb'    => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'features'      => ['nullable', 'array', 'max:30'],
            'features.*'    => ['string', 'max:120'],
            'is_active'     => ['sometimes', 'boolean'],
            'sort_order'    => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
    }
}
