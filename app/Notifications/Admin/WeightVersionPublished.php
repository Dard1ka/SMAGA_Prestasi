<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

/** To the Kepala Sekolah and Admins after the point weights changed (UI-MAP #24, D-08). */
final class WeightVersionPublished extends AdminNotice
{
    public function __construct(private readonly string $actorName, private readonly string $effectiveAt)
    {
        parent::__construct();
    }

    public function kind(): string
    {
        return 'weights.published';
    }

    public function subject(): string
    {
        return 'Bobot poin diubah';
    }

    public function lines(): array
    {
        return [
            "{$this->actorName} menyimpan bobot poin baru, berlaku {$this->effectiveAt}.",
            'Poin semua prestasi sedang dihitung ulang dan tercatat di log audit.',
        ];
    }
}
