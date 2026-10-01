<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

/**
 * Privileged role change (backlog #13, SECURITY §3m-9 "Alert: perubahan peran"): sent to every Admin and the
 * account concerned when a change is requested, applied or cancelled; in delay mode (single Admin) the
 * Kepala Sekolah gets the request notice too. Staff names and role labels only.
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

    public function lines(): array
    {
        $requested = $this->newAccount
            ? "{$this->actorName} membuat akun {$this->targetName} dengan peran {$this->roleLabel}. Sampai peran berlaku, akun ini belum punya peran."
            : "{$this->actorName} meminta peran {$this->targetName} diubah menjadi {$this->roleLabel}.";

        return match ($this->event) {
            self::REQUESTED => $this->effectiveAt !== null
                ? [
                    $requested,
                    "Berlaku {$this->effectiveAt} bila tidak dibatalkan. Jika keliru, batalkan di menu Pengguna & peran.",
                ]
                : [
                    $requested,
                    'Perlu persetujuan Admin lain di menu Pengguna & peran.',
                ],
            self::CANCELLED => ["Perubahan peran {$this->targetName} menjadi {$this->roleLabel} dibatalkan."],
            default => ["Peran {$this->targetName} sekarang {$this->roleLabel}.", 'Pengguna perlu login ulang.'],
        };
    }
}
