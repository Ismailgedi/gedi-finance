<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
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

        if (
            !$user ||
            !$user->is_active ||
            !Hash::check($credentials['password'], $user->password)
        ) {
            return response()->json(
                ['message' => 'Invalid email or password.'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        Auth::guard('web')->login(
            $user,
            (bool) ($credentials['remember'] ?? false)
        );

        $request->session()->regenerate();

        $user = $user->fresh();

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

        $request->user()->update([
            'password' => $data['password'],
            'must_change_password' => false,
        ]);

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

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if (
            $status !== Password::RESET_LINK_SENT &&
            $status !== Password::RESET_THROTTLED
        ) {
            return response()->json([
                'message' => "If an account exists for this email, we've sent a password reset link.",
            ]);
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
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}