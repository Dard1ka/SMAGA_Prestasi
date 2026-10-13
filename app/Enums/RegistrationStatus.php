<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Registration state machine (ARCHITECTURE §6). Allowed transitions live in
 * App\Domain\Registrations\RegistrationStateMachine; phase() uses the same mapping as its phaseFor().
 */
enum RegistrationStatus: string
{
    use HasOptions;

    case MenungguKonfirmasi = 'menunggu_konfirmasi';
    case MenungguKepsek = 'menunggu_kepsek';
    case Disetujui = 'disetujui';
    case Ditolak = 'ditolak';
    case SuratTerbit = 'surat_terbit';
    case Berlangsung = 'berlangsung';
    case MenungguLaporan = 'menunggu_laporan';
    case LaporanDikirim = 'laporan_dikirim';
    case Selesai = 'selesai';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::MenungguKonfirmasi => 'Menunggu konfirmasi anggota',
            self::MenungguKepsek => 'Menunggu persetujuan', // D-150: Kepala Sekolah or Tata Usaha decides; the value stays
            self::Disetujui => 'Disetujui',
            self::Ditolak => 'Ditolak',
            self::SuratTerbit => 'Surat tugas terbit',
            self::Berlangsung => 'Lomba berlangsung',
            self::MenungguLaporan => 'Menunggu laporan',
            self::LaporanDikirim => 'Laporan dikirim',
            self::Selesai => 'Selesai',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    /**
     * Tracker phase 1–6: 1 Daftar · 2 Persetujuan · 3 Surat tugas · 4 Lomba · 5 Laporan hasil · 6 Poin & apresiasi.
     * ditolak ends in phase 2, dibatalkan in phase 1.
     */
    public function phase(): int
    {
        return match ($this) {
            self::MenungguKonfirmasi, self::Dibatalkan => 1,
            self::MenungguKepsek, self::Ditolak => 2,
            self::Disetujui, self::SuratTerbit => 3,
            self::Berlangsung => 4,
            self::MenungguLaporan, self::LaporanDikirim => 5,
            self::Selesai => 6,
        };
    }

    public static function phaseLabel(int $phase): string
    {
        return match ($phase) {
            1 => 'Daftar',
            2 => 'Persetujuan',
            3 => 'Surat tugas',
            4 => 'Lomba',
            5 => 'Laporan hasil',
            6 => 'Poin & apresiasi',
            default => throw new \InvalidArgumentException('Fase harus 1–6.'),
        };
    }

    /** ditolak, selesai, dibatalkan: no further transitions. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Ditolak, self::Selesai, self::Dibatalkan], true);
    }

    /** Status chip tone (DESIGN-SPEC §2: green done, orange needs action, red rejected, gray waiting, blue in progress). */
    public function tone(): string
    {
        return match ($this) {
            self::MenungguKonfirmasi, self::MenungguKepsek => 'gray',
            self::Disetujui, self::SuratTerbit, self::Berlangsung, self::LaporanDikirim => 'blue',
            self::MenungguLaporan => 'orange',
            self::Selesai => 'green',
            self::Ditolak => 'red',
            self::Dibatalkan => 'gray',
        };
    }

    /** @return list<self> statuses that still block the same student from registering again for the same competition */
    public static function nonFinal(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => ! $s->isFinal()));
    }
}
