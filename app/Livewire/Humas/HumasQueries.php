<?php

declare(strict_types=1);

namespace App\Livewire\Humas;

use App\Actions\Publications\AchievementFacts;
use App\Actions\Publications\NamedStudent;
use App\Enums\Permission;
use App\Enums\PublicationStatus;
use App\Models\Achievement;
use App\Models\Competition;
use App\Models\Publication;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared queries + small formatters of the Humas screens (W2-C helper). The queue only shows
 * publications of verified achievements whose student may be named (mirrors PublicationPolicy::eligible();
 * SECURITY §3m-7; QA10: NamedStudent, so a disabled or deleted student leaves the queue like one without consent).
 */
final class HumasQueries
{
    /** @return Builder<Publication> */
    public static function visiblePublications(): Builder
    {
        return Publication::query()->whereHas('achievement', fn (Builder $a) => $a
            ->whereNotNull('verified_at')
            ->whereHas('student', fn (Builder $s) => NamedStudent::constrain($s)));
    }

    /**
     * Visible publications that are "lewat jadwal" (D-122): still `terjadwal` although their time arrived, so
     * Humas has not marked them as published and the student has not been told. The box on Humas › Jadwal, the
     * chip on the Publikasi cards and the sidebar count `humas.jadwal` all use this rule (Publication::overdue()).
     *
     * @return Builder<Publication>
     */
    public static function overduePublications(?CarbonImmutable $now = null): Builder
    {
        return self::visiblePublications()->overdue($now);
    }

    /** Eager loads for cards (Model::shouldBeStrict(): no lazy loading in lists). */
    public static function cardRelations(): array
    {
        return array_map(fn (string $relation): string => 'achievement.'.$relation, AchievementFacts::RELATIONS);
    }

    /**
     * Case-insensitive filter on student name or competition name. With $consentingNamesOnly a student
     * without publish consent is not found by name (Arsip shows such a row without the name, so the search
     * must not confirm it either).
     *
     * @param  Builder<Publication>  $query
     */
    public static function search(Builder $query, string $term, bool $consentingNamesOnly = false): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->whereHas('achievement', fn (Builder $a) => $a->where(fn (Builder $w) => $w
            ->whereHas('student', fn (Builder $s) => $s
                ->when($consentingNamesOnly, fn (Builder $c) => $c->where('publish_consent', true))
                ->whereHas('user', fn (Builder $u) => $u->where('name', 'like', $like)))
            ->orWhereHas('competition', fn (Builder $c) => $c->where('name', 'like', $like))));
    }

    /**
     * Verified achievements with publish consent that may go on an appreciation event.
     *
     * @return Builder<Achievement>
     */
    public static function appreciableAchievements(): Builder
    {
        return Achievement::query()
            ->whereNotNull('verified_at')
            ->whereHas('student', fn (Builder $s) => $s->where('publish_consent', true));
    }

    /**
     * Case-insensitive filter on student name or competition name for the picker "Tambah prestasi" (D-124), the
     * way search() filters publications: `%` and `_` are literal. Give it appreciableAchievements(): that query
     * already demands publish consent, so a student without consent is never confirmed by name.
     *
     * @param  Builder<Achievement>  $achievements
     */
    public static function searchAchievements(Builder $achievements, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $achievements->where(fn (Builder $w) => $w
            ->whereHas('student.user', fn (Builder $u) => $u->where('name', 'like', $like))
            ->orWhereHas('competition', fn (Builder $c) => $c->where('name', 'like', $like)));
    }

    /**
     * Sidebar badge counts the user may see (Humas queue; Kesiswaan queues when the same screen is
     * mounted under kesiswaan.*).
     *
     * @return array<string, int>
     */
    public static function badges(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $badges = [];

        if ($user->can(Permission::PublicationManage->value)) {
            $badges['humas.publikasi'] = self::visiblePublications()->where('status', PublicationStatus::Siap)->count();
            // Menu item Jadwal: scheduled publications past their time that nobody marked as published yet (D-122).
            $badges['humas.jadwal'] = self::overduePublications()->count();
        }

        if ($user->can(Permission::CompetitionVerify->value)) {
            $badges['kesiswaan.usulan'] = Competition::query()->pendingVerification()->count();
        }

        if ($user->can(Permission::ReportVerify->value)) {
            $badges['kesiswaan.verifikasi'] = Report::query()->awaitingVerification()->count();
        }

        return $badges;
    }

    /** "2 Okt" / "2 Okt 2026". */
    public static function shortDate(?DateTimeInterface $date, bool $withYear = false): string
    {
        if ($date === null) {
            return '–';
        }

        return CarbonImmutable::instance($date)->locale('id')->translatedFormat($withYear ? 'j M Y' : 'j M');
    }

    /** "Senin, 5 Oktober 2026". */
    public static function longDate(?DateTimeInterface $date): string
    {
        if ($date === null) {
            return '–';
        }

        return CarbonImmutable::instance($date)->locale('id')->translatedFormat('l, j F Y');
    }

    /** Border colour of a bidang (published lane), literal class names for Tailwind. */
    public static function bidangBorder(?string $cssKey): string
    {
        return match ($cssKey) {
            'sains' => 'border-l-sains-chart',
            'riset' => 'border-l-riset-chart',
            'seni' => 'border-l-seni-chart',
            'olahraga' => 'border-l-olahraga-chart',
            default => 'border-l-line',
        };
    }
}
