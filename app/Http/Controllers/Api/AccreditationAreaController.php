<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccreditationAreaResource;
use App\Http\Resources\AreaMemberResource;
use App\Models\AccreditationArea;
use App\Models\AreaMember;
use App\Models\AccreditationCycle;
use Illuminate\Http\Request;

class AccreditationAreaController extends Controller
{
    /**
     * Display a paginated list of accreditation areas for a cycle.
     */
    public function index(Request $request)
    {
        $query = AccreditationArea::with('chair', 'members.user');

        if ($request->filled('cycle_id')) {
            $query->where('cycle_id', $request->cycle_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('description', 'like', "%{$request->search}%");
            });
        }

        $areas = $query->orderBy('name')->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Accreditation areas retrieved successfully.',
            'data' => AccreditationAreaResource::collection($areas),
        ], 200);
    }

    /**
     * Store a newly created accreditation area.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'cycle_id' => ['required', 'exists:accreditation_cycles,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'chair_id' => ['nullable', 'exists:users,id'],
            'status' => ['nullable', 'in:' . implode(',', AccreditationArea::STATUSES)],
        ]);

        $area = AccreditationArea::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Accreditation area created successfully.',
            'data' => new AccreditationAreaResource($area->load('chair', 'members.user')),
        ], 201);
    }

    /**
     * Display the specified accreditation area (Area Details).
     */
    public function show(AccreditationArea $accreditationArea)
    {
        $accreditationArea->load('chair', 'members.user', 'cycle.program');

        return response()->json([
            'success' => true,
            'message' => 'Accreditation area retrieved successfully.',
            'data' => new AccreditationAreaResource($accreditationArea),
        ], 200);
    }

    /**
     * Update the specified accreditation area.
     */
    public function update(Request $request, AccreditationArea $accreditationArea)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'chair_id' => ['nullable', 'exists:users,id'],
            'status' => ['nullable', 'in:' . implode(',', AccreditationArea::STATUSES)],
        ]);

        $accreditationArea->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Accreditation area updated successfully.',
            'data' => new AccreditationAreaResource($accreditationArea->load('chair', 'members.user')),
        ], 200);
    }

    /**
     * Remove the specified accreditation area.
     */
    public function destroy(AccreditationArea $accreditationArea)
    {
        $accreditationArea->delete();

        return response()->json([
            'success' => true,
            'message' => 'Accreditation area deleted successfully.',
        ], 200);
    }

    /**
     * Assign a chair to the accreditation area.
     */
    public function assignChair(Request $request, AccreditationArea $accreditationArea)
    {
        $validated = $request->validate([
            'chair_id' => ['required', 'exists:users,id'],
        ]);

        $accreditationArea->update(['chair_id' => $validated['chair_id']]);

        return response()->json([
            'success' => true,
            'message' => 'Chair assigned successfully.',
            'data' => new AccreditationAreaResource($accreditationArea->load('chair', 'members.user')),
        ], 200);
    }

    /**
     * Add a member to the accreditation area.
     */
    public function addMember(Request $request, AccreditationArea $accreditationArea)
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'role' => ['nullable', 'string', 'max:255'],
        ]);

        $member = $accreditationArea->members()->create([
            'user_id' => $validated['user_id'],
            'role' => $validated['role'] ?? 'member',
        ]);

        $member->load('user');

        return response()->json([
            'success' => true,
            'message' => 'Member added successfully.',
            'data' => new AreaMemberResource($member),
        ], 201);
    }

    /**
     * Remove a member from the accreditation area.
     */
    public function removeMember(AccreditationArea $accreditationArea, AreaMember $member)
    {
        if ($member->area_id !== $accreditationArea->id) {
            return response()->json([
                'success' => false,
                'message' => 'Member does not belong to this area.',
            ], 404);
        }

        $member->delete();

        return response()->json([
            'success' => true,
            'message' => 'Member removed successfully.',
        ], 200);
    }

    /**
     * Get the progress of an accreditation area.
     */
    public function progress(AccreditationArea $accreditationArea)
    {
        $accreditationArea->load('chair', 'members.user');

        $totalMembers = $accreditationArea->members->count();
        $status = $accreditationArea->status;

        return response()->json([
            'success' => true,
            'message' => 'Area progress retrieved successfully.',
            'data' => [
                'area' => new AccreditationAreaResource($accreditationArea),
                'progress' => [
                    'status' => $status,
                    'totalMembers' => $totalMembers,
                    'hasChair' => $accreditationArea->chair_id !== null,
                ],
            ],
        ], 200);
    }
}