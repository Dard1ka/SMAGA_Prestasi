<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

/**
 * "Ganti akun Kepala Sekolah" (SECURITY §3m-9): sent to the current and the new Kepala Sekolah and to every
 * Admin when a replacement is requested, cancelled or applied.
 */
final class PrincipalReplacementNotice extends AdminNotice
{
    public const REQUESTED = 'requested';

    public const CANCELLED = 'cancelled';

    public const APPLIED = 'applied';

    public function __construct(
        private readonly string $event,
        private readonly string $fromName,
        private readonly string $toName,
        private readonly string $effectiveAt,
    ) {
        parent::__construct();
    }

    public function kind(): string
    {
        return 'principal.'.$this->event;
    }

    public function subject(): string
    {
        return match ($this->event) {
            self::REQUESTED => 'Penggantian akun Kepala Sekolah dijadwalkan',
            self::CANCELLED => 'Penggantian akun Kepala Sekolah dibatalkan',
            default => 'Akun Kepala Sekolah sudah diganti',
        };
    }

    public function lines(): array
    {
        return match ($this->event) {
            self::REQUESTED => [
                "Peran Kepala Sekolah akan dipindahkan dari {$this->fromName} ke {$this->toName} pada {$this->effectiveAt}.",
                'Jika ini keliru, batalkan di menu Pengguna & peran sebelum waktu itu.',
            ],
            self::CANCELLED => ["Penggantian akun Kepala Sekolah ke {$this->toName} dibatalkan."],
            default => ["Peran Kepala Sekolah sekarang dipegang {$this->toName}."],
        };
    }
}
