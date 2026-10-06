<?php

declare(strict_types=1);

namespace Database\Seeders\Support\Demo;

use App\Enums\Bidang;
use App\Enums\EventMode;
use App\Enums\FilePurpose;
use App\Enums\Level;
use App\Enums\MemberRole;
use App\Enums\OrganizerType;
use App\Enums\Participation;
use App\Enums\ReportFileKind;
use App\Enums\ResultType;
use App\Models\Competition;
use App\Models\File;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Works out which sample document a demo file should be, from what the `files` row is attached to (LOCAL ONLY):
 *
 * | files.purpose   | attached through                              | document                                              |
 * |-----------------|-----------------------------------------------|-------------------------------------------------------|
 * | report_evidence | report_files → report → registration → lomba  | sertifikat / piagam of the file's owner (kind         |
 * |                 |                                               | sertifikat), research report (laporan_riset, artikel) |
 * | talent_evidence | talent_applications.evidence_file_id          | the certificate of the student's own report with that |
 * |                 |                                               | file name or lomba; HINTS for junior-high certificates |
 * | juknis          | competitions.juknis_file_id                   | juknis, or brochure / poster by the file name          |
 * | letter          | letters.file_id                               | the school's real letter template (DemoLetter)         |
 *
 * A row that is attached to nothing, or to people outside the demo set, still gets a realistic document of its
 * purpose, built from the file name, with the reason as second element of the answer. Names are only ever printed
 * when DemoPeople knows them as fictional. Reads only; never writes.
 */
final class DemoDocumentResolver
{
    /**
     * Demo files whose content cannot be read from the database: junior-high certificates used as talent evidence.
     *
     * @var array<string, array{competition: string, organizer: string, type: OrganizerType, bidang: Bidang, level: Level, result: ResultType, branch: ?string, date: string, location: string, affiliation: string}>
     */
    private const HINTS = [
        'sertifikat-osn-smp-biologi-2025.pdf' => [
            'competition' => 'OSN SMP Tingkat Kota Semarang 2025', 'organizer' => 'Dinas Pendidikan Kota Semarang',
            'type' => OrganizerType::PemerintahDaerah, 'bidang' => Bidang::Sains, 'level' => Level::Kota, 'result' => ResultType::Juara1,
            'branch' => 'Biologi', 'date' => '2025-03-15', 'location' => 'Kota Semarang', 'affiliation' => 'Peserta jenjang SMP, Kota Semarang',
        ],
        'sertifikat-olimpiade-pai-smp-2025.pdf' => [
            'competition' => 'Olimpiade Pendidikan Agama Islam SMP Kota Semarang 2025', 'organizer' => 'Panitia Olimpiade PAI Kota Semarang',
            'type' => OrganizerType::Lainnya, 'bidang' => Bidang::Sains, 'level' => Level::Kota, 'result' => ResultType::Juara2,
            'branch' => null, 'date' => '2025-02-22', 'location' => 'Kota Semarang', 'affiliation' => 'Peserta jenjang SMP, Kota Semarang',
        ],
    ];

    private const ACRONYMS = [
        'osn', 'o2sn', 'fls3n', 'fls2n', 'ldbi', 'opsi', 'fiksi', 'lkti', 'okn', 'pai', 'smp', 'sma', 'lpb', 'nsif', 'wice', 'ispo',
        'mtq', 'kir', 'osebi', 'wseec', 'ayrc', 'lct', 'mipa',
    ];

    private const WORDS = ['prov' => 'Provinsi', 'jateng' => 'Jawa Tengah', 'undip' => 'Universitas Diponegoro', 'unnes' => 'Universitas Negeri Semarang', 'krenova' => 'KRENOVA', 'popda' => 'Popda'];

    private const REPORT_WITH = [
        'registration.competition',
        'registration.mentor',
        'registration.members.student.user',
        'registration.members.student.classroom',
    ];

