<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

/**
 * How a role request made before 21 Okt 2026 ended (backlog #13, SECURITY §3m-9 "Alert: perubahan peran"): applied
 * (approved by a second Admin or after its 24 h) or cancelled. Sent to every Admin and the account concerned; for a
 * delay-mode request also to the Kepala Sekolah (QA10, ARCHITECTURE §8b). Staff names and role labels only.
 *
 * Since 21 Okt 2026 (the lead) a role change applies at once and is told by StaffAccountChanged, so nothing new waits
 * and the "requested" notice (REQUESTED, `effectiveAt`, `newAccount`) is gone; bell rows stored before keep their text.
 */
final class RoleChangeNotice extends AdminNotice
{
    public const APPLIED = 'applied';

    public const CANCELLED = 'cancelled';

    public function __construct(
        private readonly string $event,
        private readonly string $actorName,
        private readonly string $targetName,
        private readonly string $roleLabel,
    ) {
        parent::__construct();
    }

    public function kind(): string
    {
        return 'role.'.$this->event;
    }

    public function subject(): string
    {
        return $this->event === self::CANCELLED ? 'Perubahan peran dibatalkan' : 'Peran akun staf diubah';
    }

    public function lines(): array
    {
        return $this->event === self::CANCELLED
            ? ["Perubahan peran {$this->targetName} menjadi {$this->roleLabel} dibatalkan."]
            : ["Peran {$this->targetName} sekarang {$this->roleLabel}.", 'Pengguna perlu login ulang.'];
    }
}
