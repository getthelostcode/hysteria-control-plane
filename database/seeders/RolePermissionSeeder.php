<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'view nodes', 'manage nodes', 'issue commands', 'publish configs',
            'manage tokens', 'manage upgrades', 'view audit', 'manage users',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $matrix = [
            'super-admin' => $permissions,
            'admin' => $permissions,
            'operator' => ['view nodes', 'manage nodes', 'issue commands', 'publish configs', 'manage tokens', 'view audit'],
            'viewer' => ['view nodes', 'view audit'],
        ];

        foreach ($matrix as $role => $granted) {
            $model = Role::findOrCreate($role, 'web');
            $model->syncPermissions($granted);
        }

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Control Plane Admin', 'password' => Hash::make('password')]
        );

        $admin->syncRoles([Role::findByName('super-admin', 'web')]);

        $operator = User::query()->firstOrCreate(
            ['email' => 'operator@example.com'],
            ['name' => 'Ops Operator', 'password' => Hash::make('password')]
        );

        $operator->syncRoles([Role::findByName('operator', 'web')]);

        $this->command?->info('Roles, permissions and panel users are ready (admin@example.com / password).');
    }
}
