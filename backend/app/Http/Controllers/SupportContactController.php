<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * A single, read-only, unauthenticated endpoint for the login page's
 * "Forgot Password" recovery screen - just the configured administrator
 * contact email (see config/gedi.php), nothing else. It never looks up,
 * verifies, or resets any account, so there is no password-reset
 * capability here at all for an unauthenticated caller to reach.
 */
class SupportContactController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'admin_email' => config('gedi.admin_contact_email'),
        ]);
    }
}
