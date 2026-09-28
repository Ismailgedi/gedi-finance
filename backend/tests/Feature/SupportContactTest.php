<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The login page's "Forgot Password" recovery screen reads the
 * administrator contact email from this single, read-only, unauthenticated
 * endpoint - never a password-reset capability of any kind.
 */
class SupportContactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_support_contact_is_reachable_without_authentication(): void
    {
        $response = $this->getJson('/api/support-contact')->assertOk();

        $response->assertJsonStructure(['admin_email']);
        $this->assertNotEmpty($response->json('admin_email'));
    }

    public function test_support_contact_reflects_the_configured_admin_email(): void
    {
        config(['gedi.admin_contact_email' => 'owner@example-business.test']);

        $this->getJson('/api/support-contact')
            ->assertOk()
            ->assertJsonPath('admin_email', 'owner@example-business.test');
    }

    public function test_support_contact_response_contains_nothing_beyond_the_email(): void
    {
        $response = $this->getJson('/api/support-contact')->assertOk();

        // Exactly one key - no account lookup, no reset token, no other
        // account/user data of any kind.
        $this->assertSame(['admin_email'], array_keys($response->json()));
    }
}
