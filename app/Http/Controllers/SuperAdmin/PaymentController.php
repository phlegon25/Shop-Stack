<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentReviewRequest;
use App\Models\SubscriptionPayment;
use App\Services\AuditLogger;
use App\Services\SubscriptionService;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Super-admin payment review queue. ONLY this plane can transition a
 * payment's status, and every decision is audited.
 */
class PaymentController extends Controller
{
    public function __construct(
        private SubscriptionService $service,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = SubscriptionPayment::query()->with(['plan:id,name,price', 'tenant:id,name,slug,status']);

        if ($request->filled('status') && in_array($request->query('status'), ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', (int) $request->query('tenant_id'));
        }

        ListQuery::applySort($query, $request, 'payments', 'submitted_at', 'asc');

        return response()->json($query->paginate(ListQuery::perPage($request)));
    }

    public function show(SubscriptionPayment $payment): JsonResponse
    {
        $payment->load(['plan', 'tenant:id,name,slug,status', 'reviewer:id,name,email']);

        return response()->json(['data' => $payment]);
    }

    public function review(PaymentReviewRequest $request, SubscriptionPayment $payment): JsonResponse
    {
        $action = $request->validated('action');

        if ($action === 'approve') {
            $subscription = $this->service->approvePayment($payment, $request->user());

            return response()->json([
                'message'      => 'Payment approved. Subscription activated.',
                'subscription' => $subscription->load('plan'),
            ]);
        }

        $this->service->rejectPayment($payment, $request->user(), $request->validated('reason'));

        return response()->json(['message' => 'Payment rejected.']);
    }

    /** Stream a tenant's payment proof — super-admin only, access is logged. */
    public function proof(SubscriptionPayment $payment)
    {
        abort_unless($payment->proof_path && Storage::disk('local')->exists($payment->proof_path), 404);

        $this->audit->log('payment.proof_viewed', [
            'subject'   => $payment,
            'tenant_id' => $payment->tenant_id,
        ]);

        $path = Storage::disk('local')->path($payment->proof_path);

        return response()->file($path, [
            'Content-Type'           => mime_content_type($path) ?: 'application/octet-stream',
            'Content-Disposition'    => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
