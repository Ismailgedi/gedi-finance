<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Notification;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use App\Notifications\ResetPasswordNotification;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_valid_credentials_create_a_session_and_return_user(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_use_a_generic_error(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(401)->assertJsonPath('message', 'Invalid email or password.');

        $this->postJson('/api/login', [
            'email' => 'missing@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401)->assertJsonPath('message', 'Invalid email or password.');
    }

    public function test_inactive_users_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertStatus(403)->assertJsonPath('message', 'Your account has been disabled.');

        $this->assertGuest();
    }

    public function test_disabled_account_message_requires_the_correct_password_first(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
            'is_active' => false,
        ]);

        // A wrong password on a disabled account should still look like an
        // ordinary failed login, not confirm the account exists/is disabled.
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(401)->assertJsonPath('message', 'Invalid email or password.');
    }

    public function test_authenticated_users_can_access_finance_api_and_logout(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $this->getJson('/api/accounts')->assertUnauthorized();
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk();
        $this->getJson('/api/accounts')->assertOk();
        $this->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/accounts')->assertUnauthorized();
    }

    public function test_password_reset_request_is_generic_and_reset_works(): void
    {
        $user = User::factory()->create();
        Notification::fake();

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('message', "If an account exists for this email, we've sent a password reset link.");

        $this->postJson('/api/forgot-password', ['email' => 'missing@example.com'])
            ->assertOk()
            ->assertJsonPath('message', "If an account exists for this email, we've sent a password reset link.");

        Notification::assertSentTo($user, function (ResetPasswordNotification $notification) use ($user): bool {
            return str_contains((string) $notification->toMail($user)->subject, 'Reset your Gedi Finance password');
        });

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'new-password',
        ])->assertOk();

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ])->assertStatus(422);
    }
}
