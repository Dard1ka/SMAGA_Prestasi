<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\Activitylog\Models\Activity;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * K9 activation report per class (docs/SECURITY.md §3a, B1) for Admin and Kesiswaan: name, class, NISN (in full
 * since D-131, lead 2026-10-11: both roles work with the class lists, also in the Excel export), state
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

    /** A freeze an Admin set by hand (FreezeAccount). */
    public const FLAG_FROZEN_ADMIN = AccountControls::FROZEN_ADMIN_LABEL;

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

    /** Header row of every sheet of the Excel export (the CSV columns before SV3). */
    public const HEADER = ['Kelas', 'Nama', 'NISN', 'Status', 'Email', 'Aktivasi', 'Login terakhir', 'Batas kata sandi awal', 'Catatan'];

    /** Sheet name per tingkat (classrooms.level) of the Excel export, in sheet order (SV3, lead 2026-11-03). */
    public const LEVELS = [10 => 'X', 11 => 'XI', 12 => 'XII'];

    /** Extra sheet for a student whose class has no tingkat of LEVELS: never dropped from a whole-school file. */
    public const NO_LEVEL_SHEET = 'Tanpa kelas';

    /**
     * First sheet of a whole-school file (SV6, D-185, replacing "sheet kosong tetap ada" of D-174): one row per class with
     * its student count and the count per status, then the total row SUMMARY_TOTAL.
     */
    public const SUMMARY_SHEET = 'Ringkasan';

    public const SUMMARY_TOTAL = 'Semua kelas';

    /** Column widths of the export in Excel characters, in HEADER order. */
    private const WIDTHS = [12, 32, 14, 16, 28, 18, 18, 22, 40];

    /** Column widths of SUMMARY_SHEET, in summaryHeader() order. */
    private const SUMMARY_WIDTHS = [14, 14, 16, 10, 14, 12, 12, 12];

    /** @return list<string> header of SUMMARY_SHEET: Kelas, Jumlah siswa, then the six states in the order of Status aktivasi */
    public static function summaryHeader(): array
    {
        return ['Kelas', 'Jumlah siswa', ...array_values(array_column(AccountControls::STATES, 'label'))];
    }

    /**
     * Excel export written to $path (SV3, replaces the CSV; SV6, D-185 changed the whole-school file):
     * - $classroom set (the name of the one class the rows come from) = one sheet named after that class;
     * - else $level set = that one tingkat sheet;
     * - else (the whole school) = SUMMARY_SHEET first, then one sheet per tingkat of LEVELS that has rows (an empty
     *   tingkat no longer gets a header-only sheet: Excel opened the file on an empty first sheet), plus NO_LEVEL_SHEET
     *   when a row has no such tingkat.
     * Rows keep the order of rows() (class, then name). Every cell is formula-safe text, so the NISN keeps its zeros.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function writeXlsx(Collection $rows, string $path, ?int $level = null, ?string $classroom = null): void
    {
        $byLevel = $rows->groupBy(fn (array $row): string => (string) (array_key_exists((int) $row['level'], self::LEVELS) ? $row['level'] : ''));

        if ($classroom !== null) {
            $sheets = [self::sheetName($classroom) => $rows];
        } elseif ($level !== null) {
            $sheets = [self::LEVELS[$level] => $byLevel->get((string) $level, collect())];
        } else {
            $sheets = collect(self::LEVELS)
                ->filter(fn (string $name, int $l): bool => $byLevel->has((string) $l))
                ->mapWithKeys(fn (string $name, int $l): array => [$name => $byLevel->get((string) $l)])
                ->all();

            if ($byLevel->has('')) {
                $sheets[self::NO_LEVEL_SHEET] = $byLevel->get('');
            }
        }

        $writer = SimpleExcelWriter::create($path);
        $text = (new Style)->setFormat('@');
        $bold = (new Style)->setFontBold();
        $first = true;

        $newSheet = function (string $name, array $widths) use ($writer, &$first): void {
            if (! $first) {
                $writer->getWriter()->addNewSheetAndMakeItCurrent();
            }

            $first = false;
            $sheet = $writer->getWriter()->getCurrentSheet();
            $sheet->setName($name);

            foreach ($widths as $i => $width) {
                $sheet->setColumnWidth($width, $i + 1);
            }
        };
        $textRow = fn (array $values, ?Style $style = null): Row => new Row(array_map(
            fn (mixed $value): StringCell => new StringCell(self::cell($value), $style ?? $text),
            $values,
        ));

        if ($classroom === null && $level === null) {
            $newSheet(self::SUMMARY_SHEET, self::SUMMARY_WIDTHS);
            $writer->addRow(Row::fromValues(self::summaryHeader(), $bold));

            foreach ($this->summary($rows) as $class) {
                $writer->addRow($textRow([$class['classroom'], (string) $class['total'], ...array_map(strval(...), array_values($class['counts']))]));
            }

            $writer->addRow($textRow([self::SUMMARY_TOTAL, (string) $rows->count(), ...array_map(strval(...), array_values(self::totals($rows)))], $bold));
        }

        foreach ($sheets as $name => $sheetRows) {
            $newSheet((string) $name, self::WIDTHS);
            $writer->addRow(Row::fromValues(self::HEADER, $bold));

            foreach ($sheetRows as $row) {
                $writer->addRow($textRow([
                    (string) $row['classroom'],
                    (string) $row['name'],
                    (string) $row['nisn'],
                    AccountControls::stateLabel($row['state']),
                    (string) ($row['email'] ?? ''),
                    $row['activatedAt']?->format('Y-m-d H:i') ?? '',
                    $row['lastLoginAt']?->format('Y-m-d H:i') ?? '',
                    $row['expiresAt']?->format('Y-m-d H:i') ?? '',
                    implode('; ', $row['flags']),
                ]));
            }
        }

        $writer->close();
    }

    /** A class name as an Excel sheet name: no : \ / ? * [ ], at most 31 characters, never empty. */
    public static function sheetName(string $name): string
    {
        $name = trim((string) preg_replace('/[:\\\\\/?*\[\]]+/u', ' ', $name));

        return mb_substr($name !== '' ? $name : 'Kelas', 0, 31);
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
        } elseif ($frozenReason === AccountControls::FROZEN_ADMIN) {
            $flags[] = self::FLAG_FROZEN_ADMIN;
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
            // Classroom::ordered(): level, then shorter class names first (X-1 … X-10 before X-IPA 1), then name; then student name.
            'sort' => sprintf('%02d|%03d|%s|%s', $level, mb_strlen($classroomName), $classroomName, mb_strtolower((string) $user->name)),
        ];
    }

    /** @return Collection<string, string> classroom id => name, for the filter */
    public static function classOptions(): Collection
    {
        return Classroom::query()->ordered()->whereHas('students')->pluck('name', 'id');
    }
}
