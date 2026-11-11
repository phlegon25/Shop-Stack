<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;

/** Platform-side notification bell (tenant_id IS NULL rows). */
class AdminNotificationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Notification::whereNull('tenant_id')
                ->latest('created_at')->limit(50)->get(),
            'unread_count' => Notification::whereNull('tenant_id')->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(Notification $notification): JsonResponse
    {
        abort_if($notification->tenant_id !== null, 404);

        $notification->forceFill(['read_at' => $notification->read_at ?? now()])->save();

        return response()->json(['data' => $notification]);
    }

    public function markAllRead(): JsonResponse
    {
        Notification::whereNull('tenant_id')->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'All marked as read.']);
    }
}
