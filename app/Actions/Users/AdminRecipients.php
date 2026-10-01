<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who receives Admin-area notices: every Admin and every Kepala Sekolah account that can still log in.
 */
final class AdminRecipients
{
    /** @return Collection<int, User> */
    public function admins(): Collection
    {
        return User::query()->role(RoleName::Admin->value)->canLogIn()->get();
    }

    /** @return Collection<int, User> */
    public function principals(): Collection
    {
        return User::query()->role(RoleName::KepalaSekolah->value)->canLogIn()->get();
    }

    /** @return Collection<int, User> */
    public function principalsAndAdmins(): Collection
    {
        return $this->principals()->merge($this->admins())->unique(fn (User $user) => $user->getKey())->values();
    }
}
