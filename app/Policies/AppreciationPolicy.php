<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AppreciationStatus;
use App\Enums\Permission;
use App\Models\Appreciation;
use App\Models\User;

/**
 * Apresiasi rows (an achievement on an appreciation event). Deny by default; `appreciation.manage`
 * (Humas, Kesiswaan, Admin) on an account that can log in.
 */
class AppreciationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canLogIn() && $user->can(Permission::AppreciationManage->value);
    }

    public function view(User $user, Appreciation $appreciation): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Appreciation $appreciation): bool
    {
        return $this->viewAny($user);
    }

    /**
     * "Lepas dari acara" (M2-B): the same people who put achievements on events, and only while the row is
     * still scheduled on an event that is not confirmed as held. A confirmed event is the record and stays.
     */
    public function delete(User $user, Appreciation $appreciation): bool
    {
        if (! $this->viewAny($user) || $appreciation->status !== AppreciationStatus::Dijadwalkan) {
            return false;
        }

        // Lists pass the event along; a lone row loads it (no lazy loading under Model::shouldBeStrict()).
        $event = $appreciation->relationLoaded('event') ? $appreciation->event : $appreciation->event()->first();

        return $event !== null && ! $event->isCompleted();
    }
}
