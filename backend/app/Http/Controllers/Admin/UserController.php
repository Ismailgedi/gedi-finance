<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\TemporaryPasswordNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::with('roles')
            ->orderBy('name')
            ->get()
            ->map(function (User $user): array {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_active' => $user->is_active,
                    'roles' => $user->getRoleNames()->values(),
                    'created_at' => $user->created_at,
                ];
            });

        return response()->json(['users' => $users]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', 'exists:roles,name'],
        ]);

        $temporaryPassword = Str::password(16);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($temporaryPassword),
                'must_change_password' => true,
            'is_active' => true,
        ]);

        $user->assignRole($validated['role']);

        AuditLog::record('user_created', $user, null, [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $validated['role'],
        ]);

        return response()->json([
            'message' => 'User created successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'roles' => $user->getRoleNames()->values(),
            ],
            'temporary_password' => $temporaryPassword,
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                'unique:users,email,' . $user->id,
            ],
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['sometimes', 'string', 'exists:roles,name'],
        ]);

        $actingUser = $request->user();
        $isSelf = $actingUser && $actingUser->id === $user->id;

        // A Super Admin acting on their own account could otherwise lock
        // themselves (and potentially everyone else) out: disabling
        // yourself, or demoting yourself away from Super Admin, with no
        // one else signed in to undo it. The frontend already disables
        // these controls for your own row; this is the real boundary.
        if ($isSelf && array_key_exists('is_active', $validated) && !$validated['is_active']) {
            throw ValidationException::withMessages([
                'is_active' => ['You cannot disable your own account.'],
            ]);
        }

        if ($isSelf && array_key_exists('role', $validated) && $validated['role'] !== 'Super Admin') {
            throw ValidationException::withMessages([
                'role' => ['You cannot change your own role.'],
            ]);
        }

        $wasActive = $user->is_active;
        $oldValues = $user->only(['name', 'email', 'is_active']);

        if (array_key_exists('name', $validated)) {
            $user->name = $validated['name'];
        }

        if (array_key_exists('email', $validated)) {
            $user->email = $validated['email'];
        }

        if (array_key_exists('is_active', $validated)) {
            $user->is_active = $validated['is_active'];
        }

        $user->save();

        if (array_key_exists('role', $validated)) {
            $user->syncRoles([$validated['role']]);
        }

        if (array_key_exists('is_active', $validated) && $wasActive && !$validated['is_active']) {
            // Disabling an account should end any session it already has
            // open, not just block future logins.
            DB::table('sessions')->where('user_id', $user->id)->delete();
            AuditLog::record('user_disabled', $user, $oldValues, $user->only(['name', 'email', 'is_active']));
        } elseif (array_key_exists('is_active', $validated) && !$wasActive && $validated['is_active']) {
            AuditLog::record('user_enabled', $user, $oldValues, $user->only(['name', 'email', 'is_active']));
        } else {
            AuditLog::record('user_updated', $user, $oldValues, $user->only(['name', 'email', 'is_active']));
        }

        return response()->json(['message' => 'User updated successfully.']);
    }

    public function resetPassword(User $user): JsonResponse
    {
        $temporaryPassword = Str::password(16);

        $user->update([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
        ]);

        // A freshly-issued temporary password should invalidate whatever
        // session(s) the account already has open under the old password.
        DB::table('sessions')->where('user_id', $user->id)->delete();

        AuditLog::record('password_reset_by_admin', $user);

        $this->tryEmailTemporaryPassword($user, $temporaryPassword);

        return response()->json([
            'message' => 'Password reset successfully.',
            'temporary_password' => $temporaryPassword,
        ]);
    }

    /**
     * Best-effort email of the temporary password. The modal + JSON
     * response above is the primary, always-on channel (and the only one
     * during development, where MAIL_MAILER=log). This is purely a
     * production nicety once a real mailer (e.g. Resend with a verified
     * domain) is configured, and must never be able to fail the request
     * or write the password anywhere - a sandbox mail provider can reject
     * sends to unverified recipients, and that is a delivery detail, not
     * an application error.
     */
    private function tryEmailTemporaryPassword(User $user, string $temporaryPassword): void
    {
        if (config('mail.default') === 'log') {
            return;
        }

        $loginUrl = rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/') . '/login';

        try {
            $user->notify(new TemporaryPasswordNotification($temporaryPassword, $loginUrl));
        } catch (\Throwable $exception) {
            Log::warning('Failed to email temporary password to user.', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
