<?php

declare(strict_types=1);

namespace App\Domain\Leaderboard;

use App\Domain\Support\Decimal;
use App\Domain\Support\Key;
use BackedEnum;
use InvalidArgumentException;

/**
 * Cumulative leaderboard (ARCHITECTURE §7, D-09): SUM(points) per student over all time,
 * alumni included. Order: points desc → more higher-tier results first → name → student id.
 * Rows may be one per achievement or already aggregated; rows with the same student_id are summed.
 */
final class Ranker
{
    /** Result tiers, best first. Results with equal default weights share a tier. */
    public const DEFAULT_RESULT_TIERS = [
        ['juara_1', 'medali_emas'],
        ['juara_2', 'medali_perak'],
        ['juara_3', 'medali_perunggu'],
        ['finalis'],
        ['semifinalis', 'penghargaan_khusus'],
        ['peserta'],
    ];

    /** @var array<string, int> result key → tier index */
    private readonly array $tierOf;

    private readonly int $tierCount;

    /** @param list<list<string|BackedEnum>> $resultTiers */
    public function __construct(array $resultTiers = self::DEFAULT_RESULT_TIERS)
    {
        $tierOf = [];

        foreach (array_values($resultTiers) as $index => $keys) {
            foreach ($keys as $key) {
                $key = Key::of($key);

                if (isset($tierOf[$key])) {
                    throw new InvalidArgumentException("Capaian '{$key}' ada di lebih dari satu tingkat.");
                }

                $tierOf[$key] = $index;
            }
        }

        if ($tierOf === []) {
            throw new InvalidArgumentException('Daftar tingkat capaian tidak boleh kosong.');
        }

        $this->tierOf = $tierOf;
        $this->tierCount = count($resultTiers);
    }

    /**
     * @param  iterable<array{student_id: int|string, name?: string, points?: string|int|float, results?: iterable<string|BackedEnum>, alumni?: bool}>  $rows
     * @return list<RankedStudent>
     */
    public function rank(iterable $rows): array
    {
        $students = $this->aggregate($rows);

        usort($students, fn (array $a, array $b): int => $this->compare($a, $b));

        $ranked = [];
        $previous = null;

        foreach (array_values($students) as $index => $student) {
            $position = $index + 1;
            $rank = $previous !== null && $this->sameStanding($previous['data'], $student)
                ? $previous['rank']
                : $position;

            $ranked[] = new RankedStudent(
                $rank,
                $position,
                $student['id'],
                $student['name'],
                $student['points'],
                $student['tiers'],
                $student['achievements'],
                $student['alumni'],
            );

            $previous = ['data' => $student, 'rank' => $rank];
        }

        return $ranked;
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return list<array{id: int|string, name: string, points: string, tiers: list<int>, achievements: int, alumni: bool}>
     */
    private function aggregate(iterable $rows): array
    {
        $students = [];

        foreach ($rows as $row) {
            $id = $row['student_id'] ?? throw new InvalidArgumentException('Baris peringkat tanpa student_id.');
            $key = (string) $id;

            $students[$key] ??= [
                'id' => $id,
                'name' => '',
                'points' => '0.00',
                'tiers' => array_fill(0, $this->tierCount, 0),
                'achievements' => 0,
                'alumni' => false,
            ];

            if ($students[$key]['name'] === '' && isset($row['name'])) {
                $students[$key]['name'] = (string) $row['name'];
            }

            $points = Decimal::roundHalfUp(Decimal::nonNegative($row['points'] ?? 0), 2);
            $students[$key]['points'] = bcadd($students[$key]['points'], $points, 2);
            $students[$key]['alumni'] = $students[$key]['alumni'] || (bool) ($row['alumni'] ?? false);

            foreach ($row['results'] ?? [] as $result) {
                $students[$key]['tiers'][$this->tierFor($result)]++;
                $students[$key]['achievements']++;
            }
        }

        return array_values($students);
    }

    private function tierFor(string|BackedEnum $result): int
    {
        $key = Key::of($result);

        return $this->tierOf[$key] ?? throw new InvalidArgumentException("Capaian tidak dikenal: {$key}.");
    }

    /**
     * @param  array{id: int|string, name: string, points: string, tiers: list<int>}  $a
     * @param  array{id: int|string, name: string, points: string, tiers: list<int>}  $b
     */
    private function compare(array $a, array $b): int
    {
        return bccomp($b['points'], $a['points'], 2)
            ?: $b['tiers'] <=> $a['tiers']
            ?: strcmp(mb_strtolower($a['name']), mb_strtolower($b['name']))
            ?: strcmp((string) $a['id'], (string) $b['id']);
    }

    /**
     * @param  array{points: string, tiers: list<int>}  $a
     * @param  array{points: string, tiers: list<int>}  $b
     */
    private function sameStanding(array $a, array $b): bool
    {
        return bccomp($a['points'], $b['points'], 2) === 0 && $a['tiers'] === $b['tiers'];
    }
}
