<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->baseQuery()->latest('created_at')->limit(100);

        if ($request->query('unread') === '1') {
            $query->whereNull('read_at');
        }

        return response()->json([
            'data' => $query->paginate(min((int) $request->query('per_page', 20), 50)),
            'unread_count' => $this->baseQuery()->whereNull('read_at')->count(),
        ]);
    }

    public function unreadCount(): JsonResponse
    {
        return response()->json(['unread_count' => $this->baseQuery()->whereNull('read_at')->count()]);
    }

    public function markRead(Notification $notification): JsonResponse
    {
        $this->authorizeOwnership($notification);

        $notification->forceFill(['read_at' => $notification->read_at ?? now()])->save();

        return response()->json(['data' => $notification]);
    }

    public function markAllRead(): JsonResponse
    {
        $this->baseQuery()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    /** Tenant-wide broadcasts + messages addressed to this user specifically. */
    private function baseQuery()
    {
        return Notification::query()
            ->where('tenant_id', $this->context->tenantId())
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $this->context->user()->id));
    }

    private function authorizeOwnership(Notification $notification): void
    {
        if ((int) $notification->tenant_id !== $this->context->tenantId()
            || ($notification->user_id !== null && (int) $notification->user_id !== $this->context->user()->id)) {
            abort(404);
        }
    }
}
