<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

/** Result of the daily archive job to every Admin (SECURITY §3m-8), only when accounts were archived. */
final class ArchiveRunReport extends AdminNotice
{
    public function __construct(private readonly int $archived, private readonly string $date)
    {
        parent::__construct();
    }

    public function kind(): string
    {
        return 'archive.run';
    }

    public function subject(): string
    {
        return 'Arsip otomatis: '.$this->archived.' akun siswa';
    }

    public function lines(): array
    {
        return [
            "Pada {$this->date}, {$this->archived} akun siswa diarsipkan otomatis sesuai tanggal arsipnya.",
            'Akun alumni tidak bisa login. Prestasinya tetap tampil di peringkat dan halaman publik.',
        ];
    }
}
