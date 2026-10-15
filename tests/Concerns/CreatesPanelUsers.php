<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

trait CreatesPanelUsers
{
    protected function adminUser(): User
    {
        return $this->panelUser('admin');
    }

    protected function superAdmin(): User
    {
        return $this->panelUser('super-admin');
    }

    protected function operatorUser(): User
    {
        return $this->panelUser('operator');
    }

    protected function viewerUser(): User
    {
        return $this->panelUser('viewer');
    }

    protected function panelUser(string $role): User
    {
        foreach ([
            'view nodes', 'manage nodes', 'issue commands', 'publish configs',
            'manage tokens', 'manage upgrades', 'view audit', 'manage users',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $matrix = [
            'super-admin' => 'all',
            'admin' => 'all',
            'operator' => ['view nodes', 'manage nodes', 'issue commands', 'publish configs', 'manage tokens', 'view audit'],
            'viewer' => ['view nodes', 'view audit'],
        ];

        $model = Role::findOrCreate($role, 'web');
        $model->syncPermissions($matrix[$role] === 'all' ? Permission::all() : $matrix[$role]);

        $user = User::query()->create([
            'name' => ucfirst($role) . ' User',
            'email' => $role . '-' . uniqid() . '@example.com',
            'password' => Hash::make('password'),
        ]);

        $user->assignRole($model);

        return $user;
    }
}
