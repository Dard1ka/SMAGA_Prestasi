<?php

declare(strict_types=1);

namespace Database\Seeders\Support\Demo;

use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\OrganizerType;
use App\Enums\ResultType;
use Carbon\CarbonImmutable;

/** What one certificate or piagam of the demo data says: who got which result in which lomba (LOCAL ONLY). */
final readonly class DemoAward
{
    /**
     * @param  string  $recipient  a fictional person of the demo set (DemoPeople)
     * @param  list<string>  $team  the other members of the team, if any
     * @param  bool  $piagam  the file is a "piagam" (sports style) even when the lomba is not a sports event
     * @param  string|null  $affiliation  printed under the name instead of "Siswa SMA Negeri 3 Semarang"
     */
    public function __construct(
        public string $recipient,
        public string $competition,
        public string $organizer,
        public ResultType $result,
        public ?OrganizerType $organizerType = null,
        public ?Bidang $bidang = null,
        public ?Level $level = null,
        public ?string $branch = null,
        public ?CarbonImmutable $startsOn = null,
        public ?CarbonImmutable $finalOn = null,
        public ?string $location = null,
        public bool $online = false,
        public array $team = [],
        public bool $piagam = false,
        public ?string $affiliation = null,
    ) {}

    public function isPiagam(): bool
    {
        return $this->piagam || $this->bidang === Bidang::Olahraga;
    }
}
