<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProgramResource;
use App\Models\Program;
use Illuminate\Http\Request;

class ProgramController extends Controller
{
    /**
     * Display a paginated list of programs.
     */
    public function index(Request $request)
    {
        $query = Program::with('college');

        if ($request->filled('college_id')) {
            $query->where('college_id', $request->college_id);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('code', 'like', "%{$request->search}%");
            });
        }

        $programs = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Programs retrieved successfully.',
            'data' => ProgramResource::collection($programs),
        ], 200);
    }

    /**
     * Store a newly created program.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'college_id' => ['required', 'exists:colleges,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:programs,code'],
            'chair' => ['nullable', 'string', 'max:255'],
            'accreditation_status' => ['nullable', 'in:compliant,at-risk,non-compliant'],
            'compliance_score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $program = Program::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Program created successfully.',
            'data' => new ProgramResource($program),
        ], 201);
    }

    /**
     * Display the specified program.
     */
    public function show(Program $program)
    {
        $program->load('college');

        return response()->json([
            'success' => true,
            'message' => 'Program retrieved successfully.',
            'data' => new ProgramResource($program),
        ], 200);
    }

    /**
     * Update the specified program.
     */
    public function update(Request $request, Program $program)
    {
        $validated = $request->validate([
            'college_id' => ['sometimes', 'required', 'exists:colleges,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50', 'unique:programs,code,' . $program->id],
            'chair' => ['nullable', 'string', 'max:255'],
            'accreditation_status' => ['nullable', 'in:compliant,at-risk,non-compliant'],
            'compliance_score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $program->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Program updated successfully.',
            'data' => new ProgramResource($program),
        ], 200);
    }

    /**
     * Remove the specified program.
     */
    public function destroy(Program $program)
    {
        $program->delete();

        return response()->json([
            'success' => true,
            'message' => 'Program deleted successfully.',
        ], 200);
    }
}
