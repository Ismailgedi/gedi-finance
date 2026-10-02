<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * routes/web.php's "/" now serves the built SPA's index.html (see that
 * file's own doc comment - Render's Nginx normally answers "/" itself and
 * never reaches this route, but a plain Apache deployment with no
 * web-server-level static handling, e.g. InfinityFree, relies on it).
 * No frontend build exists in this repo/test environment (it's a build
 * artifact, never committed), so these tests exercise both the "nothing
 * built yet" state and the "a build exists" state directly rather than
 * depending on whichever happens to be true on disk at test time.
 */
class ExampleTest extends TestCase
{
    private function builtIndexPath(): string
    {
        return public_path('index.html');
    }

    protected function tearDown(): void
    {
        // Never leave a test-created index.html behind for a later test
        // run (or a real `npm run build` output) to collide with.
        if (file_exists($this->builtIndexPath()) && str_contains((string) file_get_contents($this->builtIndexPath()), 'ExampleTest fixture')) {
            unlink($this->builtIndexPath());
        }

        parent::tearDown();
    }

    public function test_root_returns_not_found_when_no_frontend_build_exists(): void
    {
        if (file_exists($this->builtIndexPath())) {
            $this->markTestSkipped('A real frontend build is present in public/ - nothing to assert about the missing-build case here.');
        }

        $response = $this->get('/');

        $response->assertStatus(404);
    }

    public function test_root_serves_the_built_spa_shell_when_a_build_exists(): void
    {
        $alreadyBuilt = file_exists($this->builtIndexPath());

        if (!$alreadyBuilt) {
            file_put_contents($this->builtIndexPath(), '<!doctype html><title>ExampleTest fixture</title>');
        }

        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_a_client_side_route_also_serves_the_spa_shell(): void
    {
        $alreadyBuilt = file_exists($this->builtIndexPath());

        if (!$alreadyBuilt) {
            file_put_contents($this->builtIndexPath(), '<!doctype html><title>ExampleTest fixture</title>');
        }

        // A react-router-dom client route (e.g. a hard refresh on
        // /dashboard) must resolve to the same SPA shell, not a 404.
        $response = $this->get('/dashboard');

        $response->assertStatus(200);
    }

    public function test_the_spa_fallback_never_swallows_api_or_sanctum_routes(): void
    {
        // Neither of these needs to succeed business-logic-wise here -
        // the point is they must not be answered by the SPA-fallback
        // route (which would return a text/html 200), proving the
        // negative-lookahead exclusion actually works.
        $apiResponse = $this->getJson('/api/products');
        $apiResponse->assertHeader('Content-Type', 'application/json');

        $sanctumResponse = $this->get('/sanctum/csrf-cookie');
        $sanctumResponse->assertStatus(204);
    }

    /**
     * /deploy/seed exists purely so InfinityFree (no SSH/Artisan access)
     * can run migrate+db:seed via one authenticated GET request - see
     * INFINITYFREE_UPLOAD_STEPS.md. It must be completely inert unless
     * SEED_TOKEN is deliberately set (which it never is here, matching
     * every real environment this codebase ships to by default,
     * including Render - render.yaml never sets it), and must never be
     * swallowed by the SPA fallback either way.
     */
    public function test_deploy_seed_route_is_a_404_when_no_seed_token_is_configured(): void
    {
        $response = $this->get('/deploy/seed?token=anything');

        $response->assertStatus(404);
    }

    public function test_deploy_seed_route_rejects_the_wrong_token(): void
    {
        config(['app.debug' => false]);
        putenv('SEED_TOKEN=the-real-token');

        try {
            $response = $this->get('/deploy/seed?token=wrong-token');

            $response->assertStatus(403);
        } finally {
            putenv('SEED_TOKEN');
        }
    }
}
