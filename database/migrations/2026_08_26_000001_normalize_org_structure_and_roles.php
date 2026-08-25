<?php

use App\Support\RoleSlug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $this->normalizeSpatieRoles();
        $this->backfillCanonicalMemberships();
        $this->addChairUniqueness();
    }

    public function down(): void
    {
        if (Schema::hasTable('programs') && Schema::hasColumn('programs', 'chair_id')) {
            Schema::table('programs', function (Blueprint $table) {
                try {
                    $table->dropUnique('programs_chair_id_unique');
                } catch (Throwable) {
                    // Index may not exist on some environments.
                }
            });
        }
    }

    private function normalizeSpatieRoles(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
            return;
        }

        $aliasToCanonical = [
            'Super Administrator' => RoleSlug::SUPERADMIN,
            'Super Admin' => RoleSlug::SUPERADMIN,
            'super-admin' => RoleSlug::SUPERADMIN,
            'superadmin' => RoleSlug::SUPERADMIN,
            'QA' => RoleSlug::QA,
            'qa' => RoleSlug::QA,
            'VPAA' => RoleSlug::VPAA,
            'vpaa' => RoleSlug::VPAA,
            'vpaa-di' => RoleSlug::VPAA,
            'vpaa/di' => RoleSlug::VPAA,
            'Dean' => RoleSlug::DEAN,
            'dean' => RoleSlug::DEAN,
            'Program Chair' => RoleSlug::PROGRAM_CHAIR,
            'program-chair' => RoleSlug::PROGRAM_CHAIR,
            'ProgramChair' => RoleSlug::PROGRAM_CHAIR,
            'Area Chair' => RoleSlug::AREA_IN_CHARGE,
            'Area In-Charge' => RoleSlug::AREA_IN_CHARGE,
            'area-in-charge' => RoleSlug::AREA_IN_CHARGE,
            'area-incharge' => RoleSlug::AREA_IN_CHARGE,
            'Faculty' => RoleSlug::FACULTY,
            'faculty' => RoleSlug::FACULTY,
            'Accreditor' => RoleSlug::ACCREDITOR,
            'accreditor' => RoleSlug::ACCREDITOR,
        ];

        foreach (RoleSlug::ALL as $slug) {
            $existing = DB::table('roles')->where('guard_name', 'web')->get()
                ->first(fn ($role) => $role->name === $slug);
            if (! $existing) {
                DB::table('roles')->insert([
                    'name' => $slug,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $rolesByName = DB::table('roles')->where('guard_name', 'web')->get()
            ->filter(fn ($role) => is_string($role->name))
            ->keyBy('name');

        foreach ($aliasToCanonical as $legacyName => $canonical) {
            if ($legacyName === $canonical) {
                continue;
            }

            $legacy = $rolesByName->get($legacyName);
            $canonicalRole = $rolesByName->get($canonical);
            if (! $canonicalRole) {
                $canonicalRole = DB::table('roles')->where('guard_name', 'web')->get()
                    ->first(fn ($role) => $role->name === $canonical);
            }
            if (! $legacy || ! $canonicalRole || (int) $legacy->id === (int) $canonicalRole->id) {
                continue;
            }

            $assignments = DB::table('model_has_roles')
                ->where('role_id', $legacy->id)
                ->get();

            foreach ($assignments as $assignment) {
                $alreadyHasCanonical = DB::table('model_has_roles')
                    ->where('role_id', $canonicalRole->id)
                    ->where('model_type', $assignment->model_type)
                    ->where('model_id', $assignment->model_id)
                    ->exists();

                if (! $alreadyHasCanonical) {
                    DB::table('model_has_roles')->insert([
                        'role_id' => $canonicalRole->id,
                        'model_type' => $assignment->model_type,
                        'model_id' => $assignment->model_id,
                    ]);
                }

                DB::table('model_has_roles')
                    ->where('role_id', $legacy->id)
                    ->where('model_type', $assignment->model_type)
                    ->where('model_id', $assignment->model_id)
                    ->delete();
            }

            if (Schema::hasTable('role_has_permissions')) {
                $legacyPerms = DB::table('role_has_permissions')->where('role_id', $legacy->id)->pluck('permission_id');
                foreach ($legacyPerms as $permissionId) {
                    $exists = DB::table('role_has_permissions')
                        ->where('role_id', $canonicalRole->id)
                        ->where('permission_id', $permissionId)
                        ->exists();
                    if (! $exists) {
                        DB::table('role_has_permissions')->insert([
                            'permission_id' => $permissionId,
                            'role_id' => $canonicalRole->id,
                        ]);
                    }
                }
                DB::table('role_has_permissions')->where('role_id', $legacy->id)->delete();
            }

            DB::table('roles')->where('id', $legacy->id)->delete();
            $rolesByName->forget($legacyName);
        }

        $keep = RoleSlug::ALL;
        DB::table('roles')
            ->where('guard_name', 'web')
            ->whereNotIn('name', $keep)
            ->orderBy('id')
            ->get()
            ->each(function ($role) {
                $assigned = DB::table('model_has_roles')->where('role_id', $role->id)->exists();
                if (! $assigned) {
                    if (Schema::hasTable('role_has_permissions')) {
                        DB::table('role_has_permissions')->where('role_id', $role->id)->delete();
                    }
                    DB::table('roles')->where('id', $role->id)->delete();
                }
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function backfillCanonicalMemberships(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('programs')) {
            return;
        }

        // Chairs: programs.chair_id is canonical; keep users.program_id in sync when empty.
        DB::table('programs')
            ->whereNotNull('chair_id')
            ->orderBy('id')
            ->get(['id', 'chair_id', 'college_id'])
            ->each(function ($program) {
                DB::table('users')
                    ->where('id', $program->chair_id)
                    ->whereNull('program_id')
                    ->update([
                        'program_id' => $program->id,
                        'college_id' => DB::raw('COALESCE(college_id, '.$program->college_id.')'),
                    ]);
            });

        $institutionWideRoleIds = DB::table('roles')
            ->where('guard_name', 'web')
            ->get()
            ->filter(fn ($role) => in_array($role->name, RoleSlug::INSTITUTION_WIDE, true))
            ->pluck('id');

        if ($institutionWideRoleIds->isNotEmpty()) {
            $userIds = DB::table('model_has_roles')
                ->whereIn('role_id', $institutionWideRoleIds)
                ->where('model_type', 'App\\Models\\User')
                ->pluck('model_id');

            if ($userIds->isNotEmpty()) {
                DB::table('users')
                    ->whereIn('id', $userIds)
                    ->update([
                        'college_id' => null,
                        'program_id' => null,
                        'team_id' => null,
                    ]);
            }
        }

        // Legacy faker chair strings are not real users. Keep chair_id null and
        // stop treating the string as assigned-chair display data.
        DB::table('programs')
            ->whereNull('chair_id')
            ->whereNotNull('chair')
            ->update(['chair' => null]);
    }

    private function addChairUniqueness(): void
    {
        if (! Schema::hasTable('programs') || ! Schema::hasColumn('programs', 'chair_id')) {
            return;
        }

        $duplicates = DB::table('programs')
            ->whereNotNull('chair_id')
            ->select('chair_id', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('chair_id')
            ->having('aggregate', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add unique programs.chair_id: a user already chairs more than one program. Backfill/cleanup is required first.'
            );
        }

        $sm = Schema::getConnection()->getSchemaBuilder();
        $indexes = method_exists($sm, 'getIndexes') ? $sm->getIndexes('programs') : [];
        $indexExists = collect($indexes)->contains(fn ($index) => ($index['name'] ?? '') === 'programs_chair_id_unique');

        if (! $indexExists) {
            Schema::table('programs', function (Blueprint $table) {
                $table->unique('chair_id', 'programs_chair_id_unique');
            });
        }
    }
};
