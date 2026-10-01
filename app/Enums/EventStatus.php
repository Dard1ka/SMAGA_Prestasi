<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Status ajang (Kelola lomba form, 5 options). */
enum EventStatus: string
{
    use HasOptions;

    case Puspresnas = 'puspresnas';
    case Terkurasi = 'terkurasi';
    case PemerintahNonPuspresnas = 'pemerintah_non_puspresnas';
    case NonPemerintah = 'non_pemerintah';
    case BelumTerverifikasi = 'belum_terverifikasi';

    public function label(): string
    {
        return match ($this) {
            self::Puspresnas => 'Puspresnas',
            self::Terkurasi => 'Terkurasi',
            self::PemerintahNonPuspresnas => 'Pemerintah (non-Puspresnas)',
            self::NonPemerintah => 'Non-pemerintah',
            self::BelumTerverifikasi => 'Belum terverifikasi',
        };
    }
}
