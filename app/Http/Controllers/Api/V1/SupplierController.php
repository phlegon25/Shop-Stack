<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierRequest;
use App\Models\Supplier;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Supplier::query()->orderBy('name');

        if ($request->filled('search')) {
            $query->where('name', 'like', ListQuery::like((string) $request->query('search')));
        }

        return response()->json($query->paginate(ListQuery::perPage($request)));
    }

    public function store(SupplierRequest $request): JsonResponse
    {
        return response()->json(['data' => Supplier::create($request->validated())], 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        $purchases = $supplier->hasMany(\App\Models\Purchase::class)
            ->with('payments')
            ->latest('created_at')
            ->limit(50)
            ->get();

        $owed = $purchases
            ->filter(fn ($p) => $p->balanceDue() > 0.0001)
            ->values();

        return response()->json([
            'data' => $supplier,
            'stats' => [
                'total_purchases' => $supplier->hasMany(\App\Models\Purchase::class)->where('status', 'received')->count(),
                'outstanding'     => round($owed->sum(fn ($p) => $p->balanceDue()), 2),
            ],
            'recent_purchases' => $purchases->take(10)->values(),
            'open_purchases'   => $owed->map(fn ($p) => [
                'id' => $p->id, 'reference' => $p->reference, 'total' => (float) $p->total,
                'paid' => (float) $p->amount_paid, 'balance' => $p->balanceDue(), 'due_date' => $p->due_date,
            ])->values(),
        ]);
    }

    public function update(SupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $supplier->update($request->validated());

        return response()->json(['data' => $supplier]);
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $used = $supplier->hasMany(\App\Models\Purchase::class)->exists();

        if ($used) {
            return response()->json(['message' => 'Supplier has purchase history and cannot be deleted.'], 422);
        }

        $supplier->delete();

        return response()->json(['message' => 'Supplier deleted.']);
    }
}
