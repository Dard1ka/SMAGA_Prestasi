<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** student_imports.status: a queued student import (backlog #19, App\Jobs\ImportStudentsJob). */
enum StudentImportStatus: string
{
    use HasOptions;

    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Menunggu antrean',
            self::Running => 'Sedang mengimpor',
            self::Done => 'Selesai',
            self::Failed => 'Gagal',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