    /**
     * @return array{0: DemoDocument, 1: string|null} the document, and why a general one was used (null = worked out
     *                                                from the attachment)
     */
    public function resolve(File $file): array
    {
        return match ($file->purpose) {
            FilePurpose::ReportEvidence => $this->reportEvidence($file),
            FilePurpose::TalentEvidence => $this->talentEvidence($file),
            FilePurpose::Juknis => $this->juknis($file),
            FilePurpose::Letter => $this->letter($file),
            default => [DemoGuideline::umum(self::humanize($file->original_name), $file->purpose->label(), $this->ownerName($file)), 'jenis berkas tanpa contoh khusus'],
        };
    }

    // Report evidence ------------------------------------------------------------------------------------

    /** @return array{0: DemoDocument, 1: string|null} */
    private function reportEvidence(File $file): array
    {
        $reportFile = ReportFile::query()->where('file_id', $file->getKey())->with(array_map(fn (string $r): string => 'report.'.$r, self::REPORT_WITH))->first();
        $report = $reportFile?->report;

        if ($reportFile === null || $report === null || $report->registration?->competition === null) {
            return [$this->looseCertificate($file, null), 'tidak terpasang pada laporan'];
        }

        $competition = $report->registration->competition;
        $members = self::members($report->registration);
        $owner = $members->first(fn (Student $s): bool => $file->owner_user_id !== null && $s->user_id === $file->owner_user_id) ?? $members->first();

        if ($owner === null || $members->contains(fn (Student $s): bool => ! DemoPeople::isFictional($s->user?->name))) {
            return [$this->looseCertificate($file, null), 'anggota di luar data contoh'];
        }

        return match ($reportFile->kind) {
            ReportFileKind::Sertifikat => [DemoCertificate::document(self::award($report, $owner, $members, $file)), null],
            ReportFileKind::LaporanRiset, ReportFileKind::Artikel => [self::researchReport($report, $members), null],
            default => [DemoGuideline::umum($reportFile->kind->label().' '.$competition->name, $file->purpose->label(), $owner->user?->name), null],
        };
    }

    /**
     * @param  Collection<int, Student>  $members  ketua first
     */
    private static function award(Report $report, Student $recipient, Collection $members, File $file): DemoAward
    {
        $registration = $report->registration;
        $competition = $registration->competition;
        $organizer = trim((string) $competition->organizer_name);

        return new DemoAward(
            recipient: (string) $recipient->user?->name,
            competition: (string) $competition->name,
            organizer: $organizer !== '' ? $organizer : 'Panitia '.$competition->name,
            result: $report->final_result ?? $report->claimed_result ?? ResultType::Peserta,
            organizerType: $competition->organizer_type,
            bidang: $competition->bidang,
            level: $competition->level,
            branch: $registration->branch,
            startsOn: $competition->starts_on,
            finalOn: $competition->final_on,
            location: $competition->location,
            online: $competition->mode === EventMode::Daring,
            team: $members->reject(fn (Student $s): bool => $s->is($recipient))->map(fn (Student $s): string => (string) $s->user?->name)->values()->all(),
            piagam: self::isPiagam($file),
        );
    }

    /** @param Collection<int, Student> $members */
    private static function researchReport(Report $report, Collection $members): DemoDocument
    {
        $registration = $report->registration;
        $competition = $registration->competition;
        $date = $competition->starts_on?->subDays(7) ?? $report->submitted_at ?? CarbonImmutable::create(2026, 9, 30);

        return DemoResearchReport::document(
            (string) $report->research_title,
            $report->research_abstract,
            (string) $competition->name,
            $members->map(fn (Student $s): array => [
                'name' => (string) $s->user?->name,
                'nis' => (string) $s->nis,
                'class' => (string) ($s->classroom?->name ?? '-'),
            ])->values()->all(),
            DemoPeople::nameOr($registration->mentor?->name, DemoPeople::FALLBACK_MENTOR),
            CarbonImmutable::instance($date)->startOfDay(),
        );
    }

    // Talent evidence ------------------------------------------------------------------------------------

