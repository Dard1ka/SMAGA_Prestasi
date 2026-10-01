<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CompetitionStatus;
use App\Enums\Permission;
use App\Models\Competition;
use App\Models\User;

/**
 * Lomba (catalog + usulan). Deny by default. Owner of the file: W2-A1 (viewAny, view, create);
 * W2-B adds the Kesiswaan methods (manage, update, verify, close) below the marked line.
 *
 * - The catalog shows only `dipublikasikan` (D-27); an unpublished lomba is visible to its submitter
 *   and to verifiers (competition.verify). Lists use Competition::visibleTo($user).
 * - Every role with competition.create may upload a lomba; it always starts at menunggu_verifikasi.
 */
class CompetitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canLogIn();
    }

    public function view(User $user, Competition $competition): bool
    {
        if (! $user->canLogIn()) {
            return false;
        }

        if ($competition->isPublished()) {
            return true;
        }

        return (int) $competition->submitted_by === (int) $user->getKey()
            || $user->can(Permission::CompetitionVerify->value);
    }

    /** Usulan / upload (D-27): lands in menunggu_verifikasi; rate limit lives in SubmitCompetition. */
    public function create(User $user): bool
    {
        return $user->canLogIn() && $user->can(Permission::CompetitionCreate->value);
    }

    // --- W2-B: Kesiswaan methods (manage, update, verify, close) below ---

    /** Kelola lomba screen (all statuses) and the full "Tambah lomba" form incl. weight fields (D-02). */
    public function manage(User $user): bool
    {
        return $user->canLogIn()
            && ($user->can(Permission::CompetitionVerify->value) || $user->can(Permission::CompetitionUpdate->value));
    }

    /** Edit catalog data incl. tingkat/kategori/status ajang (audited), while queued or published. */
    public function update(User $user, Competition $competition): bool
    {
        return $user->canLogIn()
            && $user->can(Permission::CompetitionUpdate->value)
            && ! $competition->trashed()
            && in_array($competition->status, [CompetitionStatus::MenungguVerifikasi, CompetitionStatus::Dipublikasikan], true);
    }

    /** Publish or reject a queued upload from any role, Kesiswaan's own included (D-27). */
    public function verify(User $user, Competition $competition): bool
    {
        return $user->canLogIn()
            && $user->can(Permission::CompetitionVerify->value)
            && ! $competition->trashed()
            && $competition->status === CompetitionStatus::MenungguVerifikasi;
    }

    /** Take a published lomba out of the catalog. */
    public function close(User $user, Competition $competition): bool
    {
        return $user->canLogIn()
            && $user->can(Permission::CompetitionClose->value)
            && ! $competition->trashed()
            && $competition->status === CompetitionStatus::Dipublikasikan;
    }
}
