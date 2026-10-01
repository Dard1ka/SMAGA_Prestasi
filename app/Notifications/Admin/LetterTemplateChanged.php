<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

/**
 * TU changed the surat tugas template (backlog #7, owner B5): the Kepala Sekolah (letters go out in their name)
 * and every Admin are told which version is now used. No letter content, staff name only.
 */
final class LetterTemplateChanged extends AdminNotice
{
    public function __construct(
        private readonly string $actorName,
        private readonly int $version,
    ) {
        parent::__construct();
    }

    public function kind(): string
    {
        return 'letter_template.updated';
    }

    public function subject(): string
    {
        return 'Template surat tugas diubah';
    }

    public function lines(): array
    {
        return [
            "{$this->actorName} mengubah template surat tugas (versi {$this->version}).",
            'Surat berikutnya memakai teks baru. Surat yang sudah terbit tidak berubah.',
        ];
    }
}
