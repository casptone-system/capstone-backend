<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['data' => Team::with('program')->paginate(20)]);
    }

    public function store(Request $request)
    {
        $user = $request->user('api');

        if (! $user || (! $user->hasRole('Program Chair') && ! $user->hasRole('Super Administrator') && ! $user->can('manage teams'))) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'program_id' => ['required', 'exists:programs,id'],
            'expires_at' => ['nullable', 'date'],
        ]);

        // generate a unique 6-character code
        do {
            $code = strtoupper(Str::random(6));
        } while (Team::where('code', $code)->exists());

        $team = Team::create([
            'name' => $validated['name'],
            'program_id' => $validated['program_id'],
            'code' => $code,
            'created_by' => $user->id,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => $team,
        ], 201);
    }

    public function show(Team $team)
    {
        return response()->json(['data' => $team->load('program')]);
    }
}
