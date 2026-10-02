<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Enums\Bidang;
use App\Enums\Permission;
use App\Models\Achievement;
use App\Models\Student;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Support\Str;

/**
 * The public-safe facts of one achievement that Humas may show (SECURITY §3m-7): name, class, capaian,
 * lomba, tingkat, pembimbing. Never NIS/NISN, birth date, phone or email. Used by the Humas screens,
 * the caption suggestion and GenerateFlyer so all three say the same thing.
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
        public bool $photoConsent,
        public bool $publishConsent,
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
            photoConsent: (bool) ($student?->photo_consent ?? false),
            publishConsent: (bool) ($student?->publish_consent ?? false),
            bidang: $competition?->bidang,
            neutralName: self::neutralNameOf($student),
        );
    }

    /**
     * The facts $viewer may see. Without publish consent only `student.view` (Kesiswaan, Admin) keeps the
     * student's name and class; everyone else (Humas) gets the neutral label of the public pages, no class
     * and no photo (SECURITY §3i, §3m-7). `publishConsent` stays false so screens can show "Izin dicabut".
     */
    public function visibleTo(User $viewer): self
    {
        if ($this->publishConsent || $viewer->can(Permission::StudentView->value)) {
            return $this;
        }

        return new self(
            studentName: $this->neutralName,
            className: null,
            resultLabel: $this->resultLabel,
            levelLabel: $this->levelLabel,
            competitionName: $this->competitionName,
            mentorShort: $this->mentorShort,
            mentorFull: $this->mentorFull,
            photoConsent: false,
            publishConsent: false,
            bidang: $this->bidang,
            neutralName: $this->neutralName,
        );
    }

    /** "Juara I Nasional" (card chip). */
    public function headline(): string
    {
        return trim($this->resultLabel.' '.($this->levelLabel === 'Kota/Kabupaten' ? 'Kota/Kab' : (string) $this->levelLabel));
    }

    /** "Tingkat Nasional". */
    public function levelLine(): ?string
    {
        return $this->levelLabel !== null ? 'Tingkat '.$this->levelLabel : null;
    }

    /** "Kelas XI-4". */
    public function classLine(): ?string
    {
        return $this->className !== null ? 'Kelas '.$this->className : null;
    }

    /** "Pembimbing: Bu Retno Wulandari, S.Pd." (flyer). */
    public function mentorLine(): ?string
    {
        return $this->mentorFull !== null ? 'Pembimbing: '.$this->mentorFull : null;
    }

    public function initials(): string
    {
        return Navigation::initials($this->studentName) ?: 'S';
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

    /** File-name slug of the student's name ("raka-pradipta"). */
    public function slug(): string
    {
        return Str::slug($this->studentName) ?: 'prestasi';
    }

    /** "Siswa kelas XI", "Alumni" or "Siswa": the label PublicAchievements uses for a student without consent. */
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
