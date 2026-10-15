<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EnrollToken;
use App\Models\User;
use App\Policies\Concerns\ChecksPanelRole;

class EnrollTokenPolicy
{
    use ChecksPanelRole;

    public function viewAny(User $user): bool
    {
        return $this->isViewer($user);
    }

    public function view(User $user, EnrollToken $token): bool
    {
        return $this->isViewer($user);
    }

    public function create(User $user): bool
    {
        return $this->isOperator($user);
    }

    public function delete(User $user, EnrollToken $token): bool
    {
        return $this->isManager($user);
    }
}
