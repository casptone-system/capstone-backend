<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
            'password' => ['required', 'string', 'min:8'],
            'password_confirmation' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:255'],
            'birthdate' => ['nullable', 'date'],
            'role' => ['sometimes', 'string'],
        ]);

        if ($request->filled('password_confirmation') && $request->input('password') !== $request->input('password_confirmation')) {
            throw ValidationException::withMessages([
                'password' => ['The password field confirmation does not match.'],
            ]);
        }

        // Determine name parts
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

        // Create the user record
        $user = User::create([
            'name' => trim($validated['name'] ?? trim($firstName . ($middleName ? ' ' . $middleName : '') . ' ' . $lastName)),
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'phone_number' => $validated['phone'] ?? null,
            'birth_date' => $validated['birthdate'] ?? null,
        ]);

        // Role assignment rules:
        // - If an authenticated Super Administrator creates the account and provides a role, allow it.
        // - Otherwise default to 'faculty'. Public registrations cannot assign elevated roles.
        $creator = $request->user('api');
        $role = 'faculty';

        if (! empty($validated['role']) && $creator && $creator->hasRole('Super Administrator')) {
            $role = $validated['role'];
        }

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

    public function joinTeam(Request $request)
    {
        $user = $request->user('api');

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Please sign in first.',
            ], 401);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        // First try to resolve to a Team by code
        $code = strtoupper($validated['code']);

        $team = \App\Models\Team::where('code', $code)->first();

        if ($team) {
            // Assign team and program to user
            $user->team_id = $team->id;
            $user->program_id = $team->program_id;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Joined team successfully.',
                'data' => [
                    'code' => $code,
                    'joined' => true,
                    'team' => [
                        'id' => $team->id,
                        'name' => $team->name,
                        'code' => $team->code,
                        'program_id' => $team->program_id,
                    ],
                    'user' => new UserResource($user),
                ],
            ], 200);
        }

        // Fallback: try Program by code
        $program = \App\Models\Program::where('code', $code)->first();

        if ($program) {
            $user->program_id = $program->id;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Joined program successfully.',
                'data' => [
                    'code' => $code,
                    'joined' => true,
                    'program' => [
                        'id' => $program->id,
                        'name' => $program->name,
                        'code' => $program->code,
                    ],
                    'user' => new UserResource($user),
                ],
            ], 200);
        }

        return response()->json([
            'success' => false,
            'message' => 'Invalid invitation code. Please check with your Program Chair or Dean.',
        ], 404);
    }
}
