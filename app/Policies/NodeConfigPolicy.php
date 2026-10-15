<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\NodeConfig;
use App\Models\User;
use App\Policies\Concerns\ChecksPanelRole;

class NodeConfigPolicy
{
    use ChecksPanelRole;

    public function viewAny(User $user): bool
    {
        return $this->isViewer($user);
    }

    public function view(User $user, NodeConfig $config): bool
    {
        return $this->isViewer($user);
    }

    public function create(User $user): bool
    {
        return $this->isOperator($user);
    }

    public function update(User $user, NodeConfig $config): bool
    {
        return $this->isManager($user);
    }

    public function delete(User $user, NodeConfig $config): bool
    {
        return $this->isManager($user);
    }

    public function rollback(User $user, NodeConfig $config): bool
    {
        return $this->isOperator($user);
    }
}
