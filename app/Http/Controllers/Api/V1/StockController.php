<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockAdjustRequest;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\AuditLogger;
use App\Services\StockService;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function __construct(
        private StockService $stock,
        private AuditLogger $audit,
    ) {}

    public function movements(Request $request): JsonResponse
    {
        $query = StockMovement::query()->with([]);

        if ($request->filled('product_id')) {
            $query->where('product_id', (int) $request->query('product_id'));
        }
        if ($request->filled('type') && in_array($request->query('type'), StockMovement::TYPES, true)) {
            $query->where('type', $request->query('type'));
        }

        ListQuery::applySort($query, $request, 'movements', 'created_at', 'desc');

        return response()->json($query->paginate(ListQuery::perPage($request)));
    }

    /**
     * Manual stock adjustment — always audited, always ledger-backed.
     * Negative adjustments cannot drive stock below zero.
     */
    public function adjust(StockAdjustRequest $request): JsonResponse
    {
        $data    = $request->validated();
        $product = Product::findOrFail($data['product_id']); // tenant-scoped

        $movement = $this->stock->apply(
            $product,
            (int) $data['quantity_change'],
            'adjustment',
            null,
            $data['note'],
        );

        $this->audit->log('stock.adjusted', [
            'subject' => $product,
            'context' => [
                'change' => (int) $data['quantity_change'],
                'after'  => $movement->quantity_after,
                'note'   => $data['note'],
            ],
        ]);

        return response()->json(['data' => $movement], 201);
    }
}
