<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\UpgradePackage;
use App\Models\User;
use App\Policies\Concerns\ChecksPanelRole;

class UpgradePackagePolicy
{
    use ChecksPanelRole;

    public function viewAny(User $user): bool
    {
        return $this->isViewer($user);
    }

    public function create(User $user): bool
    {
        return $this->isManager($user);
    }

    public function update(User $user, UpgradePackage $package): bool
    {
        return $this->isManager($user);
    }

    public function delete(User $user, UpgradePackage $package): bool
    {
        return $this->isManager($user);
    }
}
