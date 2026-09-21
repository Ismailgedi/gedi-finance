<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks API access to the normal application while the authenticated
 * user still has must_change_password = true.
 *
 * This is the server-side half of the "force password change" flow. The
 * frontend already gates its own routes on this flag, but a route guard in
 * React only prevents navigation in the app's own UI - it does nothing to
 * stop someone from calling the API directly (curl, Postman, devtools)
 * while still on the temporary password. This middleware is the real
 * enforcement boundary.
 *
 * Applied to the finance/admin route groups only; it must NOT be applied
 * to the handful of routes a user needs while still forced to change
 * their password (checking who they are, changing the password, logging
 * out), or they could never get out of the locked state.
 */
class EnsurePasswordHasBeenChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            return response()->json([
                'message' => 'You must change your temporary password before continuing.',
                'must_change_password' => true,
            ], 423);
        }

        return $next($request);
    }
}
