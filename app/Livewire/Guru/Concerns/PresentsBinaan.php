<?php

declare(strict_types=1);

namespace App\Livewire\Guru\Concerns;

use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Models\Registration;

/**
 * Display helpers shared by Guru\Binaan and Guru\BinaanDetail (B4). Display only, no rules.
 */
trait PresentsBinaan
{
    /**
     * Status chip text and tone (menunggu laporan without a date since D-151, perlu revisi, laporan ditolak).
     *
     * @return array{0: string, 1: string}
     */
    protected function binaanChip(Registration $registration): array
    {
        $status = $registration->status;
        $report = $registration->report;

        if ($status === RegistrationStatus::MenungguLaporan) {
            if ($report?->status === ReportStatus::PerluRevisi) {
                return ['Perlu revisi', 'orange'];
            }

            // D-151: no deadline, no days left.
            return ['Menunggu laporan', 'orange'];
        }

        if ($status === RegistrationStatus::LaporanDikirim) {
            return ['Laporan menunggu verifikasi', 'blue'];
        }

        if ($status === RegistrationStatus::Selesai && $report?->status === ReportStatus::Ditolak) {
            return ['Laporan ditolak', 'red'];
        }

        return [$status->label(), $status->tone()];
    }

    protected function binaanType(Registration $registration, int $members): string
    {
        return $registration->isTeam() ? 'Tim '.$members.' orang' : 'Individu';
    }
}
