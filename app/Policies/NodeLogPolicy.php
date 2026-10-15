<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\NodeLog;
use App\Models\User;
use App\Policies\Concerns\ChecksPanelRole;

class NodeLogPolicy
{
    use ChecksPanelRole;

    public function viewAny(User $user): bool
    {
        return $this->isViewer($user);
    }

    public function view(User $user, NodeLog $log): bool
    {
        return $this->isViewer($user);
    }
}
