<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * SV8, D-199: the state of one registration revision (App\Models\RegistrationRevision). Only `pending` changes:
 * approved / rejected by Tata Usaha or Kesiswaan (first decision wins), cancelled by the ketua, or by an invited
 * student who declines (the revision then no longer holds what the ketua asked for).
 */
enum RevisionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu keputusan',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /** Chip tone (DESIGN-SPEC: green done, red rejected, gray waiting or ended). */
    public function tone(): string
    {
        return match ($this) {
            self::Pending, self::Cancelled => 'gray',
            self::Approved => 'green',
            self::Rejected => 'red',
        };
    }
}
