<?php

declare(strict_types=1);

namespace App\Actions\Letters;

use App\Domain\Archive\SchoolYear;
use App\Enums\MemberRole;
use App\Enums\SettingKey;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Setting;

/**
 * Everything both letters print, built from the approved registration (DB) + the TU draft. Shared by the
 * PDF templates (resources/pdf) and the live HTML preview so both show the same text (SECURITY §3g:
 * letter data comes from the database record, not from the request).
 *
 * Structure follows the school's templates (mockup-v2-notes/F.md): student letter = one table
 * NO | NAMA MURID | NIS | KELAS for the whole team; guru letter = Surat Perintah Tugas + Lampiran.
 * Signer name/rank/NIP come from settings (placeholders until Admin fills them), never hard-coded.
 */
final readonly class LetterContent
{
    public const SCHOOL = 'SMA Negeri 3 Semarang';

    public const CITY = 'Semarang';

    /**
     * @param  list<array{no: int, name: string, nis: string, class: string}>  $students
     * @param  array{name: string, rank: string, nip: string, position: string}|null  $mentor
     * @param  array{name: string, rank: string, nip: string}  $signer
     */
    public function __construct(
        public string $competitionName,
        public string $competitionShort,
        public string $organizer,
        public ?string $basisNumber,
        public ?string $basisDateText,
        public array $students,
        public ?array $mentor,
        public string $scheduleDay,
        public string $scheduleTime,
        public string $place,
        public bool $online,
        public string $issuedOnText,
        public string $schoolYear,
        public array $signer,
    ) {}

    /** Needs `competition`, `mentor.staffProfile`, `members.student.{user,classroom}` (loaded here when missing). */
    public static function build(Registration $registration, LetterDraft $draft): self
    {
        $registration->loadMissing(['competition', 'mentor.staffProfile', 'members.student.user', 'members.student.classroom']);
        $competition = $registration->competition;
        $name = (string) ($competition?->name ?? '');
        $branch = trim((string) $registration->branch);

        $members = $registration->members
            ->filter(fn (RegistrationMember $m): bool => $m->confirmed_at !== null && $m->declined_at === null)
            ->sortBy(fn (RegistrationMember $m): string => ($m->role === MemberRole::Ketua ? '0' : '1').mb_strtolower((string) $m->student?->user?->name))
            ->values();

        $students = [];

        foreach ($members as $index => $member) {
            $students[] = [
                'no' => $index + 1,
                'name' => (string) $member->student?->user?->name,
                'nis' => (string) $member->student?->nis,
                'class' => (string) $member->student?->classroom?->name,
            ];
        }

        $mentor = $registration->mentor;
        $place = trim($draft->place);

        return new self(
            competitionName: $branch !== '' ? $name.' cabang '.mb_strtolower($branch) : $name,
            competitionShort: $name,
            organizer: (string) ($competition?->organizer_name ?: 'penyelenggara'),
            basisNumber: self::clean($draft->basisNumber),
            basisDateText: $draft->basisDate?->locale('id')->translatedFormat('j F Y'),
            students: $students,
            mentor: $mentor === null ? null : [
                'name' => (string) $mentor->name,
                'rank' => (string) ($mentor->staffProfile?->rank ?: '-'),
                'nip' => (string) ($mentor->staffProfile?->nip ?: '-'),
                'position' => 'Guru',
            ],
            scheduleDay: trim($draft->scheduleDay),
            scheduleTime: trim($draft->scheduleTime),
            place: $place,
            online: mb_strtolower($place) === 'daring',
            issuedOnText: $draft->issuedOn->locale('id')->translatedFormat('j F Y'),
            schoolYear: SchoolYear::containing($draft->issuedOn)->toString(),
            signer: [
                'name' => (string) Setting::get(SettingKey::SignerName),
                'rank' => (string) Setting::get(SettingKey::SignerRank),
                'nip' => (string) Setting::get(SettingKey::SignerNip),
            ],
        );
    }

    /** "Sabtu, 7 November 2026" → "Sabtu / 7 November 2026" (student template layout). */
    public function dayAndDate(): string
    {
        [$day, $date] = $this->splitDay();

        return $day !== '' ? $day.' / '.$date : $date;
    }

    /** Guru letter, "Untuk" point 1. */
    public function guruTask(): string
    {
        [$day, $date] = $this->splitDay();
        $where = $this->online ? 'secara daring' : 'di '.($this->place !== '' ? $this->place : '[tempat]');

        return 'Melaksanakan tugas kegiatan mendampingi siswa Lomba '.$this->competitionShort
            .' pada hari '.($day !== '' ? $day : '[hari]').' tanggal '.($date !== '' ? $date : '[tanggal]').', '.$where.'.';
    }

    /** Guru letter, "Dasar" point 2. */
    public function guruBasis(): string
    {
        $text = 'Surat dari '.$this->organizer;

        if ($this->basisNumber !== null) {
            $text .= ' Nomor '.$this->basisNumber;
        }

        if ($this->basisDateText !== null) {
            $text .= ' tanggal '.$this->basisDateText;
        }

        return $text.'.';
    }

    public function studentCount(): int
    {
        return count($this->students);
    }

    /** @return array{0: string, 1: string} */
    private function splitDay(): array
    {
        $value = $this->scheduleDay;
        $comma = mb_strpos($value, ',');

        if ($comma === false || $comma === 0) {
            return ['', $value];
        }

        return [trim(mb_substr($value, 0, $comma)), trim(mb_substr($value, $comma + 1))];
    }

    private static function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
