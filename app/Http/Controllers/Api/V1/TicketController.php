<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private AuditLogger $audit,
        private NotificationService $notify,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = SupportTicket::query()->withCount('replies')->latest('updated_at');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json($query->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'body'    => ['required', 'string', 'max:5000'],
        ]);

        $user = $this->context->user();

        $ticket = SupportTicket::create([
            'user_id'  => $user->id,
            'subject'  => $data['subject'],
            'status'   => 'open',
            'priority' => 'normal',
        ]);

        $ticket->replies()->create([
            'author_type' => 'user',
            'author_id'   => $user->id,
            'author_name' => $user->name,
            'body'        => $data['body'],
        ]);

        $this->notify->toPlatform('support_ticket',
            "New support ticket: {$data['subject']}",
            "{$this->context->tenant()->name} opened a ticket: {$data['subject']}",
            ['ticket_id' => $ticket->id]);

        $this->audit->log('support.ticket_created', ['subject' => $ticket]);

        return response()->json(['data' => $ticket], 201);
    }

    public function show(SupportTicket $ticket): JsonResponse
    {
        // Internal admin notes are never exposed to tenants.
        $ticket->load(['replies' => fn ($q) => $q->where('is_internal', false)->orderBy('created_at')]);

        return response()->json(['data' => $ticket]);
    }

    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        if (in_array($ticket->status, ['closed'], true)) {
            return response()->json(['message' => 'This ticket is closed. Open a new one instead.'], 422);
        }

        $user = $this->context->user();

        $reply = $ticket->replies()->create([
            'author_type' => 'user',
            'author_id'   => $user->id,
            'author_name' => $user->name,
            'body'        => $data['body'],
        ]);

        if ($ticket->status !== 'open') {
            $ticket->update(['status' => 'open']);
        }

        $this->audit->log('support.ticket_replied', ['subject' => $ticket]);

        return response()->json(['data' => $reply], 201);
    }
}
