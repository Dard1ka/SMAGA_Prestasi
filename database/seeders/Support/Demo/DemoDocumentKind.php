<?php

declare(strict_types=1);

namespace Database\Seeders\Support\Demo;

/** The kinds of realistic sample documents the local demo data can hold (LOCAL ONLY). */
enum DemoDocumentKind: string
{
    case Sertifikat = 'sertifikat';
    case Piagam = 'piagam';
    case LaporanPenelitian = 'laporan_penelitian';
    case Juknis = 'juknis';
    case Brosur = 'brosur';
    case Poster = 'poster';
    case SuratSiswa = 'surat_siswa';
    case SuratGuru = 'surat_guru';
    case Umum = 'umum';

    public function label(): string
    {
        return match ($this) {
            self::Sertifikat => 'Sertifikat',
            self::Piagam => 'Piagam penghargaan',
            self::LaporanPenelitian => 'Laporan penelitian',
            self::Juknis => 'Juknis lomba',
            self::Brosur => 'Brosur lomba',
            self::Poster => 'Poster lomba',
            self::SuratSiswa => 'Surat tugas siswa',
            self::SuratGuru => 'Surat perintah tugas guru',
            self::Umum => 'Dokumen umum',
        };
    }
}
