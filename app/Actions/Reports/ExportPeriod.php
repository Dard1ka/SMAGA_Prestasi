<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Domain\Archive\SchoolYear;
use Carbon\CarbonImmutable;

/**
 * Period of the Kepala Sekolah report (owner B5): a school year (1 July – 30 June), a custom date range, or
 * everything. An achievement belongs to the period by its lomba's final date, falling back to the
 * verification date (same rule as the dashboard and the public pages).
 */
final readonly class ExportPeriod
{
    public function __construct(
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
        public string $label,
        public string $key,
    ) {}

    public static function all(): self
    {
        return new self(null, null, 'Semua tahun', 'semua');
    }

    public static function schoolYear(SchoolYear $year): self
    {
        return new self(
            CarbonImmutable::create($year->startYear, 7, 1)->startOfDay(),
            CarbonImmutable::create($year->startYear + 1, 6, 30)->startOfDay(),
            'Tahun ajaran '.$year->toString(),
            $year->toString(),
        );
    }

    public static function range(CarbonImmutable $from, CarbonImmutable $to): self
    {
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return new self(
            $from->startOfDay(),
            $to->startOfDay(),
            $from->locale('id')->translatedFormat('j M Y').' – '.$to->locale('id')->translatedFormat('j M Y'),
            $from->format('Ymd').'-'.$to->format('Ymd'),
        );
    }

    /** @return array{from: ?string, to: ?string, label: string} */
    public function toArray(): array
    {
        return ['from' => $this->from?->toDateString(), 'to' => $this->to?->toDateString(), 'label' => $this->label];
    }
}
