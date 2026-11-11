<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use App\Models\SupportTicket;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Platform helpdesk across ALL tenants (internal notes never leak out). */
class SupportController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
        private NotificationService $notify,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = SupportTicket::withoutTenancy()
            ->with(['tenant:id,name,slug', 'assignee:id,name'])
            ->withCount(['replies', 'replies as unread_replies' => fn ($q) => $q->where('author_type', 'user')])
            ->latest('updated_at');

        if ($request->filled('status')) {
            $query->where('status', mb_substr($request->query('status'), 0, 20));
        }
        if ($request->filled('tenant')) {
            $t = mb_substr($request->query('tenant'), 0, 120);
            $query->whereHas('tenant', fn ($q) => $q->where('name', 'like', "%{$t}%"));
        }

        return response()->json($query->paginate(20));
    }

    public function show(SupportTicket $ticket): JsonResponse
    {
        $ticket->load(['tenant:id,name,slug', 'assignee:id,name', 'replies' => fn ($q) => $q->orderBy('created_at')]);

        return response()->json(['data' => $ticket]);
    }

    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate([
            'body'        => ['required', 'string', 'max:5000'],
            'is_internal' => ['sometimes', 'boolean'],
        ]);

        $admin = $request->user();

        $reply = $ticket->replies()->create([
            'author_type'  => 'super_admin',
            'author_id'    => $admin->id,
            'author_name'  => $admin->name,
            'body'         => $data['body'],
            'is_internal'  => (bool) ($data['is_internal'] ?? false),
        ]);

        if (! ($data['is_internal'] ?? false)) {
            if ($ticket->status === 'open') {
                $ticket->update(['status' => 'in_progress']);
            }
            if ($ticket->tenant_id) {
                $this->notify->toTenant($ticket->tenant_id, 'support_reply',
                    'Support replied: ' . $ticket->subject,
                    mb_substr($data['body'], 0, 220),
                    ['ticket_id' => $ticket->id]);
            }
        }

        $this->audit->log('support.ticket_admin_replied', [
            'subject'  => $ticket,
            'tenant_id' => $ticket->tenant_id,
            'context'  => ['internal' => (bool) ($data['is_internal'] ?? false)],
        ]);

        return response()->json(['data' => $reply], 201);
    }

    public function assign(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate([
            'super_admin_id' => ['nullable', 'integer', Rule::exists('super_admins', 'id')->where('is_active', true)],
        ]);

        $ticket->update(['assigned_to' => $data['super_admin_id']]);

        $this->audit->log('support.ticket_assigned', [
            'subject' => $ticket,
            'context' => ['assigned_to' => $data['super_admin_id']],
        ]);

        return response()->json(['data' => $ticket->fresh()->load('assignee:id,name')]);
    }

    public function setStatus(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'in_progress', 'waiting', 'resolved', 'closed'])],
        ]);

        $ticket->update(['status' => $data['status']]);

        if ($ticket->tenant_id) {
            $this->notify->toTenant($ticket->tenant_id, 'support_status',
                "Ticket {$data['status']}: {$ticket->subject}",
                "The status of your support ticket was changed to {$data['status']}.",
                ['ticket_id' => $ticket->id]);
        }

        $this->audit->log('support.ticket_status_changed', [
            'subject' => $ticket,
            'context' => ['status' => $data['status']],
        ]);

        return response()->json(['data' => $ticket->fresh()]);
    }
}
