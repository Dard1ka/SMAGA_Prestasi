<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

/**
 * Privileged role change (backlog #13, SECURITY §3m-9 "Alert: perubahan peran"): sent to every Admin and the
 * account concerned when a change is requested, applied or cancelled; in delay mode (single Admin) the
 * Kepala Sekolah gets every one of them too (since QA10 also the outcome, ARCHITECTURE §8b). Staff names and role
 * labels only. Where to cancel depends on the reader (linesFor(), QA10).
 * `newAccount` (W4): the request belongs to a staff account just created with a privileged role (CreateStaffAccount).
 */
final class RoleChangeNotice extends AdminNotice
{
    public const REQUESTED = 'requested';

    public const APPLIED = 'applied';

    public const CANCELLED = 'cancelled';

    public function __construct(
        private readonly string $event,
        private readonly string $actorName,
        private readonly string $targetName,
        private readonly string $roleLabel,
        private readonly ?string $effectiveAt = null,
        private readonly bool $newAccount = false,
    ) {
        parent::__construct();
    }

    public function kind(): string
    {
        return 'role.'.$this->event;
    }

    public function subject(): string
    {
        return match ($this->event) {
            self::REQUESTED => $this->newAccount ? 'Akun staf baru menunggu persetujuan peran' : 'Perubahan peran menunggu persetujuan',
            self::CANCELLED => 'Perubahan peran dibatalkan',
            default => 'Peran akun staf diubah',
        };
    }

    /** The text for an Admin (the default). */
    public function lines(): array
    {
        return $this->linesForAdmin(true);
    }

    /** QA10: the account concerned and the Kepala Sekolah cannot open Pengguna & peran; they are asked to turn to an Admin. */
    public function linesFor(object $notifiable): array
    {
        return $this->linesForAdmin(self::isAdmin($notifiable));
    }

    /** @return list<string> */
    private function linesForAdmin(bool $admin): array
    {
        $requested = $this->newAccount
            ? "{$this->actorName} membuat akun {$this->targetName} dengan peran {$this->roleLabel}. Sampai peran berlaku, akun ini belum punya peran."
            : "{$this->actorName} meminta peran {$this->targetName} diubah menjadi {$this->roleLabel}.";

        return match ($this->event) {
            self::REQUESTED => $this->effectiveAt !== null
                ? [
                    $requested,
                    "Berlaku {$this->effectiveAt} bila tidak dibatalkan. ".($admin ? 'Jika keliru, batalkan di menu Pengguna & peran.' : 'Jika keliru, minta Admin membatalkannya.'),
                ]
                : [
                    $requested,
                    $admin ? 'Perlu persetujuan Admin lain di menu Pengguna & peran.' : 'Perubahan ini menunggu persetujuan Admin lain.',
                ],
            self::CANCELLED => ["Perubahan peran {$this->targetName} menjadi {$this->roleLabel} dibatalkan."],
            default => ["Peran {$this->targetName} sekarang {$this->roleLabel}.", 'Pengguna perlu login ulang.'],
        };
    }
}
