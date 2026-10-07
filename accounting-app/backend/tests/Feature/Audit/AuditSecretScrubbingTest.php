<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\RoleName;
use App\Services\Audit\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The global audit scrubber.
 *
 * Phase 14 §31 asks for cookie/session scrubbing in the audit payloads, and is
 * explicit that it must not be an FX-only implementation but a rule the whole
 * audit system obeys. The scrubber already existed; this file pins the property
 * that a credential parked under a cookie or session key is redacted just as a
 * bare `token` field is, and that key matching survives the casing and separators
 * an HTTP header name arrives with.
 *
 * The counter-assertion matters as much as the positive ones: a non-secret field
 * survives untouched, so the scrubber is known to be a deny-list rather than
 * something that blanks a whole payload the moment it sees the word "cookie".
 */
class AuditSecretScrubbingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function credential_material_is_redacted_from_cookie_and_session_bags(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($user);

        $log = app(AuditService::class)->security(
            AuditAction::Login,
            $user,
            [
                'headers' => [
                    'Authorization' => 'Bearer super-secret',
                    'Cookie' => 'session=abc; token=def',
                    'Set-Cookie' => 'remember_token=ghi',
                    'X-CSRF-Token' => 'csrf-123',
                ],
                'session' => ['id' => 'sess-1'],
                'session_id' => 'sess-1',
                'password' => 'hunter2',
                'token' => 'raw-token',
                'safe' => 'keep-me',
            ],
        );

        $this->assertNotNull($log);

        $metadata = $log->metadata;

        $this->assertSame('[REDACTED]', $metadata['headers']['Authorization']);
        $this->assertSame('[REDACTED]', $metadata['headers']['Cookie']);
        $this->assertSame('[REDACTED]', $metadata['headers']['Set-Cookie']);
        $this->assertSame('[REDACTED]', $metadata['headers']['X-CSRF-Token']);
        $this->assertSame('[REDACTED]', $metadata['session']);
        $this->assertSame('[REDACTED]', $metadata['session_id']);
        $this->assertSame('[REDACTED]', $metadata['password']);
        $this->assertSame('[REDACTED]', $metadata['token']);
        $this->assertSame('keep-me', $metadata['safe']);
    }

    #[Test]
    public function a_non_secret_field_that_merely_mentions_a_secret_word_survives(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $this->createCompanyFor($user);

        $log = app(AuditService::class)->security(
            AuditAction::Login,
            $user,
            ['token_count' => 3, 'session_title' => 'March close'],
        );

        $this->assertSame(3, $log->metadata['token_count']);
        $this->assertSame('March close', $log->metadata['session_title']);
    }
}
