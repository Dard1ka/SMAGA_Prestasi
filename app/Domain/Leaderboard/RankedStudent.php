<?php

declare(strict_types=1);

namespace App\Domain\Leaderboard;

/**
 * One leaderboard line. `position` is the unique display order (1..n); `rank` is shared by
 * students whose points AND result profile are equal (1, 2, 2, 4) — name only orders them.
 */
final readonly class RankedStudent
{
    /**
     * @param  list<int>  $tierCounts  number of results per tier, best tier first
     */
    public function __construct(
        public int $rank,
        public int $position,
        public int|string $studentId,
        public string $name,
        public string $points,
        public array $tierCounts,
        public int $achievements,
        public bool $alumni,
    ) {}
}
