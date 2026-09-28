<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_registration(): void
    {
        $payload = [
            'name' => 'Nasjiar Dev',
            'email' => 'nasjiar@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ];

        $response = $this->postJson('/api/register', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'created_at',
                        'updated_at',
                    ],
                    'token',
                ],
            ])
            ->assertJson([
                'message' => 'User registered successfully',
                'data' => [
                    'user' => [
                        'name' => 'Nasjiar Dev',
                        'email' => 'nasjiar@example.com',
                    ],
                ],
            ]);

        // Ensure password is NEVER returned in response
        $this->assertArrayNotHasKey('password', $response->json('data.user'));

        // Ensure user is created and password is properly hashed
        $user = User::where('email', 'nasjiar@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('secret123', $user->password));
    }

    public function test_duplicate_email(): void
    {
        User::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $payload = [
            'name' => 'Another User',
            'email' => 'existing@example.com',
            'password' => 'secret123',
        ];

        $response = $this->postJson('/api/register', $payload);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Validation failed',
            ])
            ->assertJsonValidationErrors(['email']);
    }

    public function test_successful_login(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'created_at',
                        'updated_at',
                    ],
                    'token',
                ],
            ])
            ->assertJson([
                'message' => 'Login successful',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'email' => 'user@example.com',
                    ],
                ],
            ]);

        // Ensure password is not returned
        $this->assertArrayNotHasKey('password', $response->json('data.user'));
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('correct_password'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'user@example.com',
            'password' => 'wrong_password',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Invalid login credentials',
            ])
            ->assertJsonStructure([
                'message',
                'errors' => [
                    'email',
                ],
            ]);
    }

    public function test_authenticated_user(): void
    {
        $user = User::factory()->create([
            'name' => 'Authenticated Person',
            'email' => 'auth@example.com',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'User profile retrieved successfully',
                'data' => [
                    'id' => $user->id,
                    'name' => 'Authenticated Person',
                    'email' => 'auth@example.com',
                ],
            ]);

        $this->assertArrayNotHasKey('password', $response->json('data'));
    }

    public function test_unauthenticated_request(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Unauthenticated',
            ]);
    }

    public function test_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->assertCount(1, $user->tokens);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/logout');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Logged out successfully',
            ]);

        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_unauthenticated_request_with_invalid_bearer_token(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer 999|invalid_malformed_fake_token')
            ->getJson('/api/user');

        $response->assertStatus(401);
    }

    public function test_registration_validation_rules_for_mismatched_password_and_invalid_email(): void
    {
        // Mismatched password confirmation
        $this->postJson('/api/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'different_password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password_confirmation']);

        // Invalid email format
        $this->postJson('/api/register', [
            'name' => 'John Doe',
            'email' => 'not-a-valid-email',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
