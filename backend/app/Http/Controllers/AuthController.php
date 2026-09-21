<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $user = User::query()
            ->where('email', $credentials['email'])
            ->first();

        // Check credentials before account status. Revealing "this account
        // is disabled" only after the password is confirmed correct limits
        // it as a user-enumeration vector (an attacker still needs a valid
        // password to learn the account exists), while still giving a
        // genuinely disabled user a clear, actionable message instead of a
        // generic one.
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json(
                ['message' => 'Invalid email or password.'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        if (!$user->is_active) {
            return response()->json(
                ['message' => 'Your account has been disabled.'],
                Response::HTTP_FORBIDDEN
            );
        }

        Auth::guard('web')->login(
            $user,
            (bool) ($credentials['remember'] ?? false)
        );

        $request->session()->regenerate();

        $user = $user->fresh();

        AuditLog::record('login', $user);

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames()->values(),
            'must_change_password' => (bool) $user->must_change_password,
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user,
            'roles' => $user->getRoleNames()->values(),
            'must_change_password' => (bool) $user->must_change_password,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'unique:users,email,' . $user->id,
            ],
        ]);

        $user->update($data);

        return response()->json([
            'user' => $user->fresh(),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => [
                'required',
                'confirmed',
                PasswordRule::min(8),
            ],
        ]);

        $user = $request->user();

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Your new password must be different from your current password.'],
            ]);
        }

        $user->update([
            'password' => $data['password'],
            'must_change_password' => false,
        ]);

        // Changing the password (especially out of the forced-change flow,
        // where the "current" password was a temporary one someone else
        // generated) should not leave other, possibly-unwanted sessions
        // signed in. Keep the session making this request; drop the rest.
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        AuditLog::record('password_changed', $user);

        return response()->json([
            'message' => 'Password updated successfully.',
            'must_change_password' => false,
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // The outbound mail transport (e.g. Resend in a sandbox/testing
        // account) can reject sends to unverified recipients. That is a
        // delivery problem, not a reason to fail the request or leak
        // whether the email exists - always return the same generic
        // message either way.
        try {
            Password::sendResetLink($request->only('email'));
        } catch (\Throwable) {
            // Intentionally swallowed: never surface mail-transport errors
            // (or the existence of the account) to the caller.
        }

        return response()->json([
            'message' => "If an account exists for this email, we've sent a password reset link.",
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => [
                'required',
                'confirmed',
                PasswordRule::min(8),
            ],
        ]);

        $status = Password::reset(
            $credentials,
            function ($user) use ($credentials): void {
                $user->forceFill([
                    'password' => $credentials['password'],
                    'remember_token' => null,
                    'must_change_password' => false,
                ])->save();

                DB::table('sessions')->where('user_id', $user->id)->delete();

                AuditLog::record('password_changed', $user);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'This password reset link is invalid or has expired.',
            ], 422);
        }

        return response()->json([
            'message' => 'Password reset successfully.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        AuditLog::record('logout', $request->user());

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}