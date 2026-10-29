<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * POST /api/forgot-password  { identifier }   Student ID, Faculty ID or email
 * POST /api/reset-password   { email, token, password, password_confirmation }
 */
class PasswordResetController extends Controller
{
    // Same answer whether or not the account exists, so this cannot be used to find accounts.
    private const GENERIC_MESSAGE = 'If an account matches, a password reset link has been sent to its email address.';

    public function forgot(Request $request)
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        $identifier = trim($validated['identifier']);

        if (str_contains($identifier, '@')) {
            $identifier = Str::lower($identifier);
        }

        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('student_number', $identifier)
            ->orWhere('faculty_id', $identifier)
            ->first();

        if ($user) {
            Password::sendResetLink(['email' => $user->email]);
        }

        return response()->json(['message' => self::GENERIC_MESSAGE]);
    }

    public function reset(Request $request)
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', PasswordRule::min(8)->numbers(), 'confirmed'],
        ]);

        $status = Password::reset(
            $validated,
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();

                // Sign the account out everywhere: old tokens must not survive a password reset.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'This reset link is invalid or has expired. Please request a new one.',
            ], 422);
        }

        return response()->json([
            'message' => 'Your password has been changed. You can sign in now.',
        ]);
    }
}
