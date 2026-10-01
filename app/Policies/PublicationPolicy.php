<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;

/**
 * Humas publication queue (UI-MAP #22). Deny by default. Everything needs `publication.manage` on an
 * account that can log in. Changing a publication additionally needs a verified achievement whose
 * student gave publish consent (D-16, SECURITY §3m-7); without consent the row stays out of the queue.
 */
class PublicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canLogIn() && $user->can(Permission::PublicationManage->value);
    }

    public function view(User $user, Publication $publication): bool
    {
        return $this->viewAny($user);
    }

    /** Edit caption/channels (not after publication). */
    public function update(User $user, Publication $publication): bool
    {
        return $this->viewAny($user)
            && self::eligible($publication)
            && $publication->status !== PublicationStatus::Dipublikasikan;
    }

    /** Schedule or re-schedule (siap / terjadwal). */
    public function schedule(User $user, Publication $publication): bool
    {
        return $this->update($user, $publication);
    }

    public function publish(User $user, Publication $publication): bool
    {
        return $this->viewAny($user)
            && self::eligible($publication)
            && in_array($publication->status, [PublicationStatus::Siap, PublicationStatus::Terjadwal], true);
    }

    public function generateFlyer(User $user, Publication $publication): bool
    {
        return $this->viewAny($user) && self::eligible($publication);
    }

    /** Verified achievement + student publish consent. */
    public static function eligible(Publication $publication): bool
    {
        $publication->loadMissing('achievement.student');
        $achievement = $publication->achievement;

        return $achievement !== null
            && $achievement->verified_at !== null
            && $achievement->student !== null
            && $achievement->student->publish_consent === true;
    }
}
