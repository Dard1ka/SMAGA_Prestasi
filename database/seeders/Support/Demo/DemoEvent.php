<?php

declare(strict_types=1);

namespace Database\Seeders\Support\Demo;

use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\OrganizerType;
use Carbon\CarbonImmutable;

/** What a juknis, brochure or poster of the demo data says about one lomba (LOCAL ONLY). */
final readonly class DemoEvent
{
    public function __construct(
        public string $name,
        public string $organizer,
        public CarbonImmutable $opensOn,
        public CarbonImmutable $closesOn,
        public CarbonImmutable $startsOn,
        public CarbonImmutable $finalOn,
        public ?Bidang $bidang = null,
        public ?Level $level = null,
        public ?OrganizerType $organizerType = null,
        public bool $online = false,
        public ?string $location = null,
        public bool $team = false,
        public bool $individual = true,
        public ?int $maxTeam = null,
    ) {}

    /**
     * A lomba without dates (a student's proposal): registration from the day it was proposed, the lomba six
     * weeks later.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable} opens, closes, starts, final
     */
    public static function datesFrom(CarbonImmutable $proposedOn): array
    {
        $opens = $proposedOn->startOfDay();

        return [$opens, $opens->addDays(28), $opens->addDays(42), $opens->addDays(42)];
    }
}
