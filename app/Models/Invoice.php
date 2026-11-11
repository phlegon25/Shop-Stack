<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use BelongsToTenant;

    protected $fillable = ['invoice_no', 'sale_id', 'customer_id', 'issue_date', 'due_date', 'notes'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'due_date'   => 'date:Y-m-d',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Derived from the linked sale (single source of truth for money):
     * cancelled | paid | partial | unpaid | overdue.
     */
    public function status(): string
    {
        $sale = $this->sale;

        if ($sale->status === Sale::STATUS_VOIDED) {
            return 'cancelled';
        }

        $balance = $sale->balanceDue();

        if ($balance <= 0) {
            return 'paid';
        }

        if ($this->due_date && $this->due_date->isPast()) {
            return 'overdue';
        }

        return $sale->amountPaidTotal() > 0 ? 'partial' : 'unpaid';
    }
}
