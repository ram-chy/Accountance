<?php

namespace Tests\Feature\Auth;

use App\Exceptions\FrontendUrlNotConfiguredException;
use App\Models\User;
use App\Support\FrontendUrl;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The password-reset email must open the public frontend form, not the API.
 *
 * The backend renders no reset form. The named `password.reset` route is a
 * token probe (GET /api/auth/reset-password/{token}) that the SPA calls before
 * showing the form; it stays in place. What changes here is only the link that
 * is emailed to the user, which must point at the configured frontend origin.
 */
class PasswordResetNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND = 'https://app.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        // A usable origin is required for a link to be produced at all.
        config(['frontend.url' => self::FRONTEND]);
    }

    /**
     * Capture the link inside the notification that the endpoint sent.
     */
    private function resetUrlFor(User $user): string
    {
        $captured = null;

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use ($user, &$captured): bool {
                $captured = $notification->toMail($user)->actionUrl;

                return true;
            },
        );

        $this->assertIsString($captured);

        return $captured;
    }

    public function test_the_reset_email_links_to_the_frontend_reset_page(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('success', true);

        $url = $this->resetUrlFor($user);

        $this->assertStringStartsWith(self::FRONTEND.'/reset-password?', $url);
        $this->assertStringNotContainsString('/api/', $url);
    }

    public function test_the_token_and_email_are_carried_in_the_query_string(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        $notification = Notification::sent($user, ResetPassword::class)->first();

        $url = $this->resetUrlFor($user);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame($notification->token, $query['token'] ?? null);
        $this->assertSame('user@example.com', $query['email'] ?? null);
    }

    public function test_a_plus_addressed_email_is_percent_encoded(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'user+ledger@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        $url = $this->resetUrlFor($user);

        // RFC 3986 encoding: '+' must survive as %2B, not be decoded to a space.
        $this->assertStringContainsString('email=user%2Bledger%40example.com', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('user+ledger@example.com', $query['email'] ?? null);
    }

    public function test_a_spoofed_host_header_cannot_change_the_destination(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->withHeaders([
            'Host' => 'evil.example.com',
            'X-Forwarded-Host' => 'evil.example.com',
        ])->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        $url = $this->resetUrlFor($user);

        $this->assertStringStartsWith(self::FRONTEND, $url);
        $this->assertStringNotContainsString('evil.example.com', $url);
    }

    public function test_an_unknown_email_gets_the_same_generic_response_and_no_mail(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'If the account exists, password recovery instructions have been sent.',
            ]);

        Notification::assertNothingSent();
    }

    public function test_a_known_email_gets_the_same_generic_response(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'user@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'If the account exists, password recovery instructions have been sent.',
            ]);
    }

    public function test_a_missing_frontend_origin_fails_loudly_instead_of_building_a_link(): void
    {
        config(['frontend.url' => null]);

        $this->expectException(FrontendUrlNotConfiguredException::class);

        FrontendUrl::to('/reset-password', ['token' => 'x', 'email' => 'a@b.test']);
    }

    public function test_a_missing_frontend_origin_fails_uniformly_for_known_and_unknown_emails(): void
    {
        Notification::fake();

        config(['frontend.url' => null]);

        $user = User::factory()->create(['email' => 'user@example.com']);

        // Both must fail identically: the origin is validated before the broker
        // is consulted, so a misconfigured deployment cannot reveal whether an
        // address is registered.
        $known = $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com']);

        $known->assertStatus(500);
        $unknown->assertStatus(500);

        Notification::assertNothingSent();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableOrigins(): array
    {
        return [
            'relative path' => ['/reset-password'],
            'scheme-relative' => ['//evil.example.com'],
            'javascript scheme' => ['javascript:alert(1)'],
            'ftp scheme' => ['ftp://files.example.com'],
            'no host' => ['https://'],
        ];
    }

    #[DataProvider('unusableOrigins')]
    public function test_an_unusable_origin_is_rejected(string $origin): void
    {
        config(['frontend.url' => $origin]);

        $this->expectException(FrontendUrlNotConfiguredException::class);

        FrontendUrl::to('/reset-password', ['token' => 'x', 'email' => 'a@b.test']);
    }

    public function test_a_usable_origin_with_a_path_is_accepted(): void
    {
        config(['frontend.url' => 'https://portal.example.test/app/']);

        $this->assertSame(
            'https://portal.example.test/app/reset-password?token=t&email=a%40b.test',
            FrontendUrl::to('/reset-password', ['token' => 't', 'email' => 'a@b.test']),
        );
    }

    public function test_the_api_token_probe_route_is_still_registered(): void
    {
        $this->assertNotNull(
            app('router')->getRoutes()->getByName('password.reset'),
        );
    }

    public function test_the_token_probe_accepts_a_valid_token_and_rejects_an_invalid_one(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com']);

        $token = Password::broker()->createToken($user);

        $this->getJson('/api/auth/reset-password/'.$token.'?email='.urlencode($user->email))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', $user->email);

        // The route only matches an alphanumeric token, so a malformed one never
        // reaches the controller; an alphanumeric-but-stale one gets the 400.
        $this->getJson('/api/auth/reset-password/invalidtoken123?email='.urlencode($user->email))
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_the_token_probe_does_not_reveal_account_existence(): void
    {
        // Unknown address and bad token answer with the same body.
        $this->getJson('/api/auth/reset-password/whatever?email='.urlencode('nobody@example.com'))
            ->assertStatus(400)
            ->assertJsonPath('message', 'This password reset link is invalid or has expired.');
    }

    public function test_a_valid_token_resets_the_password_exactly_once(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('OldPassword@1'),
        ]);

        $token = Password::broker()->createToken($user);

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'BrandNew@Pass9',
            'password_confirmation' => 'BrandNew@Pass9',
        ];

        $this->postJson('/api/auth/reset-password', $payload)->assertOk();

        $this->assertTrue(Hash::check('BrandNew@Pass9', $user->fresh()->password));

        // The broker deletes the token on use, so the same token is now rejected.
        $this->postJson('/api/auth/reset-password', $payload)->assertStatus(400);
    }
}
