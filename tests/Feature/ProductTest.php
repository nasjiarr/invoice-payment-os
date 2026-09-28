<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_product_with_valid_price(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        Sanctum::actingAs($user);

        $payload = [
            'business_id' => $business->id,
            'name' => 'SEO Consultation',
            'description' => 'Comprehensive SEO audit and consultation',
            'price' => 1500000.00,
            'active' => true,
        ];

        $response = $this->postJson('/api/products', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'business_id',
                    'name',
                    'description',
                    'price',
                    'price_in_cents',
                    'active',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJson([
                'message' => 'Product created successfully',
                'data' => [
                    'name' => 'SEO Consultation',
                    'price' => '1500000.00',
                    'price_in_cents' => 150000000,
                    'active' => true,
                    'business_id' => $business->id,
                ],
            ]);

        $this->assertDatabaseHas('products', [
            'business_id' => $business->id,
            'name' => 'SEO Consultation',
            'price' => 1500000.00,
            'active' => true,
        ]);
    }

    public function test_price_validation_rules(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        Sanctum::actingAs($user);

        // Negative price
        $this->postJson('/api/products', [
            'business_id' => $business->id,
            'name' => 'Invalid Product',
            'price' => -100,
        ])->assertStatus(422)->assertJsonValidationErrors(['price']);

        // Non numeric price
        $this->postJson('/api/products', [
            'business_id' => $business->id,
            'name' => 'Invalid Product',
            'price' => 'free',
        ])->assertStatus(422)->assertJsonValidationErrors(['price']);

        // More than 2 decimal places
        $this->postJson('/api/products', [
            'business_id' => $business->id,
            'name' => 'Invalid Product',
            'price' => 150.999,
        ])->assertStatus(422)->assertJsonValidationErrors(['price']);
    }

    public function test_user_can_read_paginated_products_list_with_search_and_filter(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Website Redesign',
            'description' => 'UI/UX revamp',
            'price' => 5000000.00,
            'active' => true,
        ]);

        Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Logo Branding',
            'description' => 'Visual identity design',
            'price' => 2000000.00,
            'active' => false,
        ]);

        Sanctum::actingAs($user);

        // Search test
        $responseSearch = $this->getJson('/api/products?search=Redesign');
        $responseSearch->assertStatus(200);
        $this->assertCount(1, $responseSearch->json('data'));
        $this->assertEquals('Website Redesign', $responseSearch->json('data.0.name'));

        // Filter active status test
        $responseActive = $this->getJson('/api/products?active=1');
        $responseActive->assertStatus(200);
        $this->assertCount(1, $responseActive->json('data'));
        $this->assertEquals('Website Redesign', $responseActive->json('data.0.name'));

        $responseInactive = $this->getJson('/api/products?active=0');
        $responseInactive->assertStatus(200);
        $this->assertCount(1, $responseInactive->json('data'));
        $this->assertEquals('Logo Branding', $responseInactive->json('data.0.name'));
    }

    public function test_user_can_sort_products_safely(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Cheap Service',
            'price' => 100000.00,
        ]);

        Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Expensive Service',
            'price' => 9000000.00,
        ]);

        Sanctum::actingAs($user);

        // Sort by price ascending
        $responseAsc = $this->getJson('/api/products?sort=price&direction=asc');
        $responseAsc->assertStatus(200);
        $this->assertEquals('Cheap Service', $responseAsc->json('data.0.name'));

        // Sort by price descending
        $responseDesc = $this->getJson('/api/products?sort=price&direction=desc');
        $responseDesc->assertStatus(200);
        $this->assertEquals('Expensive Service', $responseDesc->json('data.0.name'));
    }

    public function test_user_can_read_own_single_product(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Hosting Plan',
            'price' => 500000.00,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/products/'.$product->id);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Product retrieved successfully',
                'data' => [
                    'id' => $product->id,
                    'name' => 'Hosting Plan',
                    'price' => '500000.00',
                    'business_id' => $business->id,
                ],
            ]);
    }

    public function test_user_can_update_own_product(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Old Title',
            'price' => 100000.00,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/products/'.$product->id, [
            'name' => 'New Title',
            'price' => 250000.00,
            'active' => false,
            // Attempt to change business_id
            'business_id' => 9999,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Product updated successfully',
                'data' => [
                    'name' => 'New Title',
                    'price' => '250000.00',
                    'active' => false,
                    'business_id' => $business->id,
                ],
            ]);

        $this->assertEquals('New Title', $product->fresh()->name);
        $this->assertEquals('250000.00', $product->fresh()->price);
        $this->assertFalse($product->fresh()->active);
        $this->assertEquals($business->id, $product->fresh()->business_id);
    }

    public function test_user_can_delete_own_product(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/products/'.$product->id);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Product deleted successfully',
            ]);

        $this->assertDatabaseMissing('products', [
            'id' => $product->id,
        ]);
    }

    public function test_user_cannot_access_or_manipulate_another_business_product(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $businessA = Business::factory()->create(['owner_id' => $userA->id]);
        $businessB = Business::factory()->create(['owner_id' => $userB->id]);

        $businessA->users()->attach($userA->id, ['role' => BusinessRole::Owner->value]);
        $businessB->users()->attach($userB->id, ['role' => BusinessRole::Owner->value]);

        $productA = Product::factory()->create([
            'business_id' => $businessA->id,
            'name' => 'User A Product',
            'price' => 300000.00,
        ]);

        Sanctum::actingAs($userB);

        // 1. List does not leak productA to userB
        $listResponse = $this->getJson('/api/products');
        $listResponse->assertStatus(200);
        $productIds = collect($listResponse->json('data'))->pluck('id')->all();
        $this->assertNotContains($productA->id, $productIds);

        // 2. User B cannot create product in User A's business
        $this->postJson('/api/products', [
            'business_id' => $businessA->id,
            'name' => 'Hacked Product',
            'price' => 50000.00,
        ])->assertStatus(403);

        // 3. User B cannot view productA (IDOR read)
        $this->getJson('/api/products/'.$productA->id)->assertStatus(403);

        // 4. User B cannot update productA (IDOR update)
        $this->putJson('/api/products/'.$productA->id, [
            'name' => 'Hacked Name',
        ])->assertStatus(403);

        // 5. User B cannot delete productA (IDOR delete)
        $this->deleteJson('/api/products/'.$productA->id)->assertStatus(403);

        // Verify product remains intact
        $this->assertEquals('User A Product', $productA->fresh()->name);
    }

    public function test_product_used_by_invoice_does_not_alter_historical_invoice_when_modified_or_deleted(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);
        $customer = Customer::factory()->create(['business_id' => $business->id]);

        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Consulting Package',
            'price' => 1000000.00,
        ]);

        $invoice = Invoice::factory()->create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'subtotal' => 1000000.00,
            'total' => 1110000.00,
        ]);

        $invoiceItem = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'description' => 'Consulting Package',
            'quantity' => 1.00,
            'unit_price' => 1000000.00,
            'subtotal' => 1000000.00,
            'tax' => 110000.00,
            'discount' => 0.00,
            'total' => 1110000.00,
        ]);

        Sanctum::actingAs($user);

        // 1. Update product price and name via API
        $this->putJson('/api/products/'.$product->id, [
            'name' => 'Expensive Consulting Package',
            'price' => 9999999.00,
        ])->assertStatus(200);

        // Historical invoice item snapshot remains completely unchanged
        $invoiceItem->refresh();
        $this->assertEquals('Consulting Package', $invoiceItem->description);
        $this->assertEquals('1000000.00', $invoiceItem->unit_price);
        $this->assertEquals('1000000.00', $invoiceItem->subtotal);
        $this->assertEquals('1110000.00', $invoiceItem->total);

        // 2. Delete product via API
        $this->deleteJson('/api/products/'.$product->id)->assertStatus(200);

        // Historical invoice item snapshot continues to exist with nullOnDelete
        $invoiceItem->refresh();
        $this->assertNull($invoiceItem->product_id);
        $this->assertEquals('Consulting Package', $invoiceItem->description);
        $this->assertEquals('1000000.00', $invoiceItem->unit_price);
        $this->assertEquals('1110000.00', $invoiceItem->total);
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $product = Product::factory()->create();

        $this->getJson('/api/products')->assertStatus(401);
        $this->postJson('/api/products', ['name' => 'Test'])->assertStatus(401);
        $this->getJson('/api/products/'.$product->id)->assertStatus(401);
        $this->putJson('/api/products/'.$product->id, ['name' => 'Test'])->assertStatus(401);
        $this->deleteJson('/api/products/'.$product->id)->assertStatus(401);
    }
}
