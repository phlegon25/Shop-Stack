<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentSubmitRequest;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPayment;
use App\Services\AuditLogger;
use App\Support\ListQuery;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Tenant-facing billing: view plans/subscription, submit payment evidence,
 * and read the platform's public payment details. Tenants can NEVER change
 * subscription status, amounts, verification or expiry here.
 */
class BillingController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private AuditLogger $audit,
    ) {}

    public function status(): JsonResponse
    {
        $tenant = $this->context->tenant()->load(['currentSubscription.plan']);

        return response()->json([
            'tenant_status' => $tenant->status,
            'suspension'    => $tenant->isSuspended() ? [
                'reason' => $tenant->suspension_reason,
                'at'     => $tenant->suspended_at?->toISOString(),
            ] : null,
            'subscription'  => $tenant->currentSubscription ? [
                'plan'      => $tenant->currentSubscription->plan?->only(['id', 'name', 'price', 'duration_days']),
                'status'    => $tenant->currentSubscription->status,
                'ends_at'   => $tenant->currentSubscription->ends_at->toISOString(),
                'days_left' => max(0, now()->diffInDays($tenant->currentSubscription->ends_at, false)),
            ] : null,
        ]);
    }

    public function plans(): JsonResponse
    {
        return response()->json([
            'data'     => Plan::where('is_active', true)->orderBy('sort_order')->orderBy('price')->get(),
            'currency' => config('pos.currency'),
        ]);
    }

    public function payments(Request $request): JsonResponse
    {
        $query = SubscriptionPayment::query()->with('plan:id,name,price,duration_days');

        if ($request->filled('status') && in_array($request->query('status'), ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $request->query('status'));
        }

        $query->latest('submitted_at');

        return response()->json(
            $query->paginate(ListQuery::perPage($request))
        );
    }

    /** Public (read-only) platform payment details tenants pay against. */
    public function platformPaymentInfo(): JsonResponse
    {
        return response()->json(PlatformSetting::instance()->publicPaymentInfo());
    }

    /**
     * Submit payment evidence for review. Amount and plan are resolved
     * server-side; status is ALWAYS 'pending'; review fields are untouched.
     */
    public function submitPayment(PaymentSubmitRequest $request): JsonResponse
    {
        $data   = $request->validatedPayload();
        $tenant = $this->context->tenant();

        $plan = Plan::whereKey($data['plan_id'])->where('is_active', true)->firstOrFail();

        // One pending submission at a time — prevents review-queue flooding.
        $alreadyPending = SubscriptionPayment::where('status', SubscriptionPayment::STATUS_PENDING)->exists();
        if ($alreadyPending) {
            return response()->json([
                'message' => 'You already have a payment awaiting review.',
                'code'    => 'payment_pending',
            ], 422);
        }

        $proofPath = null;
        if ($request->hasFile('proof')) {
            $file      = $request->file('proof');
            $extension = match ($file->getMimeType()) {
                'image/png'  => 'png',
                'image/webp' => 'webp',
                default      => 'jpg',
            };
            // Private disk, random name — original filename is discarded.
            $proofPath = $file->storeAs(
                'payment-proofs/'.$tenant->id,
                Str::random(40).'.'.$extension,
                'local',
            );
        }

        $payment = null;

        DB::transaction(function () use ($tenant, $plan, $data, $proofPath, &$payment) {
            $payment = SubscriptionPayment::create([
                'plan_id'       => $plan->id,
                'amount'        => $plan->price,       // authoritative server-side amount
                'method'        => $data['method'],
                'sender_name'   => $data['sender_name'],
                'sender_number' => $data['sender_number'],
                'reference'     => $data['reference'],
                'proof_path'    => $proofPath,
                'status'        => SubscriptionPayment::STATUS_PENDING, // forced
                'submitted_at'  => now(),
            ]);
        });

        $this->audit->log('payment.submitted', [
            'subject' => $payment,
            'context' => [
                'plan_id'   => $plan->id,
                'method'    => $data['method'],
                'reference' => $data['reference'],
                'has_proof' => $proofPath !== null,
            ],
        ]);

        return response()->json([
            'message' => 'Payment submitted for review.',
            'data'    => $payment->fresh(['plan:id,name,price,duration_days'])->makeHidden(['proof_path']),
        ], 201);
    }
}
