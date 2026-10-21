<?php

declare(strict_types=1);

namespace App\Domain\Competitions;

use App\Enums\EventStatus;
use App\Enums\WeightCategory;

/** What OrganizerWeight::for() gives a lomba: kategori bobot + status ajang (D-146). */
final readonly class OrganizerWeightResult
{
    public function __construct(
        public WeightCategory $category,
        public EventStatus $eventStatus,
    ) {}
}
