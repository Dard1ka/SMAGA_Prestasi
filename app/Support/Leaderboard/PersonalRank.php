<?php

declare(strict_types=1);

namespace App\Support\Leaderboard;

use App\Domain\Leaderboard\Ranker;
use App\Domain\Support\Decimal;
use App\Enums\UserStatus;
use App\Models\Achievement;
use App\Models\Student;

/**
 * A student's own standing ("peringkatmu": siswa Beranda, Portofolio, public student page). Lead decision
 * 2026-10-01 (D-37): cumulative points of all years, ranked among ACTIVE students only — accounts that can
 * log in (`active`, `pending_activation`), not soft-deleted. Alumni (archived) and disabled accounts are not
 * ranked here. The public leaderboard (PublicAchievements::ranking) shows alumni only when asked: its status
 * filter "Alumni" or "Semua" (RankingScope, D-09). Same Ranker everywhere, so ties share a rank.
 */
final class PersonalRank
{
    /** Account states counted as "siswa aktif". */
    public const ACTIVE_STATUSES = [UserStatus::Active, UserStatus::PendingActivation];

    public function __construct(private readonly Ranker $ranker = new Ranker) {}

    /**
     * @return array{total: string, rank: int|null, count: int} total = decimal string ("104.00"); rank null
     *                                                          without verified achievements
     */
    public function summary(Student|string $student): array
    {
        $id = $student instanceof Student ? (string) $student->getKey() : $student;
        $points = Achievement::query()->where('student_id', $id)->pluck('points');
        $total = $points->reduce(fn (string $sum, mixed $value): string => bcadd($sum, Decimal::normalize((string) $value), 2), '0.00');

        return [
            'total' => $total,
            'rank' => $points->isEmpty() ? null : $this->rank($id),
            'count' => $points->count(),
        ];
    }

    /** Rank among active students; null when the student has no verified achievement or is not active. */
    public function rank(Student|string $student): ?int
    {
        $id = $student instanceof Student ? (string) $student->getKey() : $student;

        $rows = Achievement::query()
            ->join('students', 'students.id', '=', 'achievements.student_id')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->whereNull('students.deleted_at')
            ->whereNull('users.deleted_at')
            ->whereIn('users.status', array_map(fn (UserStatus $status): string => $status->value, self::ACTIVE_STATUSES))
            ->get(['achievements.student_id', 'achievements.points', 'achievements.result', 'users.name'])
            ->map(fn (Achievement $row): array => [
                'student_id' => (string) $row->student_id,
                'name' => (string) $row->getAttribute('name'),
                'points' => (string) $row->points,
                'results' => [$row->result],
            ]);

        foreach ($this->ranker->rank($rows) as $ranked) {
            if ((string) $ranked->studentId === $id) {
                return $ranked->rank;
            }
        }

        return null;
    }
}
