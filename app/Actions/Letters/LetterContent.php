<?php

declare(strict_types=1);

namespace App\Actions\Letters;

use App\Actions\Templates\LetterTemplate;
use App\Domain\Archive\SchoolYear;
use App\Enums\MemberRole;
use App\Enums\SettingKey;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Setting;
use Error;

/**
 * Everything one letter prints, built from the approved registration (DB) + the TU draft of that letter.
 * Shared by the PDF templates (resources/pdf) and the live HTML preview so both show the same text
 * (SECURITY §3g: who is in the letter comes from the database record, not from the request).
 *
 * Structure follows the school's own files (M1-A, lead 2026-10-04): the student letter is the Excel sheet
 * "ST_LOMBA … TIM" (one table NO | NAMA MURID | NIS | KELAS for the whole team, names in capitals, class as
 * "XI - 7", Hari / Tanggal / Pukul / Tempat rows), the guru letter is the Word file "ST PENGUKUHAN KEG OPSI"
 * (Surat Perintah Tugas + Lampiran). Signer name/rank/NIP come from settings, never hard-coded.
 */
final readonly class LetterContent
{
    public const SCHOOL = 'SMA Negeri 3 Semarang';

    public const CITY = 'Semarang';

    /**
     * @param  list<array{no: int, name: string, nis: string, class: string}>  $students  as printed (capitals, "XI - 7")
     * @param  array{name: string, rank: string, nip: string, position: string}|null  $mentor
     * @param  array{name: string, rank: string, nip: string}  $signer
     */
    public function __construct(
        public string $competitionName,
        public string $competitionShort,
        public string $organizer,
        public string $basisFrom,
        public ?string $basisNumber,
        public ?string $basisDateText,
        public string $basisSubject,
        public array $students,
        public ?array $mentor,
        public string $scheduleDay,
        public string $scheduleDate,
        public string $scheduleTime,
        public string $place,
        public bool $online,
        public string $issuedOnText,
        public string $issuedYear,
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
            // A member in the trash of Admin › Kelas (student() excludes trashed rows; the lead, 5 Nov 2026) is not printed.
            ->filter(fn (RegistrationMember $m): bool => $m->confirmed_at !== null && $m->declined_at === null && $m->student !== null)
            ->sortBy(fn (RegistrationMember $m): string => ($m->role === MemberRole::Ketua ? '0' : '1').mb_strtolower((string) $m->student?->user?->name))
            ->values();

        $students = [];

        foreach ($members as $index => $member) {
            $students[] = [
                'no' => $index + 1,
                'name' => mb_strtoupper((string) $member->student?->user?->name),
                'nis' => (string) $member->student?->nis,
                'class' => self::classLabel((string) $member->student?->classroom?->name),
            ];
        }

        $mentor = $registration->mentor;
        $organizer = trim((string) $competition?->organizer_name);
        $place = self::line($draft->place);

        return new self(
            competitionName: $branch !== '' ? $name.' cabang '.mb_strtolower($branch) : $name,
            competitionShort: $name,
            organizer: $organizer !== '' ? $organizer : 'penyelenggara',
            basisFrom: self::line($draft->basisFrom),
            basisNumber: self::clean($draft->basisNumber),
            basisDateText: $draft->basisDate?->locale('id')->translatedFormat('j F Y'),
            basisSubject: self::line($draft->basisSubject),
            students: $students,
            mentor: $mentor === null ? null : [
                'name' => (string) $mentor->name,
                'rank' => (string) ($mentor->staffProfile?->rank ?: '-'),
                'nip' => (string) ($mentor->staffProfile?->nip ?: '-'),
                'position' => (string) ($mentor->staffProfile?->position ?: 'Guru'),
            ],
            scheduleDay: self::line($draft->scheduleDay),
            scheduleDate: self::line($draft->scheduleDate),
            scheduleTime: self::line($draft->scheduleTime),
            place: $place,
            online: mb_strtolower($place) === 'daring',
            issuedOnText: $draft->issuedOn->locale('id')->translatedFormat('j F Y'),
            issuedYear: (string) $draft->issuedOn->year,
            schoolYear: SchoolYear::containing($draft->issuedOn)->toString(),
            signer: [
                'name' => (string) Setting::get(SettingKey::SignerName),
                'rank' => (string) Setting::get(SettingKey::SignerRank),
                'nip' => (string) Setting::get(SettingKey::SignerNip),
            ],
        );
    }

    /**
     * Every field, for `letters.printed['content']` (QA retest 2026-10-06): the preview of an issued letter is
     * rebuilt from this, never again from today's registration and settings.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** The content stored by toArray(); null when $data is not a complete one (wrong or missing fields). */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || array_is_list($data)) {
            return null;
        }

        try {
            return new self(...$data);
        } catch (Error) { // TypeError, ArgumentCountError, unknown named parameter
            return null;
        }
    }

    /** "XI-7" → "XI - 7", the way the school's student letter writes a class. Other names stay as they are. */
    public static function classLabel(string $name): string
    {
        $name = trim($name);

        return preg_match('/^(XII|XI|X)\s*-\s*(\S.*)$/u', $name, $m) === 1 ? $m[1].' - '.$m[2] : $name;
    }

    /** "Lomba <nama>" (student letter, with the cabang); a name that already starts with "Lomba" is not doubled. */
    public function activity(): string
    {
        return self::withLomba($this->competitionName);
    }

    /** "Lomba <nama>" without the cabang (guru letter). */
    public function activityShort(): string
    {
        return self::withLomba($this->competitionShort);
    }

    /**
     * Student letter: "21 September 2026 s.d 24 September 2026" → ["21 September 2026", "24 September 2026"].
     *
     * @return array{0: string, 1: string|null}
     */
    public function dateRange(): array
    {
        return self::range($this->scheduleDate);
    }

    /**
     * Student letter: "07.00 WIB s.d Selesai" → ["07.00 WIB", "Selesai"].
     *
     * @return array{0: string, 1: string|null}
     */
    public function timeRange(): array
    {
        return self::range($this->scheduleTime);
    }

    /**
     * Guru letter, "Dasar": the invitation, after the fixed regulation texts. SV8, D-194: the school's letter writes a day
     * of one digit with a leading zero ("Tanggal 02 November 2026"); $twoDigitDay = false keeps the M1-A text for the
     * preview of a letter printed before (LetterTemplate::GURU_LAYOUT_LAMPIRAN).
     */
    public function guruBasis(bool $twoDigitDay = true): string
    {
        $text = 'Surat dari '.($this->basisFrom !== '' ? $this->basisFrom : '[surat dari]');

        if ($this->basisNumber !== null) {
            $text .= ' Nomor '.$this->basisNumber;
        }

        if ($this->basisDateText !== null) {
            $text .= ' Tanggal '.($twoDigitDay ? self::twoDigitDay($this->basisDateText) : $this->basisDateText);
        }

        if ($this->basisSubject !== '') {
            $text .= ' Hal '.$this->basisSubject;
        }

        return $text;
    }

    /**
     * Student letter, every "Dasar" text in print order: the regulation texts of the template, then the invitation TU
     * typed as the last one (D-157; the template's are a list since SV8, D-195). More than one is numbered 1., 2., …
     * like the guru letter; one alone is printed without a number (D-154). The PDF, the TU preview and the summary of
     * an issued letter (with the template it printed, D-108) all print this list.
     *
     * @return list<string>
     */
    public function siswaBasis(LetterTemplate $template): array
    {
        $invitation = $this->siswaInvitation();

        return [...$template->siswaDasarItems($this->schoolYear), ...($invitation !== null ? [$invitation] : [])];
    }

    /**
     * Student letter, the last "Dasar" (D-157): the invitation TU typed, without "Hal"; null when "Surat dari" is empty,
     * and the letter then prints only the regulation texts of the template.
     */
    public function siswaInvitation(): ?string
    {
        if ($this->basisFrom === '') {
            return null;
        }

        return 'Surat dari '.$this->basisFrom
            .($this->basisNumber !== null ? ' Nomor '.$this->basisNumber : '')
            .($this->basisDateText !== null ? ' Tanggal '.$this->basisDateText : '');
    }

    /** Guru letter, "Untuk" point 1 as one plain text (guruTaskParts()). */
    public function guruTask(string $layout = LetterTemplate::GURU_LAYOUT): string
    {
        return implode('', $this->guruTaskParts($layout));
    }

    /**
     * Guru letter, "Untuk" point 1 (worded as in the school's file, without a full stop) in three pieces: the words
     * before the kegiatan, the kegiatan, the rest. SV8, D-194 (the school's letter of 2026-11-09): "Melaksanakan tugas
     * kegiatan <kegiatan, bold> pada hari … tanggal …, Pukul … di …", the kegiatan = the lomba's name as it was entered
     * (the school writes the event's own name). A letter printed before (GURU_LAYOUT_LAMPIRAN) said "… kegiatan
     * mendampingi siswa Lomba <nama> …", nothing bold.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function guruTaskParts(string $layout = LetterTemplate::GURU_LAYOUT): array
    {
        $where = $this->online ? 'secara daring' : 'di '.($this->place !== '' ? $this->place : '[tempat]');
        $rest = ' pada hari '.($this->scheduleDay !== '' ? $this->scheduleDay : '[hari]')
            .' tanggal '.($this->scheduleDate !== '' ? $this->scheduleDate : '[tanggal]')
            .', Pukul '.($this->scheduleTime !== '' ? $this->scheduleTime : '[pukul]')
            .' '.$where;

        return $layout === LetterTemplate::GURU_LAYOUT_LAMPIRAN
            ? ['Melaksanakan tugas kegiatan mendampingi siswa ', $this->activityShort(), $rest]
            : ['Melaksanakan tugas kegiatan ', $this->competitionShort !== '' ? $this->competitionShort : '[kegiatan]', $rest];
    }

    /** SV8, D-194: the date above the signature of the guru letter, a one-digit day with a leading zero ("04 November 2026"). */
    public function guruSignedOn(): string
    {
        return self::twoDigitDay($this->issuedOnText);
    }

    /**
     * Guru letter, Lampiran: ["DAFTAR PENDAMPING LOMBA …", "TAHUN 2026"]. A year at the end of the lomba name
     * moves to the second line (as in the school's file); otherwise the year of the letter date.
     *
     * @return array{0: string, 1: string}
     */
    public function attachmentTitle(): array
    {
        $activity = $this->activityShort();
        $year = $this->issuedYear;

        if (preg_match('/^(.+?)(?:\s+tahun)?\s+((?:19|20)\d{2})$/iu', $activity, $m) === 1) {
            [$activity, $year] = [$m[1], $m[2]];
        }

        return ['DAFTAR PENDAMPING '.mb_strtoupper($activity), 'TAHUN '.$year];
    }

    public function studentCount(): int
    {
        return count($this->students);
    }

    /** "4 November 2026" → "04 November 2026" (SV8, D-194, the guru letter); any other text stays as it is. */
    private static function twoDigitDay(string $date): string
    {
        return (string) preg_replace('/^(\d)(?=\s)/u', '0$1', $date);
    }

    private static function withLomba(string $name): string
    {
        return preg_match('/^lomba\b/iu', $name) === 1 ? $name : 'Lomba '.$name;
    }

    /**
     * Split "A s.d B" (also "s.d." and "s/d") into [A, B]; [text, null] when there is no range.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function range(string $text): array
    {
        if (preg_match('~^(.+?)\s+(?:s\.d\.?|s/d)\s+(.+)$~iu', $text, $m) === 1) {
            return [trim($m[1]), trim($m[2])];
        }

        return [$text, null];
    }

    /** One line of plain text: control characters and line breaks become spaces, runs of spaces collapse. */
    private static function line(?string $value): string
    {
        $text = (string) preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', ' ', (string) $value);

        return trim((string) preg_replace('/\s{2,}/u', ' ', $text));
    }

    private static function clean(?string $value): ?string
    {
        $value = self::line($value);

        return $value === '' ? null : $value;
    }
}
