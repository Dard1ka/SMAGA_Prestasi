<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

/**
 * SECURITY §3m-9 "Alert: export > 500 baris" (owner B5): sent to every Admin when one export (audit log CSV,
 * laporan prestasi) contains more than LIMIT rows. Names of the exporter and the export only.
 */
final class LargeExportNotice extends AdminNotice
{
    public const LIMIT = 500;

    public function __construct(
        private readonly string $exporterName,
        private readonly string $exportLabel,
        private readonly int $rows,
        private readonly string $at,
    ) {
        parent::__construct();
    }

    public function kind(): string
    {
        return 'export.large';
    }

    public function subject(): string
    {
        return 'Ekspor data besar';
    }

    public function lines(): array
    {
        return [
            "{$this->exporterName} mengunduh {$this->exportLabel} berisi {$this->rows} baris pada {$this->at}.",
            'Jika bukan atas sepengetahuan sekolah, periksa log audit.',
        ];
    }
}
