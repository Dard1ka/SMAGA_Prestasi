<?php

declare(strict_types=1);

namespace App\Notifications\Verification;

use App\Actions\Publications\NamedStudent;
use App\Models\Achievement;
use Illuminate\Database\Eloquent\Builder;

/**
 * To Humas: a verified achievement (n members) is in the publication queue (status siap).
 *
 * QA10: the queue only lists students who may be named (NamedStudent: not deleted, not disabled; no consent since
 * D-162), so the notice counts only those members of the report and is not sent at all when there is none
 * (`shouldSend()`): Humas is never mailed about a queue that shows nothing. The caller (VerifyReport) needs no change.
 */
final class AchievementReadyForPublication extends Notice
{
    /** Members of the report Humas may name (0 = nothing in the queue, nothing sent). */
    public int $namedMembers = 0;

    public static function for(Achievement $achievement, int $members): self
    {
        $named = self::namedMembers($achievement);
        $competition = (string) ($achievement->competition?->name ?? 'lomba');
        $who = match (true) {
            $named <= 1 => 'Satu siswa',
            $named === $members => 'Tim '.$named.' siswa',
            default => $named.' siswa',
        };

        $notice = new self(
            kind: 'achievement.ready_for_publication',
            title: 'Prestasi baru siap dipublikasikan',
            body: $who.' meraih '.$achievement->result->label().' di '.$competition.'.',
            routeName: 'humas.beranda',
            actionLabel: 'Buka antrean publikasi',
            extra: ['report_id' => (string) $achievement->report_id],
        );
        $notice->namedMembers = $named;

        return $notice;
    }

    /** Mail and bell only when the queue shows at least one of these achievements. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $this->namedMembers > 0;
    }

    /** Verified achievements of the same report whose student may be named (the rows of the Humas queue). */
    private static function namedMembers(Achievement $achievement): int
    {
        if ($achievement->report_id === null) {
            return 0;
        }

        return Achievement::query()
            ->where('report_id', $achievement->report_id)
            ->whereNotNull('verified_at')
            ->whereHas('student', fn (Builder $student) => NamedStudent::constrain($student))
            ->count();
    }
}
