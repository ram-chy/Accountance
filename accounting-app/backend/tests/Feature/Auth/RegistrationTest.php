<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'mobile_no' => '+15551234567',
            'password' => 'Str0ng@Pass1',
            'password_confirmation' => 'Str0ng@Pass1',
        ], $overrides);
    }

    public function test_a_visitor_can_register(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['token', 'token_type', 'expires_in', 'user' => [
                    'id', 'first_name', 'last_name', 'email', 'mobile_no', 'roles',
                ]],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'john.doe@example.com');

        $this->assertDatabaseHas('users', ['email' => 'john.doe@example.com']);
    }

    public function test_registration_assigns_only_the_staff_role(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', $this->payload())->assertCreated();

        $user = User::where('email', 'john.doe@example.com')->firstOrFail();

        $this->assertTrue($user->hasRole(RoleName::Staff->value));
        $this->assertFalse($user->hasRole(RoleName::Admin->value));
        $this->assertCount(1, $user->roles);
    }

    public function test_registration_ignores_a_requested_role(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', $this->payload(['roles' => ['Admin']]))
            ->assertCreated();

        $user = User::where('email', 'john.doe@example.com')->firstOrFail();

        $this->assertFalse($user->hasRole(RoleName::Admin->value));
        $this->assertTrue($user->hasRole(RoleName::Staff->value));
    }

    public function test_password_is_hashed_and_never_returned(): void
    {
        Notification::fake();

        $body = $this->postJson('/api/auth/register', $this->payload())
            ->assertCreated()
            ->getContent();

        $user = User::where('email', 'john.doe@example.com')->firstOrFail();

        $this->assertNotSame('Str0ng@Pass1', $user->password);
        $this->assertTrue(Hash::check('Str0ng@Pass1', $user->password));
        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('$2y$', $body);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', $this->payload())->assertCreated();

        $this->postJson('/api/auth/register', $this->payload([
            'email' => 'JOHN.DOE@example.com',
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['email' => 'not-an-email']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $this->postJson('/api/auth/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'password']);
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $this->postJson('/api/auth/register', $this->payload([
            'password_confirmation' => 'Different@123',
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function weakPasswordProvider(): array
    {
        return [
            'too short' => ['Ab1@ef'],
            'no uppercase' => ['str0ng@pass1'],
            'no lowercase' => ['STR0NG@PASS1'],
            'no number' => ['Strong@Pass'],
            'no symbol' => ['Str0ngPass1'],
        ];
    }

    #[DataProvider('weakPasswordProvider')]
    public function test_weak_passwords_are_rejected(string $password): void
    {
        $this->postJson('/api/auth/register', $this->payload([
            'password' => $password,
            'password_confirmation' => $password,
        ]))->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_mobile_number_format_is_validated(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['mobile_no' => 'abc']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('mobile_no');
    }

    public function test_registration_sends_an_email_verification_notification(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', $this->payload())->assertCreated();

        Notification::assertSentTo(
            User::where('email', 'john.doe@example.com')->firstOrFail(),
            VerifyEmail::class,
        );
    }
}
