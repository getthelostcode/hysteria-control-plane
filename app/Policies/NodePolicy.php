<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Node;
use App\Models\User;
use App\Policies\Concerns\ChecksPanelRole;

class NodePolicy
{
    use ChecksPanelRole;

    public function viewAny(User $user): bool
    {
        return $this->isViewer($user);
    }

    public function view(User $user, Node $node): bool
    {
        return $this->isViewer($user);
    }

    public function create(User $user): bool
    {
        return $this->isOperator($user);
    }

    public function update(User $user, Node $node): bool
    {
        return $this->isOperator($user);
    }

    public function delete(User $user, Node $node): bool
    {
        return $this->isManager($user);
    }

    public function issueCommand(User $user, Node $node): bool
    {
        return $this->isOperator($user);
    }

    public function publishConfig(User $user, Node $node): bool
    {
        return $this->isOperator($user);
    }

    public function rotateSecret(User $user, Node $node): bool
    {
        return $this->isManager($user);
    }
}
