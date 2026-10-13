<?php

declare(strict_types=1);

namespace App\Livewire\Siswa\LombaSaya\Concerns;

use App\Domain\Points\PointBreakdown;
use App\Domain\Points\PointCalculator;
use App\Domain\Points\WeightTable;
use App\Enums\ResultType;
use App\Models\Competition;
use App\Models\WeightVersion;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Display helpers shared by the Lomba saya screens (W2-A2). Points are only *previewed* here with the
 * active weight version (App\Domain\Points); the final points are computed by Kesiswaan's VerifyReport.
 */
trait ReportHelpers
{
    private ?WeightTable $weightTableCache = null;

    /** Estimated points for one member, or null when the lomba lacks a weight field. */
    protected function previewBreakdown(Competition $competition, ResultType|string|null $result): ?PointBreakdown
    {
        $result = is_string($result) ? ResultType::tryFrom($result) : $result;

        if ($result === null || $competition->level === null || $competition->weight_category === null) {
            return null;
        }

        $this->weightTableCache ??= WeightVersion::active()?->toWeightTable() ?? WeightTable::defaults();

        return app(PointCalculator::class)->breakdown(
            $this->weightTableCache,
            $competition->level,
            $result,
            $competition->weight_category,
        );
    }

    /**
     * The chip of a registration that waits for its report (D-151): no due date and no days left, only the request.
     *
     * @return array{chip: string, tone: string}
     */
    protected function reportChip(): array
    {
        return ['chip' => 'Segera laporkan', 'tone' => 'orange'];
    }

    protected static function shortDate(?DateTimeInterface $date): string
    {
        return $date === null ? '' : CarbonImmutable::instance($date)->locale('id')->translatedFormat('j M');
    }

    protected static function longDate(?DateTimeInterface $date): string
    {
        return $date === null ? '' : CarbonImmutable::instance($date)->locale('id')->translatedFormat('j M Y');
    }

    /** "21–24 Sep 2026", "28 Sep – 2 Okt 2026" or "24 Feb 2026". */
    protected static function dateRange(?DateTimeInterface $start, ?DateTimeInterface $end, bool $withYear = true): string
    {
        if ($start === null && $end === null) {
            return '';
        }

        $start = CarbonImmutable::instance($start ?? $end)->locale('id');
        $end = CarbonImmutable::instance($end ?? $start)->locale('id');
        $year = $withYear ? ' '.$end->year : '';

        if ($start->isSameDay($end)) {
            return $start->translatedFormat('j M').$year;
        }

        if ($start->isSameMonth($end)) {
            return $start->day.'–'.$end->translatedFormat('j M').$year;
        }

        return $start->translatedFormat('j M').' – '.$end->translatedFormat('j M').$year;
    }
}