    /** @return array{0: DemoDocument, 1: string|null} */
    private function talentEvidence(File $file): array
    {
        $application = TalentApplication::query()->where('evidence_file_id', $file->getKey())->with(['student.user', 'talentType'])->first();
        $student = $application?->student;

        if ($application === null || $student === null) {
            return [$this->looseCertificate($file, null), 'tidak terpasang pada pendaftaran talenta'];
        }

        if (! DemoPeople::isFictional($student->user?->name)) {
            return [$this->looseCertificate($file, null), 'siswa di luar data contoh'];
        }

        $name = (string) $student->user?->name;
        $hint = self::HINTS[mb_strtolower($file->original_name)] ?? null;

        if ($hint !== null) {
            return [DemoCertificate::document(new DemoAward(
                recipient: $name,
                competition: $hint['competition'],
                organizer: $hint['organizer'],
                result: $hint['result'],
                organizerType: $hint['type'],
                bidang: $hint['bidang'],
                level: $hint['level'],
                branch: $hint['branch'],
                startsOn: CarbonImmutable::parse($hint['date']),
                finalOn: CarbonImmutable::parse($hint['date']),
                location: $hint['location'],
                piagam: self::isPiagam($file),
                affiliation: $hint['affiliation'],
            )), null];
        }

        // The same certificate the student already attached to a report: one document, one render.
        $twin = File::query()
            ->where('purpose', FilePurpose::ReportEvidence)
            ->where('owner_user_id', $student->user_id)
            ->where('original_name', $file->original_name)
            ->where('path', 'like', DemoFileRefresher::DIRECTORY.'/%')
            ->orderBy('id')
            ->first();

        if ($twin !== null) {
            [$document, $note] = $this->reportEvidence($twin);

            if ($note === null) {
                return [$document, null];
            }
        }

        $report = $this->reportByFileName($student, $file);

        if ($report !== null) {
            return [DemoCertificate::document(self::award($report, $student, self::members($report->registration), $file)), null];
        }

        return [$this->looseCertificate($file, $name, $application->talentType?->bidang), 'lomba tidak dikenali, dibuat dari nama berkas'];
    }

    /** The student's report whose lomba name carries every word of the file name ("piagam-o2sn-prov-2026"). */
    private function reportByFileName(Student $student, File $file): ?Report
    {
        $tokens = self::tokens($file->original_name);

        if ($tokens === []) {
            return null;
        }

        return Report::query()
            ->whereHas('registration.members', fn ($q) => $q->where('student_id', $student->getKey())->whereNotNull('confirmed_at'))
            ->with(self::REPORT_WITH)
            ->orderByDesc('submitted_at')
            ->get()
            ->first(function (Report $report) use ($tokens): bool {
                $slug = Str::slug((string) $report->registration?->competition?->name);

                return $slug !== '' && collect($tokens)->every(fn (string $token): bool => str_contains($slug, $token));
            });
    }

    // Juknis, brochure, poster ---------------------------------------------------------------------------

    /** @return array{0: DemoDocument, 1: string|null} */
    private function juknis(File $file): array
    {
        $competition = Competition::query()->where('juknis_file_id', $file->getKey())->first();
        $created = CarbonImmutable::instance($competition?->created_at ?? $file->created_at ?? now())->startOfDay();
        [$opens, $closes, $starts, $final] = DemoEvent::datesFrom($created);

        if ($competition?->registration_closes_at !== null && $competition->starts_on !== null) {
            $starts = $competition->starts_on;
            $final = $competition->final_on ?? $starts;
            $closes = $competition->registration_closes_at->startOfDay();
            $opens = $competition->registration_opens_at?->startOfDay() ?? $closes->subDays(30);
        }

        $organizer = trim((string) $competition?->organizer_name);
        $name = $competition !== null ? (string) $competition->name : self::humanize($file->original_name);

        $event = new DemoEvent(
            name: $name,
            organizer: $organizer !== '' ? $organizer : 'Panitia '.$name,
            opensOn: $opens,
            closesOn: $closes,
            startsOn: $starts,
            finalOn: $final->lt($starts) ? $starts : $final,
            bidang: $competition?->bidang,
            level: $competition?->level,
            organizerType: $competition?->organizer_type,
            online: $competition?->mode === EventMode::Daring,
            location: $competition?->location,
            team: in_array($competition?->participation, [Participation::Tim, Participation::Keduanya], true),
            individual: $competition?->participation !== Participation::Tim,
            maxTeam: $competition?->max_team_size,
        );

        $lower = mb_strtolower($file->original_name);
        $document = match (true) {
            str_starts_with($lower, 'brosur') => DemoGuideline::brosur($event),
            str_starts_with($lower, 'poster') => DemoGuideline::poster($event),
            default => DemoGuideline::juknis($event),
        };

        return [$document, $competition === null ? 'tidak terpasang pada lomba, dibuat dari nama berkas' : null];
    }

