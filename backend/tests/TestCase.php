<?php

namespace Tests;

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
