<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(['student', 'teacher'])],
            'student_number' => ['nullable', 'string', 'max:50', 'unique:users,student_number', 'required_if:role,student'],
            'faculty_id' => ['nullable', 'string', 'max:50', 'unique:users,faculty_id', 'required_if:role,teacher'],
            'department' => ['nullable', 'string', 'max:255'],
            'year_level' => ['nullable', 'string', 'max:50'],
        ]);

        if ($validated['role'] === 'student') {
            $validated['faculty_id'] = null;
        } else {
            $validated['student_number'] = null;
        }

        $user = User::create($validated);

        return response()->json([
            'message' => 'Account created successfully.',
            'user' => $user,
            'token' => $user->createToken('reviewerhub')->plainTextToken,
        ], 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
            'role' => ['required', Rule::in(['student', 'teacher'])],
        ]);

        $identifier = trim($validated['identifier']);
        $role = $validated['role'];

        $user = User::query()
            ->where('role', $role)
            ->where(function ($query) use ($identifier, $role) {
                $query->where('email', $identifier);

                if ($role === 'student') {
                    $query->orWhere('student_number', $identifier);
                } else {
                    $query->orWhere('faculty_id', $identifier);
                }
            })
            ->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'The provided credentials are incorrect.',
            ], 401);
        }

        return response()->json([
            'message' => 'Login successful.',
            'user' => $user,
            'token' => $user->createToken('reviewerhub')->plainTextToken,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
