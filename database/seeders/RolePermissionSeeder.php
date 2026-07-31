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
            'manage reviews',
            'approve reviews',
            'review reports',
            'manage users',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $roles = [
            'Super Admin' => ['view dashboard', 'manage reviews', 'approve reviews', 'review reports', 'manage users'],
            'QA' => ['view dashboard', 'review reports'],
            'VPAA' => ['view dashboard', 'approve reviews', 'review reports'],
            'Dean' => ['view dashboard', 'approve reviews', 'review reports'],
            'Area Chair' => ['view dashboard', 'manage reviews', 'review reports'],
            'Team Member' => ['view dashboard', 'manage reviews'],
            'Accreditor' => ['view dashboard', 'review reports'],
            'faculty' => ['view dashboard'],
        ];

        foreach ($roles as $name => $rolePermissions) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $role->syncPermissions($rolePermissions);
        }
    }
}
