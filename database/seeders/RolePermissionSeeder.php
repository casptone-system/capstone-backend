<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view dashboard',
            'manage users',
            'manage teams',
            'invite faculty',
            'assign chairs',
            'upload documents',
            'replace documents',
            'manage documents',
            'submit reviews',
            'manage reviews',
            'approve reviews',
            'request revisions',
            'review reports',
            'view audit logs',
            'view login history',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $roles = [
            // Preserve existing human-readable roles but ensure canonical slugs exist
            'Super Administrator' => [
                'view dashboard',
                'manage users',
                'manage teams',
                'invite faculty',
                'assign chairs',
                'manage documents',
                'submit reviews',
                'manage reviews',
                'approve reviews',
                'request revisions',
                'review reports',
                'view audit logs',
                'view login history',
            ],
            'VPAA' => [
                'view dashboard',
                'approve reviews',
                'review reports',
                'view audit logs',
            ],
            'QA' => [
                'view dashboard',
                'review reports',
                'view audit logs',
            ],
            'Dean' => [
                'view dashboard',
                'approve reviews',
                'review reports',
            ],
            'Program Chair' => [
                'view dashboard',
                'manage teams',
                'invite faculty',
                'assign chairs',
                'review reports',
                'manage reviews',
                'request revisions',
            ],
            'Area In-Charge' => [
                'view dashboard',
                'manage reviews',
                'request revisions',
                'review reports',
            ],
            'Faculty' => [
                'view dashboard',
                'upload documents',
                'submit reviews',
            ],
            'Accreditor' => [
                'view dashboard',
                'review reports',
            ],
        ];

        // Define canonical slug overrides for roles we want normalized specially
        $canonicalMap = [
            'Super Administrator' => 'superadmin',
            'VPAA' => 'vpaa',
            'Program Chair' => 'program-chair',
            'Area In-Charge' => 'area-in-charge',
            'Accreditor' => 'accreditor',
            'Faculty' => 'faculty',
            'Dean' => 'dean',
            'QA' => 'qa',
        ];

        foreach ($roles as $name => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $role->syncPermissions($rolePermissions);

            // Compute canonical slug (use overrides where appropriate)
            if (isset($canonicalMap[$name])) {
                $slug = $canonicalMap[$name];
            } else {
                $slug = strtolower(str_replace([' ', '_'], '-', $name));
                $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
            }

            if ($slug !== $name) {
                $canonical = Role::firstOrCreate(['name' => $slug, 'guard_name' => 'web']);
                // Merge permissions: ensure canonical has at least the intended permissions
                $canonicalPerms = $canonical->permissions->pluck('name')->toArray();
                $toAssign = array_values(array_unique(array_merge($canonicalPerms, $rolePermissions)));
                $canonical->syncPermissions($toAssign);
            }
        }
    }
}
