<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Permission;
use App\Enums\PublicationStatus;
use App\Livewire\Humas\HumasQueries;
use App\Models\Competition;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\Report;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Support\ServiceProvider;

/**
 * Sidebar counts on every page (M2-C, QA Q1-05).
 *
 * Until now a count appeared only on pages whose Livewire component passed `badges` to the layout, so it
 * vanished on Arsip surat, Template surat, Status aktivasi, Notifikasi, Keamanan akun, … This provider registers
 * the one resolver App\Support\Navigation asks on every render of `layouts::app`.
 *
 * Rules:
 * - only the badge keys of the menu that is being rendered (Navigation::badgeKeys($role)); the Admin, Guru and
 *   Siswa menus have none that a screen counts today, so they cost no query;
 * - only for a user who may open that queue: the same policy ability / permission the screen itself checks,
 *   never the role name (an Admin with the Kesiswaan menu gets the Kesiswaan counts through its permissions);
 * - the same query the screen uses, one COUNT per key, no rows loaded;
 * - keys the page passed itself are skipped ($except): the page's number wins in Navigation::for() anyway.
 * The browser event `sth-badges` keeps updating the numbers after an action (resources/js/app.js).
 */
final class NavigationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Navigation::resolveBadgesUsing(self::badges(...));
    }

    /**
     * @param  list<string>  $except  badge keys the page already passed to the layout
     * @return array<string, int>
     */
    public static function badges(object $user, string $role, array $except = []): array
    {
        if (! $user instanceof User) {
            return [];
        }

        $badges = [];

        foreach (array_diff(Navigation::badgeKeys($role), $except) as $key) {
            $count = self::count($user, $key);

            if ($count !== null) {
                $badges[$key] = $count;
            }
        }

        return $badges;
    }

    /** The queue size behind one badge key, or null when this user may not see that queue (or nothing counts it). */
    private static function count(User $user, string $key): ?int
    {
        return match ($key) {
            // TataUsaha\SuratTugas: LetterPolicy::viewQueue, Registration::awaitingLetters().
            'tu.surat' => $user->can('viewQueue', Letter::class)
                ? Registration::query()->awaitingLetters()->count()
                : null,
            // Kepsek\Persetujuan: RegistrationPolicy::viewApprovalQueue, Registration::awaitingPrincipal().
            'kepsek.persetujuan' => $user->can('viewApprovalQueue', Registration::class)
                ? Registration::query()->awaitingPrincipal()->count()
                : null,
            // Kesiswaan\Concerns\WithKesiswaanBadges (permissions as in HumasQueries::badges()).
            'kesiswaan.usulan' => $user->can(Permission::CompetitionVerify->value)
                ? Competition::query()->pendingVerification()->count()
                : null,
            'kesiswaan.verifikasi' => $user->can('viewQueue', Report::class)
                ? Report::query()->awaitingVerification()->count()
                : null,
            // Humas\HumasQueries::badges().
            'humas.publikasi' => $user->can(Permission::PublicationManage->value)
                ? HumasQueries::visiblePublications()->where('status', PublicationStatus::Siap)->count()
                : null,
            // 'siswa.lomba-saya': no screen computes a count for it today.
            default => null,
        };
    }
}
