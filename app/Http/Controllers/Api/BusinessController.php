<?php

namespace App\Http\Controllers\Api;

use App\Enums\BusinessRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\StoreBusinessRequest;
use App\Http\Requests\Business\UpdateBusinessRequest;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BusinessController extends Controller
{
    /**
     * Display a listing of the authenticated user's businesses.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Business::class);

        $businesses = Business::where('owner_id', $request->user()->id)
            ->orWhereHas('users', fn ($query) => $query->where('users.id', $request->user()->id))
            ->distinct()
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Businesses retrieved successfully',
            'data' => BusinessResource::collection($businesses),
        ]);
    }

    /**
     * Store a newly created business.
     */
    public function store(StoreBusinessRequest $request): JsonResponse
    {
        $business = DB::transaction(function () use ($request): Business {
            $business = Business::create([
                ...$request->safe()->except(['owner_id']),
                'owner_id' => $request->user()->id,
            ]);

            $business->users()->attach($request->user()->id, ['role' => BusinessRole::Owner->value]);

            return $business;
        });

        return response()->json([
            'message' => 'Business created successfully',
            'data' => new BusinessResource($business),
        ], 201);
    }

    /**
     * Display the specified business.
     */
    public function show(Request $request, Business $business): JsonResponse
    {
        Gate::authorize('view', $business);

        return response()->json([
            'message' => 'Business retrieved successfully',
            'data' => new BusinessResource($business),
        ]);
    }

    /**
     * Update the specified business.
     */
    public function update(UpdateBusinessRequest $request, Business $business): JsonResponse
    {
        Gate::authorize('update', $business);

        $business->update($request->safe()->except(['owner_id']));

        return response()->json([
            'message' => 'Business updated successfully',
            'data' => new BusinessResource($business->fresh()),
        ]);
    }

    /**
     * Remove the specified business.
     */
    public function destroy(Request $request, Business $business): JsonResponse
    {
        Gate::authorize('delete', $business);

        $business->delete();

        return response()->json([
            'message' => 'Business deleted successfully',
        ]);
    }
}
