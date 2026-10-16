<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AppreciationEvent;
use App\Models\User;

/**
 * Appreciation events ("Upacara Senin, 5 Oktober 2026"). Deny by default; `appreciation.manage`
 * (Humas, Kesiswaan, Admin) on an account that can log in. Authorized by permission, not role, because
 * Kesiswaan mounts the same screen (kesiswaan.apresiasi.index).
 */
class AppreciationEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canLogIn() && $user->can(Permission::AppreciationManage->value);
    }

    public function view(User $user, AppreciationEvent $event): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Add achievements to the event, confirm it as held. Permission only: the Actions answer a wrong state
     * (event already confirmed, date not reached) with a message, not with a 403.
     */
    public function update(User $user, AppreciationEvent $event): bool
    {
        return $this->viewAny($user);
    }

    /**
     * "Hapus acara" (M1-E): the same people who create events, and only while the event is not confirmed
     * as held. A confirmed event is the record of who was appreciated and stays.
     */
    public function delete(User $user, AppreciationEvent $event): bool
    {
        return $this->viewAny($user) && ! $event->isCompleted();
    }

    /**
     * "Cetak daftar" (D-141): the PDF of the students of one event who may be named. The same people who see the
     * event; the list itself only holds students that pass `NamedStudent`.
     */
    public function printList(User $user, AppreciationEvent $event): bool
    {
        return $this->viewAny($user);
    }
}
