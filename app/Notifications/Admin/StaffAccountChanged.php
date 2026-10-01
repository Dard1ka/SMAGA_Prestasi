<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

/**
 * Alert to every Admin (and the affected staff member) when a staff account is created, gets a new role or is
 * reset by an Admin (SECURITY §3m-9 "Alert: perubahan peran").
 */
final class StaffAccountChanged extends AdminNotice
{
    public const CREATED = 'created';

    public const ROLE_CHANGED = 'role_changed';

    public const RESET = 'reset';

    public function __construct(
        private readonly string $event,
        private readonly string $actorName,
        private readonly string $targetName,
        private readonly string $roleLabel = '',
    ) {
        parent::__construct();
    }

    public function kind(): string
    {
        return 'staff.'.$this->event;
    }

    public function subject(): string
    {
        return match ($this->event) {
            self::CREATED => 'Akun staf baru dibuat',
            self::ROLE_CHANGED => 'Peran akun staf diubah',
            default => 'Akun staf direset',
        };
    }

    public function lines(): array
    {
        return match ($this->event) {
            self::CREATED => ["{$this->actorName} membuat akun {$this->targetName} dengan peran {$this->roleLabel}."],
            self::ROLE_CHANGED => ["{$this->actorName} mengubah peran {$this->targetName} menjadi {$this->roleLabel}."],
            default => ["{$this->actorName} mereset kata sandi dan verifikasi dua langkah akun {$this->targetName}.", 'Jika bukan atas permintaan Anda, hubungi Admin sekarang.'],
        };
    }
}
