<?php

declare(strict_types=1);

namespace App\Livewire\Siswa\LombaSaya;

use App\Actions\Registrations\ApproveRegistration;
use App\Actions\Registrations\CancelRegistration;
use App\Actions\Registrations\ConfirmMembership;
use App\Domain\Deadlines\ReportFollowUp;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\ReportFileKind;
use App\Enums\ReportStatus;
use App\Livewire\Siswa\Lomba\LombaPresenter;
use App\Livewire\Siswa\LombaSaya\Concerns\ReportHelpers;
use App\Models\Achievement;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Report;
use App\Models\Student;
use App\Models\User;
use App\Support\StaffRoleLabel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Lomba saya (UI-MAP #12, board Lomba-Saya): list Berjalan/Selesai + detail pane with the 6-phase tracker,
 * surat tugas, tim and the laporan hasil card (inline LaporHasil at every width since UX2; `?lapor=1` opens it, the
 * address the Beranda button and the old `siswa.lapor` page use).
 * Routes: siswa.lomba-saya.index (list; desktop auto-selects the first item) and siswa.lomba-saya.show.
 * Shows registrations where the student is a member who did not decline (incl. open invitations).
 */
#[Title('Lomba saya')]
#[Layout('layouts::app', ['subtitle' => 'Pantau pendaftaran, surat tugas, dan laporan hasil.'])]
final class Index extends Component
{
    use ReportHelpers;

    public const TABS = ['berjalan', 'selesai'];

    /**
     * Only the ketua reports (ReportPolicy::create): a team member waits for the report, so the chip is neutral instead
     * of the ketua's orange request (SV1).
     */
    public const MEMBER_REPORT_CHIP = ['chip' => 'Menunggu laporan ketua', 'tone' => 'gray'];

    /** The same for a team member while the ketua revises the report ("Perlu revisi" is the ketua's chip). */
    public const MEMBER_REVISION_CHIP = ['chip' => 'Menunggu perbaikan ketua', 'tone' => 'gray'];

    /** The ketua's chip while the report is sent back for a revision (the ketua acts; the Beranda shows it too). */
    public const LEADER_REVISION_CHIP = ['chip' => 'Perlu revisi', 'tone' => 'orange'];

    /**
     * D-36: the chip of a team left with fewer than two people (ConfirmMembership::teamIsShort()): orange for the ketua,
     * who invites a replacement or cancels, gray for anyone else (shortTeamChip(); the Beranda shows it too).
     */
    public const SHORT_TEAM_CHIP = 'Tim kurang anggota';

    /**
     * The words and tone of each memberState() on the team card (`partials/team-card`), and on the two other screens that
     * list a team's members, Siswa › Undangan tim and Guru › detail binaan (memberChip(); SV1 R3: they still said
     * "Menunggu konfirmasi" for a registration that had ended). Gray = waiting on someone else or nothing left to wait for.
     */
    public const MEMBER_STATE_CHIPS = [
        'confirmed' => ['Sudah konfirmasi', 'green'],
        'later' => ['Diundang setelah lomba diverifikasi', 'gray'],
        'pending' => ['Menunggu konfirmasi', 'gray'],
        'not_joined' => ['Tidak ikut', 'gray'],
        'not_invited' => ['Tidak diundang', 'gray'],
    ];

    #[Url(except: 'berjalan')]
    public string $tab = 'berjalan';

    #[Locked]
    public ?string $selectedId = null;

    /** Opened through /lomba-saya/{registration}: phones show the detail instead of the list. */
    #[Locked]
    public bool $explicit = false;

    /** The report form is open inside the detail pane (UX2: also on phones; `?lapor=1` opens it from the Beranda). */
    #[Url(except: false)]
    public bool $lapor = false;

    public function mount(?Registration $registration = null): void
    {
        $student = $this->student();
        abort_if($student === null, 403);

        if ($registration !== null) {
            abort_unless(Gate::allows('view', $registration) && $this->isVisibleTo($registration, $student), 404);

            $this->selectedId = (string) $registration->getKey();
            $this->explicit = true;
            $this->tab = $registration->status->isFinal() ? 'selesai' : 'berjalan';
            // Only the ketua who may report now gets the open form; anyone else just sees the card.
            $this->lapor = $this->lapor && Gate::allows('create', [Report::class, $registration]);

            return;
        }

        $this->lapor = false;

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'berjalan';
        }

        $this->selectedId = $this->registrations()->first()?->getKey();
    }

    public function openLapor(): void
    {
        $registration = $this->selectedOrFail();

        if ($this->reportSentElsewhere($registration)) {
            return;
        }

        Gate::authorize('create', [Report::class, $registration]);

        $this->lapor = true;
    }

    public function closeLapor(): void
    {
        $this->lapor = false;
    }

    public function cancel(): void
    {
        $registration = $this->selectedOrFail();
        $user = $this->user();

        if (! Gate::allows('cancel', $registration)) {
            $this->dispatch('sth-modal-close', id: 'batal-daftar');
            $this->dispatch('sth-toast', message: 'Pendaftaran ini tidak bisa dibatalkan lagi.', tone: 'error');

            return;
        }

        try {
            app(CancelRegistration::class)->handle($user, $registration);
        } catch (ValidationException $e) {
            $this->dispatch('sth-modal-close', id: 'batal-daftar');
            $this->dispatch('sth-toast', message: (string) collect($e->errors())->flatten()->first(), tone: 'error');

            return;
        }

        // A cancelled registration is final: the list follows it to Selesai, as mount() places it on a page load (SV1
        // re-check: the desktop list stayed on an empty Berjalan tab beside the cancelled card).
        $this->tab = 'selesai';
        unset($this->selected, $this->counts, $this->items);
        $this->dispatch('sth-modal-close', id: 'batal-daftar');
        $this->dispatch('sth-toast', message: 'Pendaftaran dibatalkan.', tone: 'success');
    }

    /** @return array{berjalan: int, selesai: int} */
    #[Computed]
    public function counts(): array
    {
        $all = $this->baseQuery()->pluck('status');

        $selesai = $all->filter(fn (RegistrationStatus $s): bool => $s->isFinal())->count();

        return ['berjalan' => $all->count() - $selesai, 'selesai' => $selesai];
    }

    /** @return Collection<int, Registration> */
    #[Computed]
    public function items(): Collection
    {
        return $this->registrations();
    }

    #[Computed]
    public function selected(): ?Registration
    {
        if ($this->selectedId === null) {
            return null;
        }

        $registration = $this->baseQuery()
            ->whereKey($this->selectedId)
            ->with([
                'competition.verifier.roles',
                'mentor.staffProfile',
                // Sorted below in PHP (RegistrationMember::inTeamOrder()), never in SQL: the same order as every other team list.
                // A teammate in the trash (Admin › Kelas) is hidden: no blank "Siswa" row; its row stays until the purge.
                'members' => fn ($q) => $q->whereNull('declined_at')->whereHas('student'),
                'members.student.user',
                'members.student.classroom',
                'letters',
                'report.files.file',
                'report.verifier.roles',
                // D-150: who approved or rejected (Kepala Sekolah or Tata Usaha), for "Disetujui oleh …" / "Ditetapkan …".
                'principalDecider.roles',
            ])
            ->first();

        $registration?->setRelation('members', RegistrationMember::inTeamOrder($registration->members));

        return $registration !== null && Gate::allows('view', $registration) ? $registration : null;
    }

    public function render(): View
    {
        $registration = $this->selected;

        return view('livewire.siswa.lomba-saya.index', [
            'cards' => $this->items->map(fn (Registration $r): array => $this->card($r))->all(),
            'counts' => $this->counts,
            'detail' => $registration !== null ? $this->detail($registration) : null,
        ]);
    }

    // View models -----------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function card(Registration $registration): array
    {
        $competition = $registration->competition;
        $mine = $registration->members->first();
        $people = (int) $registration->getAttribute('active_members_count');
        // registrations() already counted the members who did not decline: no COUNT query per card (SV1 review).
        [$chip, $tone] = $this->statusChip($registration, $mine, self::isShortTeam($registration, $people));

        return [
            'id' => (string) $registration->getKey(),
            'href' => route('siswa.lomba-saya.show', $registration),
            'title' => $competition->name,
            // The size the detail lists: without a teammate in the trash (the short-team rule above still counts its row).
            'meta' => ($competition->bidang?->label() ?? 'Lomba').' · '.$this->typeLabel($registration, (int) $registration->getAttribute('shown_members_count')),
            'bidang' => $competition->bidang,
            'status' => $registration->status,
            // QA10: the tracker ends at Laporan hasil without points for a rejected report (D-34).
            'reportStatus' => $registration->report?->status,
            'trackerState' => self::trackerState($registration, $mine),
            'chip' => $chip,
            'tone' => $tone,
            'current' => (string) $registration->getKey() === $this->selectedId,
        ];
    }

    /**
     * The chip of a registration in the list and in the head of the detail, for the viewer ($mine = the viewer's row).
     * $short = whether the team is short of people (teamIsShort()), when the caller already knows; null = ask.
     *
     * @return array{0: string, 1: string} chip text and tone
     */
    private function statusChip(Registration $registration, ?RegistrationMember $mine, ?bool $short = null): array
    {
        $status = $registration->status;
        $leader = $mine?->isLeader() ?? false;

        if ($status === RegistrationStatus::MenungguKonfirmasi && $mine !== null && ! $leader && $mine->confirmed_at === null) {
            return ['Konfirmasi undangan', 'orange'];
        }

        // D-36: nobody is left to confirm, so the ketua invites a replacement or cancels (SV1: it read as waiting).
        if ($short ?? self::isShortTeam($registration)) {
            ['chip' => $chip, 'tone' => $tone] = self::shortTeamChip($leader);

            return [$chip, $tone];
        }

        if ($status === RegistrationStatus::MenungguLaporan) {
            $revising = $registration->report?->status === ReportStatus::PerluRevisi;

            // SV1: only the ketua reports; a team member never gets the orange request.
            if (! $leader) {
                ['chip' => $chip, 'tone' => $tone] = $revising ? self::MEMBER_REVISION_CHIP : self::MEMBER_REPORT_CHIP;

                return [$chip, $tone];
            }

            if ($revising) {
                ['chip' => $chip, 'tone' => $tone] = self::LEADER_REVISION_CHIP;

                return [$chip, $tone];
            }

            // D-151: no deadline, no days left: only the request.
            ['chip' => $chip, 'tone' => $tone] = $this->reportChip();

            return [$chip, $tone];
        }

        if ($status === RegistrationStatus::Selesai && $registration->report?->status === ReportStatus::Ditolak) {
            return ['Laporan ditolak', 'red'];
        }

        return [$status->label(), $status->tone()];
    }

    /** @return array<string, mixed> */
    private function detail(Registration $registration): array
    {
        $student = $this->student();
        $competition = $registration->competition;
        $status = $registration->status;
        $mine = $registration->members->firstWhere('student_id', $student?->getKey());
        $isLeader = $registration->leader_student_id === $student?->getKey();
        $report = $registration->report;
        // A letter exists for the student once Tata Usaha sent it (LetterPolicy::view); until then the card says
        // "sedang menyiapkan" instead of offering a file the policy would answer with 404.
        $sentLetters = $registration->letters->filter(fn (Letter $letter): bool => $letter->sent_at !== null);
        $studentLetter = $sentLetters->firstWhere('type', LetterType::Siswa);
        $guruLetter = $sentLetters->firstWhere('type', LetterType::Guru);
        $achievement = $report !== null && $student !== null
            ? Achievement::query()->where('report_id', $report->getKey())->where('student_id', $student->getKey())->first()
            : null;
        $short = self::isShortTeam($registration);
        [$chip, $tone] = $this->statusChip($registration, $mine, $short);
        $canCancel = Gate::allows('cancel', $registration);
        // B3 (#9): the ketua invites replacements in the registration panel of the lomba.
        $inviteHref = Gate::allows('invite', $registration) && Route::has('siswa.lomba.show')
            ? route('siswa.lomba.show', $registration->competition_id).'#h-daftar'
            : null;

        return [
            'registration' => $registration,
            'competition' => $competition,
            'status' => $status,
            'reportStatus' => $report?->status,
            'trackerState' => self::trackerState($registration, $mine),
            'chip' => $chip,
            'tone' => $tone,
            'sub' => collect([$competition->organizer_name, $competition->weight_category?->label()])->filter()->implode(' · '),
            'typeLabel' => $this->typeLabel($registration, $registration->members->count()),
            'dateText' => self::dateRange($competition->starts_on, $competition->final_on),
            'place' => $competition->location,
            'trackerDates' => $this->trackerDates($registration, $studentLetter, $achievement),
            'isLeader' => $isLeader,
            'isTeam' => $registration->type === RegistrationType::Tim,
            'pendingInvite' => $mine !== null && ! $mine->isLeader() && $mine->confirmed_at === null
                && $status === RegistrationStatus::MenungguKonfirmasi,
            'inviteUrl' => Route::has('siswa.undangan.show') ? route('siswa.undangan.show', $registration) : null,
            'canCancel' => $canCancel,
            // The line of the "Batalkan pendaftaran?" dialog: who CancelRegistration tells (D-109, the Detail lomba panel's
            // line too; SV1: it was a fixed text that told nothing about who hears of it).
            'cancelNote' => $canCancel ? CancelRegistration::noticeLine($registration, $this->user()) : '',
            'inviteHref' => $inviteHref,
            'canReport' => Gate::allows('create', [Report::class, $registration]),
            'report' => $report,
            // The role that decided on the report, for "Catatan …" / "Diverifikasi …" (D-139); "sekolah" before a decision.
            'decider' => StaffRoleLabel::forDecision($report?->verifier),
            // D-151: no report deadline, only the request (and the chip of reportChip()). SV1: a team member waits for
            // the ketua's report, so the chip beside the card title is neutral.
            'reportPrompt' => ReportFollowUp::PROMPT,
            'reportChip' => match (true) {
                $report?->status === ReportStatus::PerluRevisi => null,
                $isLeader => $this->reportChip(),
                default => self::MEMBER_REPORT_CHIP,
            },
            'estimate' => $report !== null ? $this->previewBreakdown($competition, $report->claimed_result) : null,
            'achievement' => $achievement,
            'certificate' => $this->fileLink($report?->files->firstWhere('kind', ReportFileKind::Sertifikat)?->file_id),
            'letters' => $this->letterRows($studentLetter, $guruLetter, $registration),
            'lettersIssuedOn' => $studentLetter?->issued_on !== null ? self::shortDate($studentLetter->issued_on) : null,
            'members' => $registration->members->map(fn (RegistrationMember $m): array => [
                'name' => $m->student?->user?->name ?? 'Siswa',
                'meta' => self::memberMeta($m, $registration->type === RegistrationType::Tim),
                'state' => self::memberState($m, $status),
                'me' => $m->student_id === $student?->getKey(),
            ])->all(),
            'mentor' => $registration->mentor !== null ? $this->mentorName($registration->mentor) : null,
            // D-132: the approver put another guru in the place of the one the student chose.
            'mentorByPrincipal' => $registration->mentor !== null && ApproveRegistration::mentorWasReplaced($registration),
            // D-150: the role that decided ("sekolah" before a decision) and, once approved, who by name.
            'approverRole' => StaffRoleLabel::forDecision($registration->principalDecider),
            'approvedBy' => $registration->principalDecider !== null
                ? 'Disetujui oleh '.$registration->principalDecider->name.' ('.StaffRoleLabel::forDecision($registration->principalDecider).').'
                : null,
            'decidedOn' => self::longDate($registration->principal_decided_at),
            'registeredOn' => self::longDate($registration->created_at),
            'teamShort' => $short,
            'waitingText' => $this->waitingText($registration, $short, $inviteHref, $canCancel),
            // D-148: cancelled together with the rejected lomba; registering again is not possible then. The role that
            // rejected it is named by the one lomba rule (StaffRoleLabel::forLomba(): Kesiswaan or Admin, Kesiswaan without one).
            'cancelledText' => $this->cancelledText($registration, $status),
        ];
    }

    /**
     * D-36: a team that waits for answers with fewer than ConfirmMembership::MIN_TEAM_SIZE people who did not decline.
     * Only then can a team be short; other statuses never ask. $people = the members who did not decline when the caller
     * already counted them (the list: registrations() loads `active_members_count` with the filter of teamIsShort(),
     * so no COUNT query runs per card; the Beranda its `team_size`); null = ConfirmMembership::teamIsShort() counts in
     * the database (the detail, whose `members` relation is filtered). Public for the Beranda, which shows the same chip.
     */
    public static function isShortTeam(Registration $registration, ?int $people = null): bool
    {
        if ($registration->status !== RegistrationStatus::MenungguKonfirmasi) {
            return false;
        }

        return $people === null
            ? ConfirmMembership::teamIsShort($registration)
            : ConfirmMembership::teamIsShortWith($registration, $people);
    }

    /**
     * The chip of a short team (isShortTeam()) for a viewer: orange for the ketua ($leader), who has to invite a
     * replacement or cancel; gray for a member, who only waits (SV1).
     *
     * @return array{chip: string, tone: string}
     */
    public static function shortTeamChip(bool $leader): array
    {
        return ['chip' => self::SHORT_TEAM_CHIP, 'tone' => $leader ? 'orange' : 'gray'];
    }

    /** The tracker's state for the viewer on this screen ($mine = the viewer's member row), see trackerStateFor(). */
    private static function trackerState(Registration $registration, ?RegistrationMember $mine): ?string
    {
        return self::trackerStateFor($registration, $mine?->isLeader() ?? false);
    }

    /**
     * The tracker's state (`<x-sth.tracker state>`) for a viewer of this registration, or null for the state the status
     * gives. SV1: only the ketua reports (ReportPolicy::create), so anyone else, a team member (here and on the Beranda)
     * or the guru pembimbing (Guru › Siswa binaan), sees Laporan hasil as the current phase, never as "perlu tindakan".
     * $reports = the viewer is the one who sends the report (the ketua; the one student of an individu registration).
     */
    public static function trackerStateFor(Registration $registration, bool $reports): ?string
    {
        return $registration->status === RegistrationStatus::MenungguLaporan && ! $reports ? 'current' : null;
    }

    /**
     * The chip of a member on the team card. D-148: while the lomba waits for Kesiswaan nobody has been invited yet
     * (`invited_at` is null), so the member is invited once the lomba is verified (FS1 QA round 2). SV1: a registration
     * that ended (the ketua cancelled it, or it went with its rejected lomba) waits for nobody: an invitee who never
     * answered did not join, and a member of a team whose lomba never got verified was never invited. The one rule of
     * every list of a team's members (memberChip(); a screen that also lists declined or expired invitations names
     * those itself first).
     *
     * @return 'confirmed'|'later'|'pending'|'not_joined'|'not_invited'
     */
    public static function memberState(RegistrationMember $member, RegistrationStatus $status): string
    {
        return match (true) {
            $member->confirmed_at !== null => 'confirmed',
            $status->isFinal() => $member->invited_at === null ? 'not_invited' : 'not_joined',
            $member->invited_at === null && $status === RegistrationStatus::MenungguLomba => 'later',
            default => 'pending',
        };
    }

    /**
     * memberState() as its chip [text, tone] (MEMBER_STATE_CHIPS): the words of the Lomba saya team card for Siswa ›
     * Undangan tim and Guru › detail binaan (SV1 R3).
     *
     * @return array{0: string, 1: string}
     */
    public static function memberChip(RegistrationMember $member, RegistrationStatus $status): array
    {
        return self::MEMBER_STATE_CHIPS[self::memberState($member, $status)];
    }

    /**
     * The line of the waiting card (menunggu_lomba, menunggu_konfirmasi, menunggu_kepsek), null for every other status.
     * D-148: the lomba itself still waits for Kesiswaan; nothing has gone out yet. D-36: a team left with fewer than two
     * people cannot go on (SV1: it said "Semua anggota sudah konfirmasi."), so the line says what the ketua can still
     * do: invite a replacement while the team window is open, else cancel.
     */
    private function waitingText(Registration $registration, bool $short, ?string $inviteHref, bool $canCancel): ?string
    {
        $status = $registration->status;

        if ($status === RegistrationStatus::MenungguLomba) {
            return LombaPresenter::waitingForLomba($registration);
        }

        if (! in_array($status, [RegistrationStatus::MenungguKonfirmasi, RegistrationStatus::MenungguKepsek], true)) {
            return null;
        }

        if ($short) {
            return self::shortTeamText($registration, $inviteHref !== null, $canCancel);
        }

        // Asked in the database, not of the loaded members: a teammate in the trash is not listed, but the team still
        // waits for its answer (ConfirmMembership counts its row until the purge).
        if ($registration->members()->whereNull('declined_at')->whereNull('confirmed_at')->exists()) {
            return 'Menunggu konfirmasi anggota tim.';
        }

        return $registration->isTeam() ? 'Semua anggota sudah konfirmasi.' : 'Menunggu persetujuan sekolah.';
    }

    /**
     * The line of a short team (isShortTeam(), D-36) for its viewer: why it does not go on, and what the ketua can still
     * do: invite a replacement while the team window is open ($canInvite = RegistrationPolicy::invite), else cancel
     * ($canCancel). A team member, who may do neither, reads the first sentence only. Also the line of the Pendaftaran
     * lomba panel on Detail lomba (SV1 R3: it said "Menunggu konfirmasi anggota").
     */
    public static function shortTeamText(Registration $registration, bool $canInvite, bool $canCancel): string
    {
        $line = 'Tim kurang dari '.ConfirmMembership::MIN_TEAM_SIZE.' orang, jadi pendaftaran belum bisa diteruskan untuk persetujuan.';

        // Until when the ketua may invite (RegistrationPolicy::teamWindowOpen() reads answersCloseAt(); a team resumed
        // late has its own moment, D-148); no moment = no deadline.
        $until = $registration->answersCloseAt()?->locale('id')->translatedFormat('j M Y, H.i');

        return match (true) {
            $canInvite && $until !== null => $line.' Undang anggota pengganti sebelum '.$until.', atau batalkan pendaftaran ini.',
            $canInvite => $line.' Undang anggota pengganti, atau batalkan pendaftaran ini.',
            $canCancel => $line.' Anggota pengganti tidak bisa diundang lagi, jadi batalkan pendaftaran ini.',
            default => $line,
        };
    }

    /**
     * Why a registration ended as dibatalkan. D-148: cancelled together with the rejected lomba (registering again is
     * not possible then; the role that rejected it comes from StaffRoleLabel::forLomba()). D-155: Tata Usaha removed it
     * from the letter queue, with the reason. Otherwise the ketua cancelled it.
     */
    private function cancelledText(Registration $registration, RegistrationStatus $status): string
    {
        $again = 'Kamu bisa mendaftar lagi selama pendaftaran lomba masih dibuka.';

        if ($status !== RegistrationStatus::Dibatalkan) {
            return $again;
        }

        if ($registration->cancelledWithLomba()) {
            return 'Usulan lomba ini ditolak '.StaffRoleLabel::forLomba($registration->competition?->verifier).', jadi pendaftarannya ikut dibatalkan.';
        }

        $byQueue = $registration->letterQueueCancellation();

        if ($byQueue !== null) {
            $by = $byQueue['by'] !== null
                ? 'oleh '.$byQueue['by']->name.' ('.StaffRoleLabel::forDecision($byQueue['by']).')'
                : StaffRoleLabel::FALLBACK;

            return 'Dibatalkan '.$by.' sebelum surat tugas dikirim. Alasan: '.$byQueue['note'].' '.$again;
        }

        return $again;
    }

    /**
     * Tracker dates per phase. Phase 5 shows the day the report was sent, never a due date (D-151).
     *
     * @return array<int, string>
     */
    private function trackerDates(Registration $registration, ?Letter $studentLetter, ?Achievement $achievement): array
    {
        $competition = $registration->competition;
        $report = $registration->report;

        return array_filter([
            1 => self::shortDate($registration->created_at),
            2 => self::shortDate($registration->principal_decided_at),
            3 => self::shortDate($studentLetter?->issued_on),
            4 => self::dateRange($competition->starts_on, $competition->final_on, false),
            5 => self::shortDate($report?->submitted_at),
            6 => self::shortDate($achievement?->verified_at),
        ], fn (string $text): bool => $text !== '');
    }

    /**
     * Rows of the "Surat tugas" card (sent letters only). `info` = a row without a file for the student.
     *
     * @return list<array{title: string, meta: string, note: ?string, href: ?string, label: string, info: bool}>
     */
    private function letterRows(?Letter $studentLetter, ?Letter $guruLetter, Registration $registration): array
    {
        $rows = [];
        $count = $registration->members->count();

        if ($studentLetter !== null) {
            $rows[] = [
                'title' => 'Surat tugas siswa',
                'meta' => trim($studentLetter->full_number.' · PDF'),
                'note' => $registration->type === RegistrationType::Tim ? '1 surat untuk '.$count.' anggota' : null,
                // The link exists exactly when files.show would serve the file (FilePolicy → LetterPolicy::view).
                'href' => Gate::allows('view', $studentLetter) ? $this->fileLink($studentLetter->file_id) : null,
                'label' => 'Download surat tugas siswa (PDF)',
                'info' => false,
            ];
        }

        if ($guruLetter !== null) {
            // M2-C (Q1-01): the guru letter and its Lampiran carry the teacher's NIP and rank, so LetterPolicy
            // keeps the file from students. The student only learns that it exists and for whom: never a link.
            $rows[] = [
                'title' => 'Surat perintah tugas untuk guru pendamping sudah terbit',
                'meta' => $registration->mentor !== null
                    ? 'Untuk '.$this->mentorName($registration->mentor)
                    : 'Hanya untuk guru pendamping',
                'note' => null,
                'href' => null,
                'label' => '',
                'info' => true,
            ];
        }

        return $rows;
    }

    private function fileLink(?string $fileId): ?string
    {
        return $fileId !== null && Route::has('files.show') ? route('files.show', $fileId) : null;
    }

    private function mentorName(User $mentor): string
    {
        $honorific = $mentor->staffProfile?->honorific;

        return trim(($honorific ? $honorific.' ' : '').$mentor->name);
    }

    private function typeLabel(Registration $registration, int $members): string
    {
        return $registration->type === RegistrationType::Tim ? 'Tim '.$members.' orang' : 'Individu';
    }

    // Queries ---------------------------------------------------------------------------------

    /**
     * Registrations of this student (member who did not decline). An anggota row without `invited_at` belongs to a
     * team whose lomba still waits for Kesiswaan (D-148): that student was not invited yet and does not see it. A
     * registration whose ketua is in the trash (Admin › Kelas, 5 Nov 2026) is hidden until "Pulihkan" or the purge,
     * which passes the leadership to a remaining member (whereHas('leader') leaves out a soft-deleted student).
     *
     * @return Builder<Registration>
     */
    private function baseQuery(): Builder
    {
        $studentId = $this->student()?->getKey();

        return Registration::query()
            ->whereHas(
                'members',
                fn (Builder $m) => $m->where('student_id', $studentId)->whereNull('declined_at')->whereNotNull('invited_at'),
            )
            ->whereHas('leader');
    }

    /** @return Collection<int, Registration> */
    private function registrations(): Collection
    {
        $studentId = $this->student()?->getKey();
        $final = array_filter(RegistrationStatus::cases(), fn (RegistrationStatus $s): bool => $s->isFinal());

        return $this->baseQuery()
            ->when(
                $this->tab === 'selesai',
                fn (Builder $q) => $q->whereIn('status', $final),
                fn (Builder $q) => $q->whereIn('status', RegistrationStatus::nonFinal()),
            )
            ->with([
                'competition',
                'report',
                'members' => fn ($q) => $q->where('student_id', $studentId),
            ])
            ->withCount([
                'members as active_members_count' => fn (Builder $m) => $m->whereNull('declined_at'),
                // The people the card names: a teammate in the trash is hidden (its row still counts above).
                'members as shown_members_count' => fn (Builder $m) => $m->whereNull('declined_at')->whereHas('student'),
            ])
            // Needs action first (report due, open invitation), then the most recently changed.
            ->orderByRaw('case when status = ? then 0 when status = ? then 1 else 2 end', [
                RegistrationStatus::MenungguLaporan->value,
                RegistrationStatus::MenungguKonfirmasi->value,
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
    }

    /** The rule of baseQuery() for one registration (the address of a hidden one answers 404). */
    private function isVisibleTo(Registration $registration, Student $student): bool
    {
        return $registration->members()->where('student_id', $student->getKey())->whereNull('declined_at')->whereNotNull('invited_at')->exists()
            && $registration->leader()->exists();
    }

    /**
     * The ketua sent the report in another tab since this card was drawn (QA9; QA8 left it): ReportPolicy::create
     * refused "Laporkan hasil" with a full-screen 403. Say so and draw the card again. A teammate never had the
     * button and still gets the 403.
     */
    private function reportSentElsewhere(Registration $registration): bool
    {
        $report = $registration->report()->first();
        $leader = (string) $registration->leader_student_id === (string) $this->student()?->getKey();

        if (! $leader || $report === null || $report->status === ReportStatus::PerluRevisi) {
            return false;
        }

        $this->lapor = false;
        unset($this->selected, $this->counts);
        $this->dispatch('sth-toast', message: 'Laporan hasil sudah dikirim. Halaman sudah diperbarui.', tone: 'warning');

        return true;
    }

    private function selectedOrFail(): Registration
    {
        $registration = $this->selected;
        abort_if($registration === null, 404);

        return $registration;
    }

    private function student(): ?Student
    {
        return $this->user()->student;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
