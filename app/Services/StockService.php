<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY way product stock changes. Every movement:
 *  - locks the product row (SELECT ... FOR UPDATE)
 *  - updates the balance
 *  - appends an immutable ledger entry
 * and is always executed inside the caller's DB transaction.
 */
class StockService
{
    public function __construct(private TenantContext $tenantContext) {}

    public function apply(Product $product, int $delta, string $type, ?Model $reference = null, ?string $note = null): StockMovement
    {
        if (! in_array($type, StockMovement::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown stock movement type [{$type}].");
        }

        return DB::transaction(function () use ($product, $delta, $type, $reference, $note) {
            /** @var Product $locked */
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            $newQuantity = $locked->stock_quantity + $delta;

            if ($newQuantity < 0) {
                throw new InsufficientStockException(
                    "Insufficient stock for [{$locked->name}]: requested {$delta}, on hand {$locked->stock_quantity}."
                );
            }

            $locked->forceFill(['stock_quantity' => $newQuantity])->save();

            return StockMovement::create([
                'product_id'               => $locked->id,
                'type'                     => $type,
                'quantity_change'          => $delta,
                'quantity_after'           => $newQuantity,
                'reference_type'           => $reference?->getMorphClass(),
                'reference_id'             => $reference?->getKey(),
                'note'                     => $note,
                'user_id'                  => $this->tenantContext->user()?->id,
                'impersonation_session_id' => $this->tenantContext->impersonation()['session_id'] ?? null,
            ]);
        });
    }
}
