<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * The name of the staff role an account acts in, for texts that say who decided ("Kesiswaan menolak …", D-139).
 * An account may hold several roles (a Kepala Sekolah is often a guru too): one is picked by PRIORITY.
 * Static and pure: it reads the user's roles and writes nothing.
 */
final class StaffRoleLabel
{
    /** Highest first: the school office an account acts in, before the operator role and the guru role it often keeps. */
    public const PRIORITY = ['kepala_sekolah', 'tata_usaha', 'kesiswaan', 'humas', 'admin', 'guru'];

    /** Used when nobody, or an account without a staff role, decided. */
    public const FALLBACK = 'sekolah';

    private const LABELS = [
        'kepala_sekolah' => 'Kepala Sekolah',
        'tata_usaha' => 'Tata Usaha',
        'kesiswaan' => 'Kesiswaan',
        'humas' => 'Humas',
        'admin' => 'Admin',
        'guru' => 'Guru',
    ];

    /** The label of the highest staff role the account holds, '' when it holds none. */
    public static function of(User $user): string
    {
        $held = $user->getRoleNames();

        foreach (self::PRIORITY as $role) {
            if ($held->contains($role)) {
                return self::LABELS[$role];
            }
        }

        return '';
    }

    /** Who decides a lomba when no account is known: the role that verifies lomba (D-27). */
    public const LOMBA_FALLBACK = 'Kesiswaan';

    /** `of($decider)`, or FALLBACK for null and for an account without a staff role. */
    public static function forDecision(?User $decider): string
    {
        $label = $decider === null ? '' : self::of($decider);

        return $label === '' ? self::FALLBACK : $label;
    }

    /**
     * The one rule for who published or rejected a lomba (FS1 fix wave): the staff role of its verifier (an Admin may
     * decide too), LOMBA_FALLBACK without one (no verifier, a deleted account, an account without a staff role). Every
     * text about that decision uses it: CompetitionPublished, CompetitionRejected, Cari lomba › Usulan saya, Lomba
     * saya, Detail lomba and its Pendaftaran lomba panel, the Guru drawer.
     */
    public static function forLomba(?User $verifier): string
    {
        $label = $verifier === null ? '' : self::of($verifier);

        return $label === '' ? self::LOMBA_FALLBACK : $label;
    }
}
