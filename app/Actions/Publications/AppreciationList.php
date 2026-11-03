<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Enums\UserStatus;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;

/**
 * D-141: the students of one appreciation event that may be named in print, ordered by class and name. Every
 * appreciation of the event with an achievement counts (scheduled and given); a student who fails `NamedStudent`
 * (deleted, disabled) is not listed and only raises `omitted`. Never NIS, NISN, points or mentor.
 */
final class AppreciationList
{
    private const ALUMNI = 'Alumni';

    private const NO_CLASS = '–';

    /**
     * @return array{rows: list<array{no: int, name: string, class: string, result: string, competition: string, level: string}>, omitted: int}
     */
    public static function for(AppreciationEvent $event): array
    {
        $relations = array_map(fn (string $r): string => 'achievement.'.$r, AchievementFacts::RELATIONS);

        $appreciations = $event->appreciations()->with($relations)->get()
            ->filter(fn (Appreciation $a): bool => $a->achievement !== null);

        $people = [];
        $omitted = 0;

        foreach ($appreciations as $appreciation) {
            $achievement = $appreciation->achievement;

            if (! NamedStudent::allows($achievement->student)) {
                $omitted++;

                continue;
            }

            $facts = AchievementFacts::of($achievement);

            $people[] = [
                'name' => $facts->studentName,
                'class' => $achievement->student?->user?->status === UserStatus::Archived
                    ? self::ALUMNI
                    : ($facts->className ?? self::NO_CLASS),
                'result' => $facts->resultLabel,
                'competition' => $facts->competitionName,
                'level' => $facts->levelLabel ?? '–',
            ];
        }

        // Real classes first (natural order: X-B, XI-D, XII-B), "Alumni" then "–" last, then by name.
        usort($people, static fn (array $a, array $b): int => self::group($a['class']) <=> self::group($b['class'])
            ?: strnatcasecmp($a['class'], $b['class'])
            ?: strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])));

        $rows = [];

        foreach ($people as $i => $person) {
            $rows[] = ['no' => $i + 1] + $person;
        }

        return ['rows' => $rows, 'omitted' => $omitted];
    }

    /** 0 = a real class, 1 = "Alumni", 2 = no class. */
    private static function group(string $class): int
    {
        return match ($class) {
            self::ALUMNI => 1,
            self::NO_CLASS => 2,
            default => 0,
        };
    }
}
