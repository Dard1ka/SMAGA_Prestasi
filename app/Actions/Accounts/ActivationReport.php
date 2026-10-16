<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * K9 activation report per class (docs/SECURITY.md §3a, B1) for Admin and Kesiswaan: name, class, NISN (in full
 * since D-131, lead 2026-10-11: both roles work with the class lists, also in the CSV), state
 * (belum/aktif/kedaluwarsa/dibekukan/nonaktif/arsip), activation time, last login, masked email and automatic
 * flags (activation outside school hours, many failed logins, "Bukan saya?" report).
 * Read-only; ~1 100 students fit in memory, so states are derived in PHP (same rules as the login controls).
 * Not flagged yet (needs data B1 does not store): activation from outside the school network, ≥ 3 accounts
 * activated from one browser session within an hour.
 */
final class ActivationReport
{
    /** School hours for the "outside school hours" flag (Asia/Jakarta, Monday–Friday). */
    public const SCHOOL_DAY_START = '06:30';

    public const SCHOOL_DAY_END = '15:30';

    public const FLAG_OUTSIDE_HOURS = 'Aktivasi di luar jam sekolah';

    public const FLAG_NOT_ME = 'Dilaporkan "Bukan saya"';

    /**
     * @return Collection<int, array{
     *     userId: int, name: string, nisn: string, classroomId: ?string, classroom: string, level: int,
     *     state: string, email: ?string, activatedAt: ?CarbonImmutable, lastLoginAt: ?CarbonImmutable,
     *     expiresAt: ?CarbonImmutable, frozenReason: ?string, attempts: int, flags: list<string>, sort: string,
     * }>
     */
    public function rows(?string $classroomId = null, ?string $state = null, string $search = ''): Collection
    {
        $now = CarbonImmutable::now();
        $search = trim($search);

        $rows = Student::query()
            ->when($classroomId !== null && $classroomId !== '', fn ($q) => $q->where('classroom_id', $classroomId))
            ->when($search !== '' && ctype_digit($search), fn ($q) => $q->where('nisn', $search))
            ->when($search !== '' && ! ctype_digit($search), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->with([
                'classroom:id,name,level',
                'user' => fn ($q) => $q->select([
                    'id', 'name', 'email', 'status', 'must_change_password', 'initial_password_expires_at',
                    'failed_activation_attempts', 'frozen_at', 'frozen_reason', 'activated_at', 'last_login_at',
                ]),
            ])
            ->get(['id', 'user_id', 'nisn', 'classroom_id'])
            ->filter(fn (Student $s): bool => $s->user !== null)
            ->map(fn (Student $s): array => $this->row($s, $now))
            ->when($state !== null && $state !== '', fn (Collection $c) => $c->where('state', $state))
            ->sortBy('sort')
            ->values();

        return $rows;
    }

    /**
     * Counts per class (every class with at least one student), ordered like Classroom::ordered().
     *
     * @return Collection<int, array{classroomId: ?string, classroom: string, level: int, total: int, counts: array<string, int>, activePct: int}>
     */
    public function summary(?Collection $rows = null): Collection
    {
        return ($rows ?? $this->rows())
            ->groupBy(fn (array $r): string => (string) $r['classroomId'])
            ->map(function (Collection $group): array {
                $first = $group->first();
                $counts = array_fill_keys(array_keys(AccountControls::STATES), 0);

                foreach ($group as $row) {
                    $counts[$row['state']]++;
                }

                $relevant = $group->count() - $counts[AccountControls::STATE_ARCHIVED] - $counts[AccountControls::STATE_DISABLED];

                return [
                    'classroomId' => $first['classroomId'],
                    'classroom' => $first['classroom'],
                    'level' => $first['level'],
                    'total' => $group->count(),
                    'counts' => $counts,
                    'activePct' => $relevant > 0 ? (int) floor($counts[AccountControls::STATE_ACTIVE] * 100 / $relevant) : 0,
                    'sort' => $first['sort'],
                ];
            })
            ->sortBy('sort')
            ->values();
    }

    /** @return array<string, int> totals per state over the given rows */
    public static function totals(Collection $rows): array
    {
        $totals = array_fill_keys(array_keys(AccountControls::STATES), 0);

        foreach ($rows as $row) {
            $totals[$row['state']]++;
        }

        return $totals;
    }

    /**
     * K7: failed logins with the owner's initial password on an already active account (W1-C logs them), last 7 days.
     *
     * @return Collection<int, array{name: string, classroom: ?string, at: CarbonImmutable}>
     */
    public function initialPasswordAlerts(int $days = 7): Collection
    {
        $activities = Activity::query()
            ->where('event', 'security.initial_password_on_active')
            ->where('created_at', '>=', now()->subDays($days))
            ->latest('created_at')
            ->limit(50)
            ->get(['subject_id', 'created_at']);

        $users = User::query()
            ->whereIn('id', $activities->pluck('subject_id')->filter()->unique()->values())
            ->with('student.classroom:id,name')
            ->get(['id', 'name'])
            ->keyBy('id');

        return $activities
            ->map(function (Activity $activity) use ($users): ?array {
                $user = $users->get((int) $activity->subject_id);

                return $user === null ? null : [
                    'name' => (string) $user->name,
                    'classroom' => $user->student?->classroom?->name,
                    'at' => CarbonImmutable::instance($activity->created_at),
                ];
            })
            ->filter()
            ->values();
    }

    /** CSV export (`;`-separated, UTF-8 BOM, formula-safe cells) written to the given stream. */
    public function writeCsv(Collection $rows, mixed $stream): void
    {
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Kelas', 'Nama', 'NISN', 'Status', 'Email', 'Aktivasi', 'Login terakhir', 'Batas kata sandi awal', 'Catatan'], ';', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, array_map(self::cell(...), [
                $row['classroom'],
                $row['name'],
                $row['nisn'],
                AccountControls::stateLabel($row['state']),
                $row['email'] ?? '',
                $row['activatedAt']?->format('Y-m-d H:i') ?? '',
                $row['lastLoginAt']?->format('Y-m-d H:i') ?? '',
                $row['expiresAt']?->format('Y-m-d H:i') ?? '',
                implode('; ', $row['flags']),
            ]), ';', '"', '');
        }
    }

    /** Spreadsheet cell text: values a spreadsheet would read as a formula get a leading apostrophe (OWASP CSV injection). */
    public static function cell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r", '|', '%'], true) ? "'".$value : $value;
    }

    public static function outsideSchoolHours(?CarbonImmutable $at): bool
    {
        if ($at === null) {
            return false;
        }

        $local = $at->setTimezone('Asia/Jakarta');
        $time = $local->format('H:i');

        return $local->isWeekend() || $time < self::SCHOOL_DAY_START || $time > self::SCHOOL_DAY_END;
    }

    /** @return array<string, mixed> */
    private function row(Student $student, CarbonImmutable $now): array
    {
        /** @var User $user */
        $user = $student->user;
        $state = AccountControls::state($user, $now);
        $activatedAt = AccountControls::date($user, 'activated_at');
        $frozenReason = AccountControls::frozenReason($user);
        $attempts = AccountControls::failedAttempts($user);
        $classroom = $student->classroom;

        $flags = [];

        if ($state === AccountControls::STATE_ACTIVE && self::outsideSchoolHours($activatedAt)) {
            $flags[] = self::FLAG_OUTSIDE_HOURS;
        }

        if ($frozenReason === AccountControls::FROZEN_NOT_ME) {
            $flags[] = self::FLAG_NOT_ME;
        } elseif ($attempts >= 5 && $state !== AccountControls::STATE_ACTIVE) {
            $flags[] = 'Gagal masuk '.$attempts.' kali';
        }

        $level = (int) ($classroom?->level ?? 99);
        $classroomName = (string) ($classroom?->name ?? 'Tanpa kelas');

        return [
            'userId' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'nisn' => (string) $student->nisn,
            'classroomId' => $classroom?->getKey(),
            'classroom' => $classroomName,
            'level' => $level,
            'state' => $state,
            'stateLabel' => AccountControls::stateLabel($state),
            'tone' => AccountControls::stateTone($state),
            'email' => AccountControls::maskEmail($user->email),
            'activatedAt' => $activatedAt,
            'lastLoginAt' => AccountControls::date($user, 'last_login_at'),
            'expiresAt' => (bool) $user->must_change_password ? AccountControls::date($user, 'initial_password_expires_at') : null,
            'frozenReason' => $frozenReason,
            'attempts' => $attempts,
            'flags' => $flags,
            // Classroom::ordered(): level, then natural name order (X-2 before X-10); then student name.
            'sort' => sprintf('%02d|%03d|%s|%s', $level, mb_strlen($classroomName), $classroomName, mb_strtolower((string) $user->name)),
        ];
    }

    /** @return Collection<string, string> classroom id => name, for the filter */
    public static function classOptions(): Collection
    {
        return Classroom::query()->ordered()->whereHas('students')->pluck('name', 'id');
    }
}
