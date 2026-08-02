<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($validated)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user = Auth::user();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Authenticated successfully.',
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
        ], 200);
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['required_without:name', 'string', 'max:255'],
            'last_name' => ['required_without:name', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['sometimes', 'string'],
        ]);

        if (! isset($validated['first_name']) || ! isset($validated['last_name'])) {
            $nameParts = preg_split('/\s+/', trim($validated['name']));
            $firstName = $nameParts[0] ?? '';
            $lastName = count($nameParts) > 1 ? array_pop($nameParts) : $firstName;
            $middleName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1, -1)) : null;
        } else {
            $firstName = $validated['first_name'];
            $lastName = $validated['last_name'];
            $middleName = $validated['middle_name'] ?? null;
        }

        $user = User::create([
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        // Assign role if provided, otherwise default to 'faculty'
        $role = $validated['role'] ?? 'faculty';
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($role);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registration successful.',
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
        ], 201);
    }

    public function logout(Request $request)
    {
        $request->user('api')?->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ], 200);
    }

    public function me(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => new UserResource($request->user('api')),
        ], 200);
    }
}
