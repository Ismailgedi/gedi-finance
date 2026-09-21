<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Category;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    private ?string $originalAdminEmail;

    private ?string $originalAdminPassword;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalAdminEmail = env('ADMIN_EMAIL');
        $this->originalAdminPassword = env('ADMIN_PASSWORD');
    }

    protected function tearDown(): void
    {
        $this->setEnv('ADMIN_EMAIL', $this->originalAdminEmail);
        $this->setEnv('ADMIN_PASSWORD', $this->originalAdminPassword);

        parent::tearDown();
    }

    public function test_seeding_fails_when_admin_password_is_missing(): void
    {
        $this->setEnv('ADMIN_EMAIL', 'admin@example.test');
        $this->setEnv('ADMIN_PASSWORD', null);

        try {
            (new DatabaseSeeder)->run();
            $this->fail('Expected seeding to fail without ADMIN_PASSWORD.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ADMIN_PASSWORD must be set', $exception->getMessage());
        }

        // Nothing should have been seeded - not the admin, and not the
        // reference data that normally follows it.
        $this->assertDatabaseCount('users', 0);
        $this->assertSame(0, Account::count());
        $this->assertSame(0, Category::count());
        $this->assertSame(0, Unit::count());
    }

    public function test_seeding_fails_when_admin_password_is_blank(): void
    {
        $this->setEnv('ADMIN_EMAIL', 'admin@example.test');
        $this->setEnv('ADMIN_PASSWORD', '   ');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_PASSWORD must be set');

        (new DatabaseSeeder)->run();
    }

    public function test_seeding_error_never_includes_the_password_value(): void
    {
        $this->setEnv('ADMIN_EMAIL', 'admin@example.test');
        $this->setEnv('ADMIN_PASSWORD', null);

        try {
            (new DatabaseSeeder)->run();
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('password123', $exception->getMessage());
        }
    }

    public function test_seeding_creates_super_admin_when_admin_password_is_set(): void
    {
        $this->setEnv('ADMIN_EMAIL', 'admin@example.test');
        $this->setEnv('ADMIN_PASSWORD', 'a-genuinely-strong-password-123');

        (new DatabaseSeeder)->run();

        $admin = User::where('email', 'admin@example.test')->firstOrFail();

        $this->assertTrue($admin->hasRole('Super Admin'));
        $this->assertTrue(Hash::check('a-genuinely-strong-password-123', $admin->password));

        // Reference data seeding is unaffected by the password check.
        $this->assertGreaterThan(0, Account::count());
        $this->assertGreaterThan(0, Category::count());
        $this->assertGreaterThan(0, Unit::count());
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
