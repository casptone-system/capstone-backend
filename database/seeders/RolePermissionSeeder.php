<?php

namespace Database\Seeders;

use App\Support\RoleSlug;
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
            'access-college-dashboard',
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
            RoleSlug::SUPERADMIN => [
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
            RoleSlug::VPAA => [
                'view dashboard',
                'approve reviews',
                'review reports',
                'view audit logs',
            ],
            RoleSlug::QA => [
                'view dashboard',
                'review reports',
                'view audit logs',
            ],
            RoleSlug::DEAN => [
                'view dashboard',
                'access-college-dashboard',
                'manage reviews',
                'approve reviews',
                'review reports',
                'manage teams',
                'manage documents',
            ],
            RoleSlug::PROGRAM_CHAIR => [
                'view dashboard',
                'manage teams',
                'invite faculty',
                'assign chairs',
                'review reports',
                'manage reviews',
                'approve reviews',
                'request revisions',
            ],
            RoleSlug::AREA_IN_CHARGE => [
                'view dashboard',
                'manage reviews',
                'request revisions',
                'review reports',
            ],
            RoleSlug::FACULTY => [
                'view dashboard',
                'upload documents',
                'submit reviews',
            ],
            RoleSlug::ACCREDITOR => [
                'view dashboard',
                'review reports',
            ],
        ];

        foreach ($roles as $name => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $role->syncPermissions($rolePermissions);
        }
    }
}
