<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    use BelongsToTenant;

    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['supplier_id', 'reference', 'note', 'received_at'];

    protected function casts(): array
    {
        return [
            'total'        => 'decimal:2',
            'amount_paid'  => 'decimal:2',
            'received_at'  => 'datetime',
            'due_date'     => 'date:Y-m-d',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function balanceDue(): float
    {
        if (! $this->isReceived()) {
            return 0.0;
        }

        return round(max(0, (float) $this->total - (float) $this->amount_paid), 2);
    }

    public function paymentStatus(): string
    {
        if (! $this->isReceived()) {
            return 'cancelled';
        }

        if ($this->balanceDue() <= 0) {
            return 'paid';
        }

        return (float) $this->amount_paid > 0 ? 'partial' : 'unpaid';
    }

    public function isReceived(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }
}
