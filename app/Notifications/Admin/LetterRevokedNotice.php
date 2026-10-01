<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

use Illuminate\Support\Facades\Route;

/**
 * Lead decision (2026-10-01, owner B5): letters go out in the Kepala Sekolah's name, so every revocation
 * (App\Actions\Letters\RevokeLetter, B3) is reported to the Kepala Sekolah — queued mail + database — with the
 * old and new number, the lomba and the TU's reason. No second approval for revocations.
 */
final class LetterRevokedNotice extends AdminNotice
{
    public function __construct(
        private readonly string $actorName,
        private readonly string $letterLabel,
        private readonly string $oldNumber,
        private readonly string $newNumber,
        private readonly string $competitionName,
        private readonly string $reason,
    ) {
        parent::__construct();
    }

    public function kind(): string
    {
        return 'letter.revoked';
    }

    public function subject(): string
    {
        return 'Surat tugas dicabut dan diganti';
    }

    public function lines(): array
    {
        return array_values(array_filter([
            "{$this->actorName} mencabut {$this->letterLabel} nomor {$this->oldNumber}".($this->competitionName !== '' ? " ({$this->competitionName})" : '').'.',
            "Penggantinya: nomor {$this->newNumber}.",
            "Alasan: {$this->reason}",
            'Daftar lengkap ada di menu Surat atas nama saya.',
        ]));
    }

    public function url(): ?string
    {
        return Route::has('kepsek.surat.index') ? route('kepsek.surat.index', ['status' => 'dicabut']) : null;
    }
}
