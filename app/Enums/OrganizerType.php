<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Jenis penyelenggara (Kelola lomba form, 10 options). */
enum OrganizerType: string
{
    use HasOptions;

    case PemerintahPusat = 'pemerintah_pusat';
    case PemerintahDaerah = 'pemerintah_daerah';
    case Ptn = 'ptn';
    case Pts = 'pts';
    case BumnBumd = 'bumn_bumd';
    case OrganisasiProfesi = 'organisasi_profesi';
    case FederasiOlahraga = 'federasi_olahraga';
    case YayasanLembaga = 'yayasan_lembaga';
    case PerusahaanSwasta = 'perusahaan_swasta';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::PemerintahPusat => 'Pemerintah pusat',
            self::PemerintahDaerah => 'Pemerintah daerah',
            self::Ptn => 'Perguruan tinggi negeri',
            self::Pts => 'Perguruan tinggi swasta',
            self::BumnBumd => 'BUMN/BUMD',
            self::OrganisasiProfesi => 'Organisasi profesi',
            self::FederasiOlahraga => 'Federasi olahraga',
            self::YayasanLembaga => 'Yayasan/lembaga',
            self::PerusahaanSwasta => 'Perusahaan swasta',
            self::Lainnya => 'Lainnya',
        };
    }
}
