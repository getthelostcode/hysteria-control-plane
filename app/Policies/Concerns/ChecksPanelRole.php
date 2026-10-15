<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;

trait ChecksPanelRole
{
    /** super-admin / admin may do anything (Gate::before handles it too). */
    protected function isManager(User $user): bool
    {
        return $user->hasAnyRole(['super-admin', 'admin']);
    }

    /** Operators can act on live nodes but never delete or publish blindly. */
    protected function isOperator(User $user): bool
    {
        return $this->isManager($user) || $user->hasRole('operator');
    }

    protected function isViewer(User $user): bool
    {
        return $this->isOperator($user) || $user->hasRole('viewer') || $user->can('view nodes');
    }
}
