<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_customer(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        Sanctum::actingAs($user);

        $payload = [
            'business_id' => $business->id,
            'name' => 'PT Pelanggan Hebat',
            'email' => 'client@hebat.com',
            'phone' => '0812345678',
            'address' => 'Surabaya, Jawa Timur',
            'notes' => 'Priority client',
        ];

        $response = $this->postJson('/api/customers', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'business_id',
                    'name',
                    'email',
                    'phone',
                    'address',
                    'notes',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJson([
                'message' => 'Customer created successfully',
                'data' => [
                    'name' => 'PT Pelanggan Hebat',
                    'email' => 'client@hebat.com',
                    'business_id' => $business->id,
                ],
            ]);

        $this->assertDatabaseHas('customers', [
            'business_id' => $business->id,
            'name' => 'PT Pelanggan Hebat',
            'email' => 'client@hebat.com',
        ]);
    }

    public function test_user_cannot_create_customer_in_another_users_business(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $businessA = Business::factory()->create(['owner_id' => $userA->id]);
        $businessA->users()->attach($userA->id, ['role' => BusinessRole::Owner->value]);

        Sanctum::actingAs($userB);

        $payload = [
            'business_id' => $businessA->id,
            'name' => 'Intruder Client',
            'email' => 'intruder@test.com',
        ];

        // User B cannot create a customer in User A's business
        $response = $this->postJson('/api/customers', $payload);
        $response->assertStatus(403);

        $this->assertDatabaseMissing('customers', [
            'name' => 'Intruder Client',
        ]);
    }

    public function test_user_can_read_paginated_customers_list(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        Customer::factory()->count(20)->create(['business_id' => $business->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/customers?per_page=10');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'business_id',
                        'name',
                        'email',
                    ],
                ],
                'links',
                'meta' => [
                    'current_page',
                    'per_page',
                    'total',
                ],
            ]);

        $this->assertCount(10, $response->json('data'));
        $this->assertEquals(20, $response->json('meta.total'));
    }

    public function test_user_can_search_customers_by_name_or_email(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        Customer::factory()->create([
            'business_id' => $business->id,
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
        ]);

        Customer::factory()->create([
            'business_id' => $business->id,
            'name' => 'Siti Nurhaliza',
            'email' => 'siti@example.com',
        ]);

        Sanctum::actingAs($user);

        // Search by name
        $responseName = $this->getJson('/api/customers?search=Budi');
        $responseName->assertStatus(200);
        $this->assertCount(1, $responseName->json('data'));
        $this->assertEquals('Budi Santoso', $responseName->json('data.0.name'));

        // Search by email
        $responseEmail = $this->getJson('/api/customers?search=siti@example.com');
        $responseEmail->assertStatus(200);
        $this->assertCount(1, $responseEmail->json('data'));
        $this->assertEquals('Siti Nurhaliza', $responseEmail->json('data.0.name'));
    }

    public function test_user_can_sort_customers_safely(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        Customer::factory()->create(['business_id' => $business->id, 'name' => 'Alpha Corp']);
        Customer::factory()->create(['business_id' => $business->id, 'name' => 'Zeta Corp']);

        Sanctum::actingAs($user);

        // Ascending sort by name
        $responseAsc = $this->getJson('/api/customers?sort=name&direction=asc');
        $responseAsc->assertStatus(200);
        $this->assertEquals('Alpha Corp', $responseAsc->json('data.0.name'));

        // Descending sort by name
        $responseDesc = $this->getJson('/api/customers?sort=name&direction=desc');
        $responseDesc->assertStatus(200);
        $this->assertEquals('Zeta Corp', $responseDesc->json('data.0.name'));
    }

    public function test_user_can_read_own_single_customer(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        $customer = Customer::factory()->create([
            'business_id' => $business->id,
            'name' => 'Single Client',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/customers/'.$customer->id);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Customer retrieved successfully',
                'data' => [
                    'id' => $customer->id,
                    'name' => 'Single Client',
                    'business_id' => $business->id,
                ],
            ]);
    }

    public function test_user_can_update_own_customer(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        $customer = Customer::factory()->create([
            'business_id' => $business->id,
            'name' => 'Old Name',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/customers/'.$customer->id, [
            'name' => 'Updated Name',
            'phone' => '0877777777',
            // Attempt to switch customer to another business
            'business_id' => 9999,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Customer updated successfully',
                'data' => [
                    'name' => 'Updated Name',
                    'phone' => '0877777777',
                    'business_id' => $business->id,
                ],
            ]);

        $this->assertEquals('Updated Name', $customer->fresh()->name);
        $this->assertEquals($business->id, $customer->fresh()->business_id);
    }

    public function test_user_can_delete_own_customer(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create(['owner_id' => $user->id]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        $customer = Customer::factory()->create([
            'business_id' => $business->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/customers/'.$customer->id);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Customer deleted successfully',
            ]);

        $this->assertDatabaseMissing('customers', [
            'id' => $customer->id,
        ]);
    }

    public function test_user_cannot_access_or_manipulate_another_business_customer(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $businessA = Business::factory()->create(['owner_id' => $userA->id]);
        $businessB = Business::factory()->create(['owner_id' => $userB->id]);

        $businessA->users()->attach($userA->id, ['role' => BusinessRole::Owner->value]);
        $businessB->users()->attach($userB->id, ['role' => BusinessRole::Owner->value]);

        $customerA = Customer::factory()->create([
            'business_id' => $businessA->id,
            'name' => 'User A Customer',
            'email' => 'clientA@businessA.com',
        ]);

        Sanctum::actingAs($userB);

        // 1. User B list does NOT show User A's customer
        $listResponse = $this->getJson('/api/customers');
        $listResponse->assertStatus(200);
        $customerIds = collect($listResponse->json('data'))->pluck('id')->all();
        $this->assertNotContains($customerA->id, $customerIds);

        // 2. User B cannot filter by User A's business_id
        $filterResponse = $this->getJson('/api/customers?business_id='.$businessA->id);
        $filterResponse->assertStatus(403);

        // 3. User B cannot view User A's customer directly (IDOR read)
        $readResponse = $this->getJson('/api/customers/'.$customerA->id);
        $readResponse->assertStatus(403);

        // 4. User B cannot update User A's customer (IDOR update)
        $updateResponse = $this->putJson('/api/customers/'.$customerA->id, [
            'name' => 'Hacked Customer Name',
        ]);
        $updateResponse->assertStatus(403);

        // 5. User B cannot delete User A's customer (IDOR delete)
        $deleteResponse = $this->deleteJson('/api/customers/'.$customerA->id);
        $deleteResponse->assertStatus(403);

        // Verify Customer A remains intact
        $this->assertEquals('User A Customer', $customerA->fresh()->name);
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $customer = Customer::factory()->create();

        $this->getJson('/api/customers')->assertStatus(401);
        $this->postJson('/api/customers', ['name' => 'Test'])->assertStatus(401);
        $this->getJson('/api/customers/'.$customer->id)->assertStatus(401);
        $this->putJson('/api/customers/'.$customer->id, ['name' => 'Test'])->assertStatus(401);
        $this->deleteJson('/api/customers/'.$customer->id)->assertStatus(401);
    }
}
