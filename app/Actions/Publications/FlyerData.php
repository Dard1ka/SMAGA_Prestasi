<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Models\Achievement;
use App\Models\Student;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * "Data untuk flyer" on Humas › Antrean publikasi (M3-E): the facts Humas types into the flyer it designs in
 * its own tools. STH draws no flyer. Same source as the cards and the caption (AchievementFacts), plus cabang,
 * penyelenggara, bidang, the lomba dates, the research title and, for a team, every member with class.
 *
 * Every student is named (no consent since D-162, 20 Okt 2026) except one NamedStudent leaves out (disabled or
 * deleted account): the achievement's own student then becomes the neutral label of AchievementFacts, a team member
 * is left out of the list and only counted in a note. STH offers no photo. Never NIS/NISN, birth date, phone, email
 * or points.
 */
final readonly class FlyerData
{
    /** Relations of() reads (Model::shouldBeStrict(): no lazy loading). */
    public const RELATIONS = [
        ...AchievementFacts::RELATIONS,
        'report.achievements.student.user',
        'report.achievements.student.classroom',
    ];

    /**
     * `rows` is what "Salin data" copies, one fact per line ("Capaian: Juara II"); a row with `list` puts the
     * label on its own line and then one value per line (the team). `notes` are sentences for Humas about who is
     * left out, shown outside the copied block.
     *
     * @param  list<array{label: string, values: list<string>, list: bool}>  $rows
     * @param  list<string>  $notes
     */
    public function __construct(
        public array $rows,
        public array $notes,
    ) {}

    public static function of(Achievement $achievement): self
    {
        $achievement->loadMissing(self::RELATIONS);

        $facts = AchievementFacts::of($achievement);
        $facts = $facts->named ? $facts : $facts->anonymous();

        $report = $achievement->report;
        $registration = $report?->registration;
        $competition = $achievement->competition;

        $rows = [];
        $notes = [];
        $team = $report?->achievements ?? collect();
        $named = [];

        if ($team->count() > 1) {
            // Ketua first, then by name.
            $leaderId = (string) $registration?->leader_student_id;
            $members = $team
                ->map(fn (Achievement $member): ?Student => $member->student)
                ->filter(fn (?Student $student): bool => NamedStudent::allows($student))
                ->sortBy(fn (Student $student): string => ((string) $student->getKey() === $leaderId ? '0' : '1').mb_strtolower(self::nameOf($student)))
                ->values();

            foreach ($members as $student) {
                $class = self::clean($student->classroom?->name);
                $named[] = self::nameOf($student).($class !== null ? ' ('.$class.')' : '');
            }

            $hidden = $team->count() - count($named);

            if ($hidden > 0) {
                $notes[] = $hidden.' anggota tim tidak ditampilkan karena akunnya nonaktif.';
            }
        }

        if ($named !== []) {
            $rows[] = ['label' => 'Anggota tim', 'values' => $named, 'list' => true];
        } else {
            // One student, or a team in which nobody may be named.
            $rows[] = self::row('Nama', $facts->studentName);
            $rows[] = self::row('Kelas', $facts->className);

            if (! $facts->named && $team->count() <= 1) {
                $notes[] = 'Akun siswa ini nonaktif, jadi namanya tidak ditampilkan.';
            }
        }

        $rows[] = self::row('Capaian', $facts->resultLabel);
        $rows[] = self::row('Lomba', $facts->competitionName);
        $rows[] = self::row('Cabang', $registration?->branch);
        $rows[] = self::row('Tingkat', $facts->levelLabel);
        $rows[] = self::row('Penyelenggara', $competition?->organizer_name);
        $rows[] = self::row('Bidang', $facts->bidang?->label());
        $rows[] = self::row('Tanggal lomba', self::dateRange($competition?->starts_on, $competition?->final_on));
        $rows[] = self::row('Guru pembimbing', $facts->mentorFull);
        $rows[] = self::row('Judul penelitian', $report?->research_title);

        return new self(array_values(array_filter($rows)), $notes);
    }

    /** @return array{label: string, values: list<string>, list: bool}|null null when the fact is not known */
    private static function row(string $label, ?string $value): ?array
    {
        $value = self::clean($value);

        return $value !== null ? ['label' => $label, 'values' => [$value], 'list' => false] : null;
    }

    /** One line of text: no line breaks or runs of spaces inside a fact. */
    private static function clean(?string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value !== '' ? $value : null;
    }

    private static function nameOf(Student $student): string
    {
        return self::clean($student->user?->name) ?? 'Siswa';
    }

    /** "7 November 2026", "5–7 November 2026", "30 Oktober – 2 November 2026"; null when no date is known. */
    private static function dateRange(?DateTimeInterface $start, ?DateTimeInterface $final): ?string
    {
        $start ??= $final;
        $final ??= $start;

        if ($start === null || $final === null) {
            return null;
        }

        $from = CarbonImmutable::instance($start)->locale('id');
        $to = CarbonImmutable::instance($final)->locale('id');

        if (! $from->isBefore($to->startOfDay())) {
            return $to->translatedFormat('j F Y');
        }

        if ($from->isSameMonth($to)) {
            return $from->translatedFormat('j').'–'.$to->translatedFormat('j F Y');
        }

        return $from->translatedFormat($from->isSameYear($to) ? 'j F' : 'j F Y').' – '.$to->translatedFormat('j F Y');
    }
}