    // Letters ----------------------------------------------------------------------------------------------

    /** @return array{0: DemoDocument, 1: string|null} */
    private function letter(File $file): array
    {
        $letter = Letter::query()->where('file_id', $file->getKey())->first();
        $document = $letter === null ? null : DemoLetter::document($letter);

        if ($document !== null) {
            return [$document, null];
        }

        return [
            DemoGuideline::umum($letter !== null ? 'Surat tugas nomor '.$letter->full_number : self::humanize($file->original_name), $file->purpose->label()),
            $letter === null ? 'tidak terpasang pada surat' : 'surat tidak bisa dibuat dari datanya',
        ];
    }

    // Helpers ----------------------------------------------------------------------------------------------

    /** A certificate built from the file name alone, for a fictional recipient. */
    private function looseCertificate(File $file, ?string $recipient, ?Bidang $bidang = null): DemoDocument
    {
        $year = preg_match('/(19|20)\d{2}/', $file->original_name, $m) === 1 ? (int) $m[0] : 2026;
        $name = self::humanize($file->original_name);
        $date = CarbonImmutable::create($year, 6, 14);

        return DemoCertificate::document(new DemoAward(
            recipient: $recipient ?? DemoPeople::nameOr($this->ownerName($file), DemoPeople::FALLBACK_STUDENT['name']),
            competition: $name,
            organizer: 'Panitia '.$name,
            result: ResultType::Peserta,
            organizerType: OrganizerType::Lainnya,
            bidang: $bidang,
            startsOn: $date,
            finalOn: $date,
            piagam: self::isPiagam($file),
        ));
    }

    private function ownerName(File $file): ?string
    {
        $name = $file->owner_user_id === null ? null : User::query()->whereKey($file->owner_user_id)->value('name');

        return DemoPeople::isFictional($name) ? (string) $name : null;
    }

    /**
     * Confirmed members of a registration, ketua first, with user and classroom.
     *
     * @return Collection<int, Student>
     */
    private static function members(Registration $registration): Collection
    {
        return $registration->members
            ->filter(fn (RegistrationMember $m): bool => $m->confirmed_at !== null && $m->declined_at === null && $m->student !== null)
            ->sortBy(fn (RegistrationMember $m): string => ($m->role === MemberRole::Ketua ? '0' : '1').mb_strtolower((string) $m->student?->user?->name))
            ->map(fn (RegistrationMember $m): Student => $m->student)
            ->values();
    }

    private static function isPiagam(File $file): bool
    {
        return str_starts_with(mb_strtolower($file->original_name), 'piagam');
    }

    /**
     * Words of a file name that should also be in the lomba name: "piagam-o2sn-prov-2026.pdf" → [o2sn, prov, 2026].
     *
     * @return list<string>
     */
    private static function tokens(string $fileName): array
    {
        $tokens = explode('-', Str::slug(pathinfo($fileName, PATHINFO_FILENAME)));

        return array_values(array_filter($tokens, fn (string $t): bool => $t !== '' && ! in_array($t, ['sertifikat', 'piagam', 'juknis', 'brosur', 'poster'], true)));
    }

    /** "sertifikat-osn-smp-biologi-2025.pdf" → "OSN SMP Biologi 2025". */
    public static function humanize(string $fileName): string
    {
        $words = array_map(fn (string $token): string => match (true) {
            in_array($token, self::ACRONYMS, true) => mb_strtoupper($token),
            isset(self::WORDS[$token]) => self::WORDS[$token],
            default => Str::ucfirst($token),
        }, self::tokens($fileName));

        return $words === [] ? 'Lomba Pelajar' : implode(' ', $words);
    }
}
