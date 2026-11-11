<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Unit;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * One combined endpoint for the product form: categories, brands and units.
 * Brands/units are quick-create suggestion lists — products also accept
 * free-text brand/unit values, so nothing here is mandatory.
 */
class CatalogController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'categories' => Category::orderBy('name')->get(),
            'brands'     => Brand::orderBy('name')->get(),
            'units'      => Unit::orderBy('name')->get(),
        ]);
    }

    public function storeBrand(Request $request): JsonResponse
    {
        $tenantId = app(\App\Support\TenantContext::class)->tenantId();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60',
                Rule::unique('brands', 'name')->where('tenant_id', $tenantId)],
        ]);

        $brand = Brand::create($data);
        $this->audit->log('brand.created', ['subject' => $brand]);

        return response()->json(['data' => $brand], 201);
    }

    public function storeUnit(Request $request): JsonResponse
    {
        $tenantId = app(\App\Support\TenantContext::class)->tenantId();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:30',
                Rule::unique('units', 'name')->where('tenant_id', $tenantId)],
        ]);

        $unit = Unit::create($data);
        $this->audit->log('unit.created', ['subject' => $unit]);

        return response()->json(['data' => $unit], 201);
    }
}
