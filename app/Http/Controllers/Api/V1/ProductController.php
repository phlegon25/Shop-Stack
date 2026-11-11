<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductImageRequest;
use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\StockService;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    private const DISK = 'public';

    public function __construct(
        private AuditLogger $audit,
        private StockService $stock,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->with('category:id,name')
            ->withSum(['movements as total_stocked_quantity' => fn ($q) => $q->where('quantity_change', '>', 0)], 'quantity_change');

        // Whitelisted filtering only.
        if ($request->filled('search')) {
            $term = ListQuery::like((string) $request->query('search'));
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('sku', 'like', $term)
                  ->orWhere('barcode', 'like', $term);
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->query('category_id'));
        }

        if ($request->filled('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        ListQuery::applySort($query, $request, 'products', 'name', 'asc');

        $paginator = $query->paginate(ListQuery::perPage($request));
        $paginator->through(fn (Product $p) => $p->toArray() + ['image_url' => $p->imageUrl()]);

        return response()->json($paginator);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        app(\App\Support\PlanLimits::class)->assertProductSlot();

        $data = $request->validated();
        $openingQuantity = (int) ($data['opening_quantity'] ?? 0);
        unset($data['opening_quantity']);

        // Tenant-configured default threshold applies when none was given.
        if (! array_key_exists('low_stock_threshold', $data)) {
            $data['low_stock_threshold'] = (int) (\App\Models\BusinessSetting::query()->first()?->low_stock_default ?? 5);
        }

        $product = DB::transaction(function () use ($data, $openingQuantity) {
            $product = Product::create($data);

            if ($openingQuantity > 0) {
                $this->stock->apply($product, $openingQuantity, 'adjustment', null, 'Opening stock');
            }

            return $product->refresh();
        });

        $this->audit->log('product.created', ['subject' => $product]);

        return response()->json(['data' => $product->toArray() + [
            'total_stocked_quantity' => $openingQuantity,
        ]], 201);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load('category:id,name');

        return response()->json(['data' => $product->toArray() + ['image_url' => $product->imageUrl()]]);
    }

    public function update(ProductRequest $request, Product $product): JsonResponse
    {
        // Only validated fields can change; stock_quantity and tenant_id are
        // NOT updatable through this endpoint.
        $product->update($request->validated());

        $this->audit->log('product.updated', [
            'subject' => $product,
            'context' => ['fields' => array_keys($request->validated())],
        ]);

        return response()->json(['data' => $product->fresh()->load('category:id,name')]);
    }

    public function destroy(Product $product): JsonResponse
    {
        // Hard delete is blocked once the product has financial history —
        // that protects the integrity of historical sales/purchases.
        $hasHistory = $product->movements()->exists();

        if ($hasHistory) {
            $product->forceFill(['is_active' => false])->save();

            return response()->json([
                'message' => 'Product has transaction history and was deactivated instead of deleted.',
                'data'    => $product,
            ], 422);
        }

        if ($product->image_path) {
            Storage::disk(self::DISK)->delete($product->image_path);
        }

        $this->audit->log('product.deleted', ['subject' => $product, 'context' => ['sku' => $product->sku]]);
        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    /**
     * Upload a product image. The file is validated by real MIME type, the
     * original filename is discarded, and a random safe name is generated.
     */
    public function uploadImage(ProductImageRequest $request, Product $product): JsonResponse
    {
        $file = $request->file('image');

        $extension = match ($file->getMimeType()) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'jpg',
        };

        $path = $file->storeAs(
            'products/'.$product->tenant_id,
            Str::random(40).'.'.$extension,
            self::DISK,
        );

        if ($product->image_path) {
            Storage::disk(self::DISK)->delete($product->image_path);
        }

        $product->forceFill(['image_path' => $path])->save();

        return response()->json(['message' => 'Image updated.', 'image_url' => $product->imageUrl()]);
    }

    /** Stream the product image (tenant-scoped; random unguessable path). */
    public function image(Product $product)
    {
        abort_unless($product->image_path && Storage::disk(self::DISK)->exists($product->image_path), 404);

        $path = Storage::disk(self::DISK)->path($product->image_path);

        return response()->file($path, [
            'Content-Type'  => mime_content_type($path) ?: 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** Products expiring within the tenant's configured warning window. */
    public function expiring(Request $request): JsonResponse
    {
        $days = (int) (\App\Models\BusinessSetting::query()->first()?->expiry_warn_days ?? 14);

        $products = Product::where('is_active', true)
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(max(1, $days))->toDateString()])
            ->orderBy('expiry_date')
            ->limit(100)
            ->get()
            ->map(function (Product $p) {
                return $p->toArray() + [
                    'days_to_expiry' => (int) now()->startOfDay()->diffInDays($p->expiry_date),
                    'image_url'      => $p->imageUrl(),
                ];
            });

        return response()->json(['data' => $products, 'window_days' => $days]);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $items = Product::where('is_active', true)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->orderBy('stock_quantity')
            ->limit(50)
            ->get(['id', 'sku', 'name', 'stock_quantity', 'low_stock_threshold']);

        return response()->json(['data' => $items]);
    }
}
