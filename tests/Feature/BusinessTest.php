<?php

namespace Tests\Feature;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_business(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload = [
            'name' => 'Nasjiar Web Studio',
            'email' => 'studio@nasjiar.com',
            'phone' => '08123456789',
            'address' => 'Jakarta, Indonesia',
            'tax_id' => 'ID-TAX-9999',
            'currency' => 'IDR',
            // Attempt to spoof owner_id
            'owner_id' => 9999,
        ];

        $response = $this->postJson('/api/business', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'owner_id',
                    'name',
                    'email',
                    'phone',
                    'address',
                    'tax_id',
                    'currency',
                    'created_at',
                    'updated_at',
                ],
            ])
            ->assertJson([
                'message' => 'Business created successfully',
                'data' => [
                    'name' => 'Nasjiar Web Studio',
                    'owner_id' => $user->id,
                ],
            ]);

        $business = Business::where('name', 'Nasjiar Web Studio')->first();
        $this->assertNotNull($business);
        $this->assertEquals($user->id, $business->owner_id);

        // Verify pivot membership
        $this->assertDatabaseHas('business_user', [
            'business_id' => $business->id,
            'user_id' => $user->id,
            'role' => BusinessRole::Owner->value,
        ]);
    }

    public function test_user_can_read_own_businesses_list(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $businessA1 = Business::factory()->create(['owner_id' => $userA->id, 'name' => 'Studio A1']);
        $businessA2 = Business::factory()->create(['owner_id' => $userA->id, 'name' => 'Studio A2']);
        $businessB = Business::factory()->create(['owner_id' => $userB->id, 'name' => 'Studio B']);

        $userA->businesses()->attach([$businessA1->id, $businessA2->id], ['role' => BusinessRole::Owner->value]);
        $userB->businesses()->attach($businessB->id, ['role' => BusinessRole::Owner->value]);

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/business');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'owner_id',
                        'name',
                    ],
                ],
            ]);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($businessA1->id, $ids);
        $this->assertContains($businessA2->id, $ids);
        $this->assertNotContains($businessB->id, $ids);
    }

    public function test_user_can_read_own_single_business(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create([
            'owner_id' => $user->id,
            'name' => 'Studio Solo',
        ]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/business/'.$business->id);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Business retrieved successfully',
                'data' => [
                    'id' => $business->id,
                    'name' => 'Studio Solo',
                    'owner_id' => $user->id,
                ],
            ]);
    }

    public function test_user_can_update_own_business(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create([
            'owner_id' => $user->id,
            'name' => 'Studio Lama',
        ]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/business/'.$business->id, [
            'name' => 'Studio Baru',
            'phone' => '0899999999',
            // Attempt to reassign owner
            'owner_id' => 12345,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Business updated successfully',
                'data' => [
                    'name' => 'Studio Baru',
                    'phone' => '0899999999',
                    'owner_id' => $user->id,
                ],
            ]);

        $this->assertEquals('Studio Baru', $business->fresh()->name);
        $this->assertEquals($user->id, $business->fresh()->owner_id);
    }

    public function test_user_can_delete_own_business(): void
    {
        $user = User::factory()->create();
        $business = Business::factory()->create([
            'owner_id' => $user->id,
            'name' => 'To Be Deleted',
        ]);
        $business->users()->attach($user->id, ['role' => BusinessRole::Owner->value]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/business/'.$business->id);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Business deleted successfully',
            ]);

        $this->assertDatabaseMissing('businesses', [
            'id' => $business->id,
        ]);
    }

    public function test_user_cannot_access_another_users_business(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $businessA = Business::factory()->create([
            'owner_id' => $userA->id,
            'name' => 'Private Business of User A',
        ]);
        $businessA->users()->attach($userA->id, ['role' => BusinessRole::Owner->value]);

        Sanctum::actingAs($userB);

        // IDOR Attempt 1: Read another user's business
        $readResponse = $this->getJson('/api/business/'.$businessA->id);
        $readResponse->assertStatus(403);

        // IDOR Attempt 2: Update another user's business
        $updateResponse = $this->putJson('/api/business/'.$businessA->id, [
            'name' => 'Hacked Name',
        ]);
        $updateResponse->assertStatus(403);

        // IDOR Attempt 3: Delete another user's business
        $deleteResponse = $this->deleteJson('/api/business/'.$businessA->id);
        $deleteResponse->assertStatus(403);

        // Ensure business remains unchanged
        $this->assertEquals('Private Business of User A', $businessA->fresh()->name);
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $business = Business::factory()->create();

        $this->getJson('/api/business')->assertStatus(401);
        $this->postJson('/api/business', ['name' => 'Test'])->assertStatus(401);
        $this->getJson('/api/business/'.$business->id)->assertStatus(401);
        $this->putJson('/api/business/'.$business->id, ['name' => 'Test'])->assertStatus(401);
        $this->deleteJson('/api/business/'.$business->id)->assertStatus(401);
    }
}
