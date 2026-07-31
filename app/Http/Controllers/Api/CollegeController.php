<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CollegeResource;
use App\Models\College;
use Illuminate\Http\Request;

class CollegeController extends Controller
{
    public function index(Request $request)
    {
        $colleges = College::with('programs')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%")->orWhere('code', 'like', "%{$request->search}%"))
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Colleges retrieved successfully.',
            'data' => CollegeResource::collection($colleges),
        ], 200);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:colleges,code'],
            'description' => ['nullable', 'string'],
        ]);

        $college = College::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'College created successfully.',
            'data' => new CollegeResource($college),
        ], 201);
    }

    public function show(College $college)
    {
        $college->load('programs');

        return response()->json([
            'success' => true,
            'message' => 'College retrieved successfully.',
            'data' => new CollegeResource($college),
        ], 200);
    }

    public function update(Request $request, College $college)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50', 'unique:colleges,code,' . $college->id],
            'description' => ['nullable', 'string'],
        ]);

        $college->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'College updated successfully.',
            'data' => new CollegeResource($college),
        ], 200);
    }

    public function destroy(College $college)
    {
        $college->delete();

        return response()->json([
            'success' => true,
            'message' => 'College deleted successfully.',
        ], 200);
    }
}
