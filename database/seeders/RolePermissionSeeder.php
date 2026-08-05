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
                'approve reviews',
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

        foreach ($roles as $name => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $role->syncPermissions($rolePermissions);
        }
    }
}
