<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CustomerController extends Controller
{
    /**
     * Display a listing of the customers for the user's business/workspace.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Customer::class);

        $query = Customer::accessibleBy($request->user())->with('business');

        // Filter by business_id if provided
        if ($businessId = $request->integer('business_id')) {
            $hasAccess = $request->user()->ownedBusinesses()->where('id', $businessId)->exists()
                || $request->user()->businesses()->where('businesses.id', $businessId)->exists();

            if (! $hasAccess) {
                abort(403, 'This action is unauthorized.');
            }

            $query->where('business_id', $businessId);
        }

        // Search by name or email
        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Safe sorting with allowed fields
        $allowedSorts = ['id', 'name', 'email', 'created_at', 'updated_at'];
        $sort = in_array($request->query('sort'), $allowedSorts, true) ? $request->query('sort') : 'created_at';
        $direction = strtolower($request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $direction);

        // Pagination
        $perPage = min(max($request->integer('per_page', 15), 1), 100);
        $customers = $query->paginate($perPage);

        return CustomerResource::collection($customers)->additional([
            'message' => 'Customers retrieved successfully',
        ]);
    }

    /**
     * Store a newly created customer.
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        Gate::authorize('create', [Customer::class, $request->integer('business_id')]);

        $customer = Customer::create($request->validated());

        return (new CustomerResource($customer->load('business')))
            ->additional(['message' => 'Customer created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified customer.
     */
    public function show(Request $request, Customer $customer): CustomerResource
    {
        Gate::authorize('view', $customer);

        return (new CustomerResource($customer->load('business')))
            ->additional(['message' => 'Customer retrieved successfully']);
    }

    /**
     * Update the specified customer.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        Gate::authorize('update', $customer);

        $customer->update($request->safe()->except(['business_id']));

        return (new CustomerResource($customer->fresh()->load('business')))
            ->additional(['message' => 'Customer updated successfully']);
    }

    /**
     * Remove the specified customer.
     */
    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        Gate::authorize('delete', $customer);

        $customer->delete();

        return response()->json([
            'message' => 'Customer deleted successfully',
        ]);
    }
}
