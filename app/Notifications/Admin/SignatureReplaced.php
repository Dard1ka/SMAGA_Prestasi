<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

/** To the Kepala Sekolah and Admins when the signature + stamp image changes (SECURITY §3m-5). */
final class SignatureReplaced extends AdminNotice
{
    public function __construct(private readonly string $actorName, private readonly bool $removed = false)
    {
        parent::__construct();
    }

    public function kind(): string
    {
        return $this->removed ? 'signature.removed' : 'signature.replaced';
    }

    public function subject(): string
    {
        return $this->removed ? 'Tanda tangan surat dihapus' : 'Tanda tangan surat diganti';
    }

    public function lines(): array
    {
        return $this->removed
            ? ["{$this->actorName} menghapus gambar tanda tangan + stempel. Surat tugas baru tidak bisa dibuat sampai ada gambar baru."]
            : ["{$this->actorName} mengunggah gambar tanda tangan + stempel baru. Gambar ini dipakai di semua surat tugas berikutnya.", 'Jika bukan atas persetujuan Anda, hubungi Admin sekarang.'];
    }
}
