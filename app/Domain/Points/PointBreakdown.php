<?php

declare(strict_types=1);

namespace App\Domain\Points;

/**
 * Weights snapshot + resulting points for one achievement (all 2-decimal strings).
 */
final readonly class PointBreakdown
{
    public function __construct(
        public string $levelWeight,
        public string $resultWeight,
        public string $organizerWeight,
        public string $points,
    ) {}

    /**
     * Column names of the achievements table.
     *
     * @return array{w_level: string, w_result: string, w_organizer: string, points: string}
     */
    public function toArray(): array
    {
        return [
            'w_level' => $this->levelWeight,
            'w_result' => $this->resultWeight,
            'w_organizer' => $this->organizerWeight,
            'points' => $this->points,
        ];
    }
}
