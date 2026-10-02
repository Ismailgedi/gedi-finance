<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safety net for the incident that destroyed the real `gedi_finance`
     * Postgres dev database: a test run was pointed at it by mistake, and
     * RefreshDatabase's migrate:fresh wiped every table. This check runs
     * BEFORE the app boots, reading the raw process env exactly as
     * PHPUnit's <php><env> block (phpunit.xml) sets it - so it fires before
     * RefreshDatabase ever gets a chance to touch anything. It runs again
     * after boot, against Laravel's resolved config, as a second layer in
     * case something other than phpunit.xml's env block ends up choosing
     * the connection. The normal suite (phpunit.xml: sqlite/:memory:) never
     * matches either check and is completely unaffected.
     */
    protected function setUp(): void
    {
        self::guardAgainstRealDatabase(
            getenv('DB_CONNECTION') ?: null,
            getenv('DB_DATABASE') ?: null,
            getenv('DB_PORT') ?: null,
        );

        parent::setUp();

        self::guardAgainstRealDatabase(
            config('database.default'),
            config('database.connections.' . config('database.default') . '.database'),
            config('database.connections.' . config('database.default') . '.port'),
        );

        // Every test that uses RefreshDatabase gets a freshly migrated,
        // empty-of-roles database - and most of this suite predates the
        // Spatie-permission system, creating its "Super Admin"/"User"
        // fixture roles directly via Role::findOrCreate() with no
        // permissions ever synced onto them. Since routes/api.php now
        // gates almost every endpoint by permission (not just role name),
        // an un-synced role would fail every one of those checks. Running
        // this here, once, automatically, for every test - rather than
        // requiring dozens of existing test files to each call it
        // themselves - means every test's "Super Admin" is a real Super
        // Admin (every permission) the moment it's created, exactly like
        // production. A test's own Role::findOrCreate() call afterward is
        // a harmless no-op (the role already exists).
        if (array_key_exists('Illuminate\\Foundation\\Testing\\RefreshDatabase', class_uses_recursive(static::class))) {
            DatabaseSeeder::seedRolesAndPermissions();
        }
    }

    /**
     * Most of this suite predates the Spatie-permission system and was
     * written against the old rule "any authenticated user can do every
     * day-to-day action (sales, purchases, payments, inventory, ...) -
     * only a handful of role:Super Admin routes (void, /admin/*,
     * audit-logs) are restricted". Hundreds of existing tests call
     * `$this->actingAs(User::factory()->create())` with no role at all,
     * relying on exactly that. Under the new permission system a roleless
     * user has zero permissions and would fail every one of those checks
     * - not because the business behavior being tested actually changed,
     * but purely because the fixture predates permissions existing at
     * all.
     *
     * Rather than editing every one of those test files individually,
     * this assigns 'Manager' - the one new role whose permission set was
     * deliberately built to cover exactly "every day-to-day action" (see
     * DatabaseSeeder::ROLE_PERMISSIONS) - to any User that reaches
     * actingAs() still holding zero roles. A test that explicitly
     * assigns its own role first (assignRole('Super Admin'), ...,
     * 'Sales & Inventory') is completely unaffected, since this only
     * ever fires when no role has been assigned yet; a test that
     * specifically wants to verify a permission-less account is rejected
     * can still assert that directly against a user it deliberately
     * leaves roleless and never passes through actingAs() this way (or
     * asserts before this runs).
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        if ($user instanceof User && $user->exists && $user->roles()->count() === 0) {
            $user->assignRole('Manager');
        }

        return parent::actingAs($user, $guard);
    }

    private static function guardAgainstRealDatabase(?string $connection, ?string $database, mixed $port): void
    {
        if ($connection === 'pgsql' && ($database === 'gedi_finance' || (string) $port === '55432')) {
            throw new RuntimeException(
                'Refusing to run tests against the real gedi_finance database. '
                . 'PHPUnit must use the sqlite/:memory: connection configured in phpunit.xml - '
                . 'this connection would let RefreshDatabase run migrate:fresh against real data.'
            );
        }
    }
}
