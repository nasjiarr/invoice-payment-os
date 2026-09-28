<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProductController extends Controller
{
    /**
     * Display a listing of the products for the user's business/workspace.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Product::class);

        $query = Product::accessibleBy($request->user())->with('business');

        // Filter by business_id if provided
        if ($businessId = $request->integer('business_id')) {
            $hasAccess = $request->user()->ownedBusinesses()->where('id', $businessId)->exists()
                || $request->user()->businesses()->where('businesses.id', $businessId)->exists();

            if (! $hasAccess) {
                abort(403, 'This action is unauthorized.');
            }

            $query->where('business_id', $businessId);
        }

        // Filter by active status if provided
        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        // Search by name or description
        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Safe sorting with allowed fields
        $allowedSorts = ['id', 'name', 'price', 'active', 'created_at', 'updated_at'];
        $sort = in_array($request->query('sort'), $allowedSorts, true) ? $request->query('sort') : 'created_at';
        $direction = strtolower($request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $direction);

        // Pagination
        $perPage = min(max($request->integer('per_page', 15), 1), 100);
        $products = $query->paginate($perPage);

        return ProductResource::collection($products)->additional([
            'message' => 'Products retrieved successfully',
        ]);
    }

    /**
     * Store a newly created product.
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        Gate::authorize('create', [Product::class, $request->integer('business_id')]);

        $product = Product::create($request->validated());

        return (new ProductResource($product->load('business')))
            ->additional(['message' => 'Product created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified product.
     */
    public function show(Request $request, Product $product): ProductResource
    {
        Gate::authorize('view', $product);

        return (new ProductResource($product->load('business')))
            ->additional(['message' => 'Product retrieved successfully']);
    }

    /**
     * Update the specified product.
     */
    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        Gate::authorize('update', $product);

        $product->update($request->safe()->except(['business_id']));

        return (new ProductResource($product->fresh()->load('business')))
            ->additional(['message' => 'Product updated successfully']);
    }

    /**
     * Remove the specified product.
     */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        Gate::authorize('delete', $product);

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully',
        ]);
    }
}
