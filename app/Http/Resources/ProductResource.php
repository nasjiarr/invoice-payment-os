<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'price_in_cents' => (int) round(((float) $this->price) * 100),
            'active' => (bool) $this->active,
            'business' => new BusinessResource($this->whenLoaded('business')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
