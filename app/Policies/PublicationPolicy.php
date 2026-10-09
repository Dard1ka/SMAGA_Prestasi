<?php

declare(strict_types=1);

namespace App\Policies;

use App\Actions\Publications\NamedStudent;
use App\Enums\Permission;
use App\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;

/**
 * Humas publication queue (UI-MAP #22). Deny by default. Everything needs `publication.manage` on an
 * account that can log in. Changing a publication additionally needs a verified achievement whose
 * student may be named (publish consent, D-16, SECURITY §3m-7; since QA10 also not deleted and not disabled,
 * NamedStudent); otherwise the row stays out of the queue.
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

    /**
     * Verified achievement + a student who may be named (QA10: the "named" rule of the public pages, NamedStudent:
     * publish consent, student and account not deleted, account not disabled; an alumnus stays named).
     */
    public static function eligible(Publication $publication): bool
    {
        $publication->loadMissing('achievement.student.user');
        $achievement = $publication->achievement;

        return $achievement !== null
            && $achievement->verified_at !== null
            && NamedStudent::allows($achievement->student);
    }
}
