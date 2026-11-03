<?php

declare(strict_types=1);

namespace App\Livewire\Siswa;

use App\Actions\Publications\NamedStudent;
use App\Domain\Archive\SchoolYear;
use App\Domain\Leaderboard\Ranker;
use App\Domain\Leaderboard\RankingScope;
use App\Domain\Points\PointFormatter;
use App\Enums\Bidang;
use App\Enums\UserStatus;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Achievement;
use App\Models\Student;
use App\Models\User;
use App\Support\Leaderboard\PersonalRank;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Siswa · Peringkat (UI-MAP §3 sidebar "Peringkat", backlog #2, owner B4). The cumulative ranking of the
 * public page — PublicAchievements::ranking(), so the same naming rule applies (NamedStudent; no consent since
 * D-162) — restricted to ACTIVE students (RankingScope::Active, no alumni choice here), so the
 * "Kamu" row carries the same rank as Beranda, Portofolio and the public student page (PersonalRank, D-37).
 * Modes siswa/kelas, filters bidang and angkatan (only the years of active students are offered).
 *
 * The student's own row is found without exposing anyone's id: by the random public slug when the student is
 * named, otherwise by rank + points + count + the anonymous label (rows that match all of these are
 * indistinguishable). The own rank under a filter comes from the same Ranker over the same active set.
 */
#[Title('Peringkat')]
#[Layout('layouts::app', ['subtitle' => 'Akumulasi poin dari semua lomba yang sudah diverifikasi.'])]
final class Peringkat extends Component
{
    public const STEP = 20;

    public const MAX = 200;

    private const ROMAN = [10 => 'X', 11 => 'XI', 12 => 'XII'];

    #[Url(except: 'siswa')]
    public string $mode = 'siswa';

    #[Url(except: '')]
    public string $bidang = '';

    #[Url(except: '')]
    public string $angkatan = '';

    #[Url(except: self::STEP)]
    public int $limit = self::STEP;

    public function mount(): void
    {
        Gate::authorize('viewAny', Achievement::class);
        abort_if($this->student() === null, 404);

        $this->normalize();
    }

    public function updated(string $property): void
    {
        if ($property !== 'limit') {
            $this->limit = self::STEP;
        }

        $this->normalize();
    }

    public function pilihMode(string $mode): void
    {
        $this->mode = $mode;
        $this->limit = self::STEP;
        $this->normalize();
    }

    public function tampilkanLebih(): void
    {
        $this->limit += self::STEP;
        $this->normalize();
    }

    public function resetFilter(): void
    {
        $this->bidang = '';
        $this->angkatan = '';
        $this->limit = self::STEP;
    }

    public function render(PointFormatter $formatter): View
    {
        $this->normalize();

        $student = $this->student();
        abort_if($student === null, 404);

        $data = app(PublicAchievements::class);
        $bidang = Bidang::tryFrom($this->bidang);
        $angkatan = $this->angkatan !== '' ? (int) $this->angkatan : null;
        $isSiswa = $this->mode === 'siswa';

        $rows = $data->ranking($this->mode, $bidang, $angkatan, RankingScope::Active);
        $meIndex = $isSiswa
            ? $this->ownRowIndex($rows, $student, $bidang, $angkatan)
            : $this->ownClassIndex($rows, $student);

        $rows = $this->markOwnRow($rows, $meIndex, $student, $isSiswa);
        $summary = app(PersonalRank::class)->summary($student);
        $visible = array_slice($rows, 0, $this->limit);

        return view('livewire.siswa.peringkat', [
            'isSiswa' => $isSiswa,
            'total' => count($rows),
            'top' => array_slice($visible, 0, 3),
            'rest' => array_slice($visible, 3),
            // Own row beyond the shown part: appended after a gap so "Kamu" is always on screen.
            'meExtra' => $meIndex !== null && $meIndex >= $this->limit ? $rows[$meIndex] : null,
            'me' => $meIndex !== null ? $rows[$meIndex] : null,
            'hasMore' => count($rows) > $this->limit && $this->limit < self::MAX,
            'filtered' => $bidang !== null || $angkatan !== null,
            'summary' => [
                'points' => $formatter->format($summary['total']),
                'rank' => $summary['rank'],
                'count' => $summary['count'],
            ],
            'kelas' => $student->classroom?->name,
            'bidangOptions' => Bidang::options(),
            'angkatanOptions' => $data->angkatanOptions(RankingScope::Active, $this->mode),
            'publicUrl' => route('public.peringkat', ['status' => RankingScope::Alumni->value]),
        ]);
    }

    /**
     * Index of the student's own row in a siswa-mode ranking, or null when the student is not in it.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function ownRowIndex(array $rows, Student $student, ?Bidang $bidang, ?int $angkatan): ?int
    {
        $standing = $this->standing($student, $bidang, $angkatan);

        if ($standing === null) {
            return null;
        }

        $named = NamedStudent::allows($student);
        $slug = $named
            ? Student::query()->whereKey($student->getKey())->value('public_slug')
            : null;

        if (is_string($slug) && $slug !== '') {
            foreach ($rows as $index => $row) {
                if ($row['slug'] === $slug) {
                    return $index;
                }
            }
        }

        $label = $named ? (string) $student->user?->name : $this->anonymousLabel($student);

        foreach ($rows as $index => $row) {
            if ($row['rank'] === $standing['rank']
                && $row['count'] === $standing['count']
                && bccomp((string) $row['points'], $standing['points'], 2) === 0
                && $row['named'] === $named
                && $row['name'] === $label) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Rank, points and count of the student among active students under the same filters as the list
     * (bidang: only that bidang's points; angkatan: year of the grade-12 start - 2, fallback class level).
     *
     * @return array{rank: int, points: string, count: int}|null
     */
    private function standing(Student $student, ?Bidang $bidang, ?int $angkatan): ?array
    {
        $current = SchoolYear::containing(now());

        $rows = Achievement::query()
            ->join('students', 'students.id', '=', 'achievements.student_id')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->join('classrooms', 'classrooms.id', '=', 'students.classroom_id')
            ->join('competitions', 'competitions.id', '=', 'achievements.competition_id')
            ->whereNull('students.deleted_at')
            ->whereNull('users.deleted_at')
            ->whereIn('users.status', array_map(fn (UserStatus $status): string => $status->value, PersonalRank::ACTIVE_STATUSES))
            ->when($bidang !== null, fn ($query) => $query->where('competitions.bidang', $bidang?->value))
            ->get([
                'achievements.student_id', 'achievements.points', 'achievements.result',
                'users.name as u_name', 'students.grade12_start_date as s_grade12', 'classrooms.level as c_level',
            ]);

        $input = [];

        foreach ($rows as $row) {
            if ($angkatan !== null && $this->angkatanOf($row->getAttribute('s_grade12'), (int) $row->getAttribute('c_level'), $current) !== $angkatan) {
                continue;
            }

            $input[] = [
                'student_id' => (string) $row->student_id,
                'name' => (string) $row->getAttribute('u_name'),
                'points' => (string) $row->points,
                'results' => [$row->result],
            ];
        }

        foreach ((new Ranker)->rank($input) as $ranked) {
            if ((string) $ranked->studentId === (string) $student->getKey()) {
                return ['rank' => $ranked->rank, 'points' => $ranked->points, 'count' => $ranked->achievements];
            }
        }

        return null;
    }

    /** Same rule as PublicAchievements: year of grade12_start_date - 2, else from the current class level. */
    private function angkatanOf(mixed $grade12, int $level, SchoolYear $current): ?int
    {
        if ($grade12 !== null && $grade12 !== '') {
            return (int) substr((string) $grade12, 0, 4) - 2;
        }

        return isset(self::ROMAN[$level]) ? $current->startYear - ($level - 10) : null;
    }

    /** @param list<array<string, mixed>> $rows */
    private function ownClassIndex(array $rows, Student $student): ?int
    {
        $name = $student->classroom?->name;

        foreach ($rows as $index => $row) {
            if ($name !== null && $row['kelas'] === $name) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Flags every row with `me` and shows the student's own name on their own row (only they see this page).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function markOwnRow(array $rows, ?int $meIndex, Student $student, bool $isSiswa): array
    {
        foreach ($rows as $index => $row) {
            $rows[$index]['me'] = $index === $meIndex;
        }

        if ($meIndex !== null && $isSiswa) {
            $rows[$meIndex]['name'] = (string) $student->user?->name;
            $rows[$meIndex]['named'] = true;
            $rows[$meIndex]['kelas'] = $student->classroom?->name;
            $rows[$meIndex]['kelas_label'] = $student->classroom?->name;
        }

        return $rows;
    }

    private function anonymousLabel(Student $student): string
    {
        $roman = self::ROMAN[(int) $student->classroom?->level] ?? null;

        return $roman !== null ? 'Siswa kelas '.$roman : 'Siswa';
    }

    private function student(): ?Student
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->loadMissing(['student.classroom', 'student.user'])->getRelation('student');
    }

    private function normalize(): void
    {
        $this->mode = in_array($this->mode, ['siswa', 'kelas'], true) ? $this->mode : 'siswa';

        if ($this->bidang !== '' && Bidang::tryFrom($this->bidang) === null) {
            $this->bidang = '';
        }

        if ($this->angkatan !== '' && ! array_key_exists($this->angkatan, app(PublicAchievements::class)->angkatanOptions(RankingScope::Active, $this->mode))) {
            $this->angkatan = '';
        }

        $this->limit = max(self::STEP, min(self::MAX, $this->limit));
    }
}
