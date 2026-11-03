<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** letters.type: one shared student letter per registration + one guru letter (D-06). */
enum LetterType: string
{
    use HasOptions;

    case Siswa = 'siswa';
    case Guru = 'guru';

    public function label(): string
    {
        return match ($this) {
            self::Siswa => 'Surat tugas siswa',
            self::Guru => 'Surat perintah tugas guru',
        };
    }

    /** Settings key that holds this letter's number format. */
    public function formatSetting(): SettingKey
    {
        return match ($this) {
            self::Siswa => SettingKey::LetterFormatSiswa,
            self::Guru => SettingKey::LetterFormatGuru,
        };
    }
}
