<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user('api') ?? $request->user();

        if (! $user || ! $user->hasRole('Super Administrator')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view users.',
            ], 403);
        }

        $users = User::latest()->paginate(50);

        return response()->json([
            'success' => true,
            'data' => [
                'users' => UserResource::collection($users),
                'meta' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                ],
            ],
        ]);
    }
}
