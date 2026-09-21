<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\TemporaryPasswordNotification;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        Role::findOrCreate('Super Admin', 'web');
        Role::findOrCreate('User', 'web');
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create(['password' => Hash::make('admin-password')]);
        $admin->assignRole('Super Admin');

        return $admin;
    }

    // A. Super Admin resets Test User password / B. temporary password is generated
    public function test_super_admin_can_reset_a_users_password(): void
    {
        $admin = $this->superAdmin();
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole('User');

        $response = $this->actingAs($admin)->postJson("/api/admin/users/{$user->id}/reset-password");

        $response->assertOk();
        $temporaryPassword = $response->json('temporary_password');
        $this->assertNotEmpty($temporaryPassword);

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check($temporaryPassword, $user->password));

        // N. No plaintext password stored in the database.
        $this->assertNotEquals($temporaryPassword, $user->getRawOriginal('password'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'password_reset_by_admin',
            'auditable_id' => (string) $user->id,
            'user_id' => $admin->id,
        ]);
    }

    // C-F. Test User logs in with the temporary password, is forced to change it,
    // changes it, and can then use the application normally.
    public function test_full_temporary_password_lifecycle(): void
    {
        $admin = $this->superAdmin();
        $user = User::factory()->create(['must_change_password' => false]);
        $user->assignRole('User');

        $temporaryPassword = $this->actingAs($admin)
            ->postJson("/api/admin/users/{$user->id}/reset-password")
            ->json('temporary_password');

        $this->app['auth']->forgetGuards();

        // C. Log in with the temporary password.
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => $temporaryPassword,
        ])->assertOk()->assertJsonPath('must_change_password', true);

        // D. Forced to change password: every normal app route is blocked
        // server-side, not just hidden in the UI.
        $this->getJson('/api/dashboard')->assertStatus(423);
        $this->getJson('/api/transactions')->assertStatus(423);

        // Allowed while locked: check identity, change password, log out.
        $this->getJson('/api/me')->assertOk();

        // G. Cannot reuse the temporary password as the new password.
        $this->putJson('/api/password', [
            'current_password' => $temporaryPassword,
            'password' => $temporaryPassword,
            'password_confirmation' => $temporaryPassword,
        ])->assertStatus(422);

        // E. Changes password successfully.
        $this->putJson('/api/password', [
            'current_password' => $temporaryPassword,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk()->assertJsonPath('must_change_password', false);

        $user->refresh();
        $this->assertFalse($user->must_change_password);

        // F. Can access the application normally afterward.
        $this->getJson('/api/dashboard')->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'password_changed',
            'auditable_id' => (string) $user->id,
        ]);
    }

    public function test_password_change_invalidates_other_open_sessions_for_the_account(): void
    {
        $user = User::factory()->create(['must_change_password' => true, 'password' => Hash::make('temp-pass-123')]);
        $user->assignRole('User');

        // Simulates a session open on another device/browser for this
        // account. (The testing session driver is array, not database, so
        // the request's own session row is never actually persisted here -
        // that persistence is standard Laravel behavior under the
        // database driver and isn't what this test is checking. What this
        // exercises is that AuthController::updatePassword() sweeps the
        // sessions table for the user's other rows.)
        DB::table('sessions')->insert([
            'id' => 'other-device-session',
            'user_id' => $user->id,
            'payload' => 'x',
            'last_activity' => time(),
        ]);

        $this->actingAs($user)->putJson('/api/password', [
            'current_password' => 'temp-pass-123',
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
    }

    public function test_admin_reset_password_invalidates_the_users_existing_sessions(): void
    {
        $admin = $this->superAdmin();
        $user = User::factory()->create();
        $user->assignRole('User');

        DB::table('sessions')->insert([
            'id' => 'user-existing-session',
            'user_id' => $user->id,
            'payload' => 'x',
            'last_activity' => time(),
        ]);

        $this->actingAs($admin)->postJson("/api/admin/users/{$user->id}/reset-password")->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'user-existing-session']);
    }

    // H-K. Disable / enable lifecycle.
    public function test_disabled_user_cannot_log_in_and_re_enabled_user_can(): void
    {
        $admin = $this->superAdmin();
        $user = User::factory()->create(['password' => Hash::make('user-password')]);
        $user->assignRole('User');

        DB::table('sessions')->insert([
            'id' => 'user-open-session',
            'user_id' => $user->id,
            'payload' => 'x',
            'last_activity' => time(),
        ]);

        // H. Super Admin disables Test User.
        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$user->id}", ['is_active' => false])
            ->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'user-open-session']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user_disabled', 'auditable_id' => (string) $user->id]);

        $this->app['auth']->forgetGuards();

        // I. Test User cannot log in while disabled, with a clear message.
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'user-password',
        ])->assertStatus(403)->assertJsonPath('message', 'Your account has been disabled.');

        // Still visible to the Super Admin (not deleted).
        $this->actingAs($admin)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonFragment(['id' => $user->id, 'is_active' => false]);

        // J. Super Admin enables Test User.
        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$user->id}", ['is_active' => true])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'user_enabled', 'auditable_id' => (string) $user->id]);

        $this->app['auth']->forgetGuards();

        // K. Test User can log in again.
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'user-password',
        ])->assertOk();
    }

    public function test_super_admin_cannot_disable_or_demote_their_own_account(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$admin->id}", ['is_active' => false])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$admin->id}", ['role' => 'User'])
            ->assertStatus(422);

        $admin->refresh();
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->hasRole('Super Admin'));
    }

    // L. Normal user cannot access User Management.
    public function test_normal_user_cannot_access_user_management(): void
    {
        $user = User::factory()->create();
        $user->assignRole('User');

        $this->actingAs($user)->getJson('/api/admin/users')->assertStatus(403);
        $this->actingAs($user)->postJson('/api/admin/users', [
            'name' => 'New',
            'email' => 'new@example.com',
            'role' => 'User',
        ])->assertStatus(403);
    }

    // M. Unauthorized API requests are rejected by the backend.
    public function test_unauthenticated_requests_to_admin_routes_are_rejected(): void
    {
        $this->getJson('/api/admin/users')->assertStatus(401);
    }

    public function test_user_creation_requires_an_existing_role(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'New Person',
            'email' => 'newperson@example.com',
            'role' => 'Nonexistent Role',
        ])->assertStatus(422);
    }

    public function test_created_user_has_a_temporary_password_and_must_change_it(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'New Person',
            'email' => 'newperson@example.com',
            'role' => 'User',
        ]);

        $response->assertCreated();
        $temporaryPassword = $response->json('temporary_password');
        $this->assertNotEmpty($temporaryPassword);

        $user = User::where('email', 'newperson@example.com')->first();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->hasRole('User'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'user_created', 'auditable_id' => (string) $user->id]);
    }

    // O. No plaintext passwords appear in Laravel logs, and the API never
    // exposes the password hash.
    public function test_user_responses_never_expose_the_password_hash(): void
    {
        $admin = $this->superAdmin();
        $user = User::factory()->create();
        $user->assignRole('User');

        $this->actingAs($admin)
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonMissingPath('users.0.password');

        $this->actingAs($user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonMissingPath('user.password');
    }

    public function test_temporary_password_email_is_only_attempted_with_a_real_mailer_configured(): void
    {
        $admin = $this->superAdmin();
        $user = User::factory()->create();
        $user->assignRole('User');

        Notification::fake();
        Config::set('mail.default', 'log');

        $this->actingAs($admin)->postJson("/api/admin/users/{$user->id}/reset-password")->assertOk();

        Notification::assertNothingSent();

        Config::set('mail.default', 'array');

        $this->actingAs($admin)->postJson("/api/admin/users/{$user->id}/reset-password")->assertOk();

        Notification::assertSentTo($user, TemporaryPasswordNotification::class);
    }

    public function test_login_and_logout_are_audited(): void
    {
        $user = User::factory()->create(['password' => Hash::make('a-password')]);
        $user->assignRole('User');

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'a-password'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'auditable_id' => (string) $user->id]);

        $this->postJson('/api/logout')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'logout', 'auditable_id' => (string) $user->id]);
    }
}
