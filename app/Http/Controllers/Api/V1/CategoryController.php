<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Category::withCount('products')->orderBy('name')->get(),
        ]);
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $category = Category::create($request->validated());

        return response()->json(['data' => $category], 201);
    }

    public function update(CategoryRequest $request, Category $category): JsonResponse
    {
        // $category is resolved through the TenantScope — a foreign tenant's
        // id simply 404s (IDOR-proof).
        $category->update($request->validated());

        return response()->json(['data' => $category]);
    }

    public function destroy(Category $category): JsonResponse
    {
        // Products keep working with category set to null (FK nullOnDelete).
        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }
}
