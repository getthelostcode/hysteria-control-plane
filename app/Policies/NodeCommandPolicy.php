<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\NodeCommand;
use App\Models\User;
use App\Policies\Concerns\ChecksPanelRole;

class NodeCommandPolicy
{
    use ChecksPanelRole;

    public function viewAny(User $user): bool
    {
        return $this->isViewer($user);
    }

    public function view(User $user, NodeCommand $command): bool
    {
        return $this->isViewer($user);
    }

    public function create(User $user): bool
    {
        return $this->isOperator($user);
    }

    public function delete(User $user, NodeCommand $command): bool
    {
        return $this->isManager($user);
    }
}
