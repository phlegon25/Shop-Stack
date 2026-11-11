<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant's payment submission for a subscription.
 *
 * Tenant-scoped: tenants only ever see their own submissions (status is
 * ALWAYS forced to 'pending' server-side). Super-admin queries run without
 * a tenant context, so the scope is inert on the platform plane.
 */
class SubscriptionPayment extends Model
{
    use BelongsToTenant;
    public const METHOD_MOMO = 'momo';
    public const METHOD_BANK = 'bank';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'plan_id', 'amount', 'method', 'sender_name', 'sender_number',
        'reference', 'proof_path', 'status', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'decimal:2',
            'submitted_at' => 'datetime',
            'reviewed_at'  => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
