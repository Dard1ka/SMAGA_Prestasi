<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Enums\Bidang;
use App\Enums\Permission;
use App\Models\Achievement;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The public-safe facts of one achievement that Humas may show (SECURITY §3m-7): name, class, capaian,
 * lomba, tingkat, pembimbing. Never NIS/NISN, birth date, phone or email. Used by the Humas screens,
 * the caption suggestion and FlyerData ("Data untuk flyer") so all three say the same thing.
 *
 * `named` = the student may be named (NamedStudent: not deleted, account not disabled; an alumnus stays named). The
 * consent flags are gone since D-162 (20 Okt 2026): every other student is named for every viewer.
 */
final readonly class AchievementFacts
{
    public function __construct(
        public string $studentName,
        public ?string $className,
        public string $resultLabel,
        public ?string $levelLabel,
        public string $competitionName,
        public ?string $mentorShort,
        public ?string $mentorFull,
        public bool $named,
        public ?Bidang $bidang,
        public string $neutralName = 'Siswa',
    ) {}

    /** Roman class level of the neutral label, as on the public pages (PublicAchievements). */
    private const ROMAN = [10 => 'X', 11 => 'XI', 12 => 'XII'];

    /** Relations to eager-load before calling of() on a collection (Model::shouldBeStrict()). */
    public const RELATIONS = [
        'student.user',
        'student.classroom',
        'competition',
        'report.registration.mentor.staffProfile',
    ];

    public static function of(Achievement $achievement): self
    {
        $achievement->loadMissing(self::RELATIONS);

        $student = $achievement->student;
        $competition = $achievement->competition;
        $mentor = $achievement->report?->registration?->mentor;

        return new self(
            studentName: trim((string) ($student?->user?->name ?? 'Siswa')),
            className: $student?->classroom?->name,
            resultLabel: $achievement->result->label(),
            levelLabel: $achievement->level?->label() ?? $competition?->level?->label(),
            competitionName: trim((string) ($competition?->name ?? 'Lomba')),
            mentorShort: self::mentorName($mentor, withDegree: false),
            mentorFull: self::mentorName($mentor, withDegree: true),
            named: NamedStudent::allows($student),
            bidang: $competition?->bidang,
            neutralName: self::neutralNameOf($student),
        );
    }

    /**
     * The facts $viewer may see. A student who may not be named (disabled or deleted account) keeps name and class
     * only for `student.view` (Kesiswaan, Admin); everyone else (Humas) gets the neutral label of the public pages
     * and no class (SECURITY §3i).
     */
    public function visibleTo(User $viewer): self
    {
        if ($this->named || $viewer->can(Permission::StudentView->value)) {
            return $this;
        }

        return $this->anonymous();
    }

    /** The same achievement without its student: neutral label, no class, `named` false. */
    public function anonymous(): self
    {
        return new self(
            studentName: $this->neutralName,
            className: null,
            resultLabel: $this->resultLabel,
            levelLabel: $this->levelLabel,
            competitionName: $this->competitionName,
            mentorShort: $this->mentorShort,
            mentorFull: $this->mentorFull,
            named: false,
            bidang: $this->bidang,
            neutralName: $this->neutralName,
        );
    }

    /** "Juara I Nasional" (card chip). */
    public function headline(): string
    {
        return trim($this->resultLabel.' '.($this->levelLabel === 'Kota/Kabupaten' ? 'Kota/Kab' : (string) $this->levelLabel));
    }

    /** Suggested caption (mockup Humas-Publikasi); Humas edits it before scheduling. */
    public function suggestedCaption(): string
    {
        $who = $this->studentName.($this->className !== null ? ' ('.$this->className.')' : '');
        $what = $this->resultLabel.' '.$this->competitionName
            .($this->levelLabel !== null ? ' tingkat '.mb_strtolower($this->levelLabel) : '');
        $thanks = $this->mentorShort !== null ? ' Terima kasih '.$this->mentorShort.' atas bimbingannya.' : '';

        return 'Selamat kepada '.$who.', '.$what.'.'.$thanks."\n#SMAGASpectacular #SMAN3Semarang";
    }

    /** "Siswa kelas XI", "Alumni" or "Siswa": the label PublicAchievements uses for a student who may not be named. */
    private static function neutralNameOf(?Student $student): string
    {
        if ($student?->user?->isArchived() === true) {
            return 'Alumni';
        }

        $roman = self::ROMAN[(int) $student?->classroom?->level] ?? null;

        return $roman !== null ? 'Siswa kelas '.$roman : 'Siswa';
    }

    private static function mentorName(?User $mentor, bool $withDegree): ?string
    {
        if ($mentor === null) {
            return null;
        }

        $name = trim((string) $mentor->name);
        $name = $withDegree ? $name : trim(Str::before($name, ','));
        $honorific = trim((string) ($mentor->staffProfile?->honorific ?? ''));

        if ($name === '') {
            return null;
        }

        return $honorific !== '' ? $honorific.' '.$name : $name;
    }
}
