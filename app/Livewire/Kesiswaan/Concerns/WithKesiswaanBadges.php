<?php

declare(strict_types=1);

namespace App\Livewire\Kesiswaan\Concerns;

use App\Models\Competition;
use App\Models\Report;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Sidebar badge counts + small date helpers shared by the Kesiswaan screens (W2-B). Counts are real
 * queue sizes; they are passed to the layout with ->layoutData(['badges' => …]).
 */
trait WithKesiswaanBadges
{
    /** @return array<string, int> */
    protected function kesiswaanBadges(): array
    {
        $badges = [
            'kesiswaan.usulan' => Competition::query()->pendingVerification()->count(),
            'kesiswaan.verifikasi' => Report::query()->awaitingVerification()->count(),
        ];

        // The sidebar is rendered once per page load: send the counts to the browser too, so the numbers
        // follow every verify/publish/reject without a reload (resources/js/app.js, event `sth-badges`).
        $this->dispatch('sth-badges', badges: $badges);

        return $badges;
    }

    protected static function shortDate(?DateTimeInterface $date, bool $withYear = true): string
    {
        if ($date === null) {
            return '–';
        }

        return CarbonImmutable::instance($date)->locale('id')->translatedFormat($withYear ? 'j M Y' : 'j M');
    }

    /** "1,1 MB" · "860 KB" */
    protected static function fileSize(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? number_format($bytes / (1024 * 1024), 1, ',', '.').' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    }

    /** "Kemarin, 19.20" · "28 Sep, 10.45" · "Hari ini, 08.15" (queue timestamps). */
    protected static function whenLabel(?DateTimeInterface $date): string
    {
        if ($date === null) {
            return '–';
        }

        $at = CarbonImmutable::instance($date)->locale('id');
        $today = CarbonImmutable::now()->startOfDay();
        $time = $at->format('H.i');

        return match (true) {
            $at->isSameDay($today) => 'Hari ini, '.$time,
            $at->isSameDay($today->subDay()) => 'Kemarin, '.$time,
            default => $at->translatedFormat('j M').', '.$time,
        };
    }
}
