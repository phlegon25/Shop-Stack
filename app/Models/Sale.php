<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use BelongsToTenant;

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_VOIDED = 'voided';

    public const PAYMENT_METHODS = ['cash', 'momo', 'bank', 'card', 'credit'];

    // Financial document: only non-financial metadata is fillable at creation.
    // Totals are computed server-side; there is deliberately no update endpoint.
    protected $fillable = [
        'receipt_no', 'customer_name', 'payment_method',
        'amount_paid', 'change_given',
    ];

    protected function casts(): array
    {
        return [
            'subtotal'      => 'decimal:2',
            'discount'      => 'decimal:2',
            'tax'           => 'decimal:2',
            'total'         => 'decimal:2',
            'amount_paid'   => 'decimal:2',
            'change_given'  => 'decimal:2',
            'voided_at'     => 'datetime',
            'due_date'      => 'date:Y-m-d',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function invoice(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    /** Everything collected so far: initial amount + subsequent payments. */
    public function amountPaidTotal(): float
    {
        $payments = $this->relationLoaded('payments') ? $this->payments : $this->payments()->get();

        return round((float) $this->amount_paid + (float) $payments->sum('amount'), 2);
    }

    public function balanceDue(): float
    {
        // Money handed back as change is never "paid towards the bill".
        $returned = (float) $this->returns()->sum('total');

        return round(max(0, (float) $this->total - $returned - $this->amountPaidTotal()), 2);
    }

    /** paid | partial | unpaid (voided sales are handled by status). */
    public function paymentStatus(): string
    {
        $paid = $this->amountPaidTotal();
        if ($this->balanceDue() <= 0) {
            return 'paid';
        }

        return $paid > 0 ? 'partial' : 'unpaid';
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
