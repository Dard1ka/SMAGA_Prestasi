<?php

declare(strict_types=1);

namespace App\Actions\Exports;

/** A finished export on a temporary path; the caller streams it and deletes it (deleteFileAfterSend). */
final readonly class ExportedFile
{
    public function __construct(
        public string $path,
        public string $filename,
        public int $rows,
    ) {}
}
