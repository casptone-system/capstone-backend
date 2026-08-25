<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CollegeResource;
use App\Models\College;
use App\Models\User;
use App\Support\RoleSlug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class CollegeController extends Controller
{
    protected function ensureSuperAdmin(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        if (! $user || ! $user->isSuperAdmin()) {
            return null;
        }

        return $user;
    }

    public function index(Request $request)
    {
        $user = $request->user('api') ?? $request->user();
        if (! $user || ! ($user->isSuperAdmin() || $user->isQA() || $user->isVPAA())) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to view colleges.'], 403);
        }

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
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to create colleges.'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:colleges,code'],
            'campus' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);
        $validated['campus'] = $validated['campus'] ?? config('institution.campus');

        $deanPayload = $request->input('dean');

        $result = DB::transaction(function () use ($validated, $deanPayload, $actor) {
            $college = College::create($validated);

            $createdDean = null;

            if (is_array($deanPayload) && ! empty($deanPayload['email'])) {
                $email = $deanPayload['email'];
                $name = $deanPayload['name'] ?? null;

                $duplicateDean = User::where('college_id', $college->id)
                    ->whereHas('roles', fn ($q) => $q->where('name', RoleSlug::DEAN))
                    ->exists();
                if ($duplicateDean) {
                    abort(422, 'This college already has an active Dean.');
                }

                $password = Str::random(12);
                $user = User::create([
                    'name' => $name ?? $email,
                    'email' => $email,
                    'password' => Hash::make($password),
                    'college_id' => $college->id,
                ]);

                $user->assignRole(RoleSlug::DEAN);
                $createdDean = $user;
            }

            return ['college' => $college, 'dean' => $createdDean];
        });

        $college = $result['college'];

        $response = ['success' => true, 'message' => 'College created successfully.', 'data' => new CollegeResource($college)];
        if ($result['dean']) {
            $response['dean'] = ['id' => $result['dean']->id, 'email' => $result['dean']->email, 'name' => $result['dean']->name];
            \App\Models\AuditLog::create([
                'user_id' => $actor->id,
                'user_email' => $actor->email,
                'event' => 'CREATE_DEAN',
                'method' => 'POST',
                'path' => "api/colleges/{$college->id}",
                'status' => 'success',
                'ip_address' => request()->ip(),
            ]);
        }
        return response()->json($response, 201);
    }

    public function show(Request $request, College $college)
    {
        $user = $request->user('api') ?? $request->user();
        if (! $user || ! ($user->isSuperAdmin() || $user->isQA() || $user->isVPAA())) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to view this college.'], 403);
        }

        $college->load('programs');

        return response()->json([
            'success' => true,
            'message' => 'College retrieved successfully.',
            'data' => new CollegeResource($college),
        ], 200);
    }

    public function update(Request $request, College $college)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to update this college.'], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50', 'unique:colleges,code,' . $college->id],
            'campus' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $college->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'College updated successfully.',
            'data' => new CollegeResource($college),
        ], 200);
    }

    public function destroy(Request $request, College $college)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to delete this college.'], 403);
        }

        $college->delete();

        return response()->json([
            'success' => true,
            'message' => 'College deleted successfully.',
        ], 200);
    }
}
