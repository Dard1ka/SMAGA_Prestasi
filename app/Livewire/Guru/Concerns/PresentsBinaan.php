<?php

declare(strict_types=1);

namespace App\Livewire\Guru\Concerns;

use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Livewire\Siswa\LombaSaya\Index as LombaSaya;
use App\Models\Registration;

/**
 * Display helpers shared by Guru\Binaan and Guru\BinaanDetail (B4). Display only, no rules.
 */
trait PresentsBinaan
{
    /**
     * The chip while the report is awaited: a guru never reports (only the ketua does, ReportPolicy::create), so it is
     * the gray of waiting on someone else (DESIGN-SPEC-v3: orange = needs action), as the tracker's "current" phase.
     */
    public const REPORT_CHIP = ['Menunggu laporan siswa', 'gray'];

    /** The same while the report is sent back for a revision: the student revises it. */
    public const REVISION_CHIP = ['Menunggu perbaikan siswa', 'gray'];

    /**
     * Status chip text and tone (a team short of members, menunggu laporan without a date since D-151, a report under
     * revision, laporan ditolak).
     *
     * @return array{0: string, 1: string}
     */
    protected function binaanChip(Registration $registration): array
    {
        $status = $registration->status;
        $report = $registration->report;

        // D-36: a team left with fewer than two people does not go on, and only the ketua can act (invite a replacement
        // or cancel), so the guru reads the gray chip of a Lomba saya viewer who is not the ketua (SV1 R3: it read
        // "Menunggu konfirmasi anggota"). The rule of ConfirmMembership (LombaSaya::isShortTeam()), counted on the loaded
        // members when the screen has them (both guru screens do: no COUNT query per row of Siswa binaan).
        $people = $registration->relationLoaded('members') ? $registration->members->whereNull('declined_at')->count() : null;

        if (LombaSaya::isShortTeam($registration, $people)) {
            ['chip' => $chip, 'tone' => $tone] = LombaSaya::shortTeamChip(false);

            return [$chip, $tone];
        }

        if ($status === RegistrationStatus::MenungguLaporan) {
            // D-151: no deadline, no days left. SV1 review: the guru waits for the student, never "perlu tindakan".
            return $report?->status === ReportStatus::PerluRevisi ? self::REVISION_CHIP : self::REPORT_CHIP;
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
