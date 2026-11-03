<?php

declare(strict_types=1);

namespace App\Livewire\Siswa;

use App\Domain\Deadlines\ReportFollowUp;
use App\Domain\Points\PointFormatter;
use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Livewire\Siswa\Lomba\LombaPresenter;
use App\Livewire\Siswa\LombaSaya\Index as LombaSaya;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\User;
use App\Support\FirstName;
use App\Support\Leaderboard\PersonalRank;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Siswa · Beranda (UI-MAP #8/#9, boards Beranda-Siswa + Beranda-Mobile, one responsive component):
 * hero, "Perlu tindakan" (team invites, reports due), Lomba saya (compact trackers), rekomendasi,
 * points card, nearest registration deadlines. Own data only. The first-run tour (Siswa\TurMenu)
 * is embedded here and shows itself until users.tour_seen_at is set, or once after /siswa/tur (TurMenu::FORCE_KEY).
 */
#[Layout('layouts::app')]
#[Title('Beranda')]
class Beranda extends Component
{
    /** "Rekomendasi untukmu": four capsules = two full rows of two; the view hides the fourth where it would stand alone. */
    private const RECOMMENDATIONS = 4;

    /** The note of the ketua's action while the report is sent back (no decider named: the card of Lomba saya does that). */
    public const REVISION_NOTE = 'Laporan hasilmu perlu revisi. Perbaiki, lalu kirim ulang.';

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();
        $student = $user->loadMissing('student.classroom')->getRelation('student');
        $today = CarbonImmutable::today();

        $points = $student ? $this->points($student) : ['total' => '0', 'rank' => null, 'count' => 0];
        $openTotal = Competition::query()->openForRegistration()->count();
        $mine = $student ? $this->myRegistrations($student) : [];
        // "Lomba saya" lists running registrations only (as Lomba saya › Berjalan): with none, its empty state says
        // "Belum ada lomba berjalan" to a student whose registrations are all over (SV1 browser check: such a student
        // was told "Belum ada lomba yang kamu ikuti"), and keeps that sentence for a student who never registered.
        $pastOnly = $mine === [] && $student !== null && $this->visibleRegistrations($student)->exists();

        return view('livewire.siswa.beranda', [
            'firstName' => FirstName::of($user->name) ?? 'Siswa',
            // "Masih ada N lomba yang bisa kamu daftar": open lomba this student is not in yet (the lead, 2026-10-08).
            // openTotal tells "nothing is open" from "you are in every open lomba" when N is 0.
            'openCount' => $student ? $this->openFor($student)->count() : $openTotal,
            'openTotal' => $openTotal,
            'actions' => $student ? $this->actions($student) : [],
            'mine' => $mine,
            'pastOnly' => $pastOnly,
            'points' => $points,
            'deadlines' => $this->deadlines($today),
            'recommendations' => $student ? $this->recommendations($student) : ['subtitle' => null, 'cards' => []],
            'links' => [
                'katalog' => route('siswa.lomba.index'),
                // SV1 review: with every lomba over, the hint names the tab Selesai, so "Lihat semua" opens it (the tab
                // Berjalan is empty for exactly this student).
                'lombaSaya' => $this->route('siswa.lomba-saya.index', $pastOnly ? ['tab' => 'selesai'] : []),
                'peringkat' => $this->route('siswa.peringkat') ?? $this->route('public.peringkat'),
            ],
        ])->layoutData(['subtitle' => ucfirst($today->locale('id')->translatedFormat('l, j F Y'))]);
    }

    /**
     * Team invites waiting for this student, then reports this student (as ketua) still has to send.
     *
     * @return list<array<string, mixed>>
     */
    private function actions(Student $student): array
    {
        $actions = [];

        $invites = RegistrationMember::query()
            ->where('student_id', $student->getKey())
            ->where('role', MemberRole::Anggota)
            ->pending()
            ->whereHas('registration', fn (Builder $r) => $r->where('status', RegistrationStatus::MenungguKonfirmasi))
            ->with(['registration.competition', 'registration.leader.user:id,name'])
            ->oldest()
            ->get();

        foreach ($invites as $invite) {
            $competition = $invite->registration->competition;
            // D-148: a team resumed late answers until its own moment, not the registration deadline.
            $grace = $invite->registration->graceText();
            $actions[] = [
                'key' => 'invite-'.$invite->getKey(),
                'icon' => 'users',
                'title' => 'Undangan tim · '.$competition->name,
                'note' => 'Dari '.$invite->registration->leader?->user?->name.' · '
                    .($grace !== null ? 'jawab sebelum '.$grace : 'batas daftar '.LombaPresenter::date($competition->registration_closes_at)),
                'button' => 'Lihat undangan',
                'href' => route('siswa.undangan.show', $invite->registration),
            ];
        }

        $reports = Registration::query()
            ->where('leader_student_id', $student->getKey())
            ->where('status', RegistrationStatus::MenungguLaporan)
            ->with(['competition', 'report:id,registration_id,status'])
            ->get();

        foreach ($reports as $registration) {
            // D-151: no deadline and no days left, only the request. A report sent back asks for the revision, in the
            // words of Lomba saya's card ("Perbaiki laporan"; third browser check: it asked to report as if nothing was sent).
            $revising = $registration->report?->status === ReportStatus::PerluRevisi;
            $actions[] = [
                'key' => 'report-'.$registration->getKey(),
                'icon' => 'upload',
                'title' => $registration->competition->name.' · '.($revising ? 'perbaiki laporan' : 'laporan hasil'),
                'note' => $revising ? self::REVISION_NOTE : ReportFollowUp::PROMPT,
                'button' => $revising ? 'Perbaiki laporan' : 'Laporkan hasil',
                // UX2: the yellow card of Lomba saya, already open (one place to report).
                'href' => route('siswa.lomba-saya.show', ['registration' => $registration->getKey(), 'lapor' => 1]).'#lapor-judul',
            ];
        }

        return $actions;
    }

    /**
     * The registrations this student sees, as on Lomba saya: a member who did not decline, and not before the invitation
     * went out (D-148: an anggota of a team whose lomba still waits was not invited yet).
     *
     * @return Builder<Registration>
     */
    private function visibleRegistrations(Student $student): Builder
    {
        return Registration::query()
            ->whereHas('members', fn (Builder $m) => $m->where('student_id', $student->getKey())->whereNull('declined_at')->whereNotNull('invited_at'));
    }

    /**
     * The running ones (Lomba saya › Berjalan), most recently changed first. SV1 review: only the ketua reports
     * (ReportPolicy::create), so a team member waiting for the report gets the neutral chip and tracker state of Lomba
     * saya (LombaSaya::MEMBER_REPORT_CHIP / MEMBER_REVISION_CHIP, LombaSaya::trackerStateFor()), never the orange
     * "perlu tindakan" of the ketua.
     *
     * @return list<array<string, mixed>>
     */
    private function myRegistrations(Student $student): array
    {
        return $this->visibleRegistrations($student)
            ->nonFinal()
            ->with(['competition', 'report:id,registration_id,status'])
            ->withCount(['members as team_size' => fn (Builder $m) => $m->whereNull('declined_at')])
            ->latest('updated_at')
            ->limit(3)
            ->get()
            ->map(function (Registration $r) use ($student): array {
                $competition = $r->competition;
                $reports = (string) $r->leader_student_id === (string) $student->getKey();
                $revising = $r->report?->status === ReportStatus::PerluRevisi;
                // The chips of Lomba saya: a team short of people (D-36, decided from the loaded `team_size`, which has
                // the filter of ConfirmMembership::teamIsShort(), so no COUNT query per card) is "Tim kurang anggota",
                // orange for the ketua; a report sent back is "Perlu revisi" for the ketua. D-151: "Menunggu laporan"
                // without a due date.
                ['chip' => $label, 'tone' => $tone] = match (true) {
                    LombaSaya::isShortTeam($r, (int) $r->team_size) => LombaSaya::shortTeamChip($reports),
                    $r->status !== RegistrationStatus::MenungguLaporan => ['chip' => $r->status->label(), 'tone' => $r->status->tone()],
                    $reports && $revising => LombaSaya::LEADER_REVISION_CHIP,
                    $reports => ['chip' => $r->status->label(), 'tone' => $r->status->tone()],
                    $revising => LombaSaya::MEMBER_REVISION_CHIP,
                    default => LombaSaya::MEMBER_REPORT_CHIP,
                };

                return [
                    'id' => $r->getKey(),
                    'title' => $competition->name,
                    'meta' => LombaPresenter::tingkat($competition).' · '.($r->isTeam() ? 'Tim '.$r->team_size : 'Individu'),
                    'bidang' => $competition->bidang?->value ?? 'sains',
                    'status' => $r->status,
                    'trackerState' => LombaSaya::trackerStateFor($r, $reports),
                    'label' => $label,
                    'tone' => $tone,
                    'href' => $this->route('siswa.lomba-saya.show', ['registration' => $r->getKey()]) ?? route('siswa.lomba.show', $competition),
                ];
            })
            ->all();
    }

    /**
     * Cumulative points (all years) and the personal rank among active students (D-37, PersonalRank —
     * same number as Portofolio and the public student page).
     *
     * @return array{total: string, rank: ?int, count: int}
     */
    private function points(Student $student): array
    {
        $summary = app(PersonalRank::class)->summary($student);

        return [
            'total' => app(PointFormatter::class)->format($summary['total']),
            'rank' => $summary['rank'],
            'count' => $summary['count'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function deadlines(CarbonImmutable $today): array
    {
        return Competition::query()
            ->openForRegistration()
            ->orderBy('registration_closes_at')
            ->limit(3)
            ->get()
            ->map(function (Competition $c) use ($today): array {
                $days = LombaPresenter::daysUntil($c->registration_closes_at, $today);

                return [
                    'id' => $c->getKey(),
                    'day' => $c->registration_closes_at->translatedFormat('j'),
                    'month' => $c->registration_closes_at->locale('id')->translatedFormat('M'),
                    'title' => $c->name,
                    'bidang' => $c->bidang?->label(),
                    'left' => $days <= 0 ? 'Ditutup hari ini' : ($days <= 3 ? "Ditutup {$days} hari lagi" : "{$days} hari lagi"),
                    'urgent' => $days <= 3,
                    'href' => route('siswa.lomba.show', $c),
                ];
            })
            ->all();
    }

    /**
     * Open lomba this student can still register for: published, registration open, and the student is not in a
     * running registration of it (as ketua or as a member who has not declined). A rejected or cancelled
     * registration does not count: the student may register again. The banner counts these and the
     * recommendations are picked from them, so the two never disagree.
     *
     * @return Builder<Competition>
     */
    private function openFor(Student $student): Builder
    {
        return Competition::query()
            ->openForRegistration()
            ->whereDoesntHave('registrations', fn (Builder $r) => $r
                ->whereIn('status', RegistrationStatus::nonFinal())
                ->whereHas('members', fn (Builder $m) => $m->where('student_id', $student->getKey())->whereNull('declined_at')));
    }

    /**
     * Open lomba the student is not in yet, preferring the bidang of their talent applications. The cards of those bidang
     * come first and other open lomba fill up to RECOMMENDATIONS; the subtitle says which of the two the list holds, so
     * it never claims "Sesuai bidang minatmu" over a card of another bidang (SV1 browser check). It names no count: the
     * view hides the fourth card at some widths. Without a card of those bidang it says why: none is open, or the student
     * is already in every open one (openFor() leaves those out, so "belum ada … yang dibuka" would be wrong there).
     *
     * @return array{subtitle: ?string, cards: list<array<string, mixed>>}
     */
    private function recommendations(Student $student): array
    {
        $interests = TalentApplication::query()
            ->where('student_id', $student->getKey())
            ->with('talentType:id,bidang')
            ->get()
            ->map(fn (TalentApplication $t) => $t->talentType?->bidang)
            ->filter()
            ->unique(fn ($b) => $b->value)
            ->values();

        $base = $this->openFor($student)->orderBy('registration_closes_at');
        $interestValues = $interests->map(fn ($b) => $b->value)->all();

        $picked = $interests->isEmpty()
            ? new EloquentCollection
            : (clone $base)->whereIn('bidang', $interestValues)->limit(self::RECOMMENDATIONS)->get();
        // The cards of the student's own bidang; any card after them is another open lomba.
        $matching = $picked->count();

        if ($picked->count() < self::RECOMMENDATIONS) {
            $picked = $picked->concat((clone $base)->whereKeyNot($picked->modelKeys())->limit(self::RECOMMENDATIONS - $picked->count())->get());
        }

        $registrants = LombaPresenter::registrants($picked);

        // FS1 QA: a list in plain Indonesian ("Sains dan Olahraga", "Sains, Seni Budaya, dan Olahraga"), not "A & B & C".
        $labels = $interests->map(fn ($b) => $b->label());
        $bidang = $labels->count() > 2 ? $labels->join(', ', ', dan ') : $labels->join(' dan ');

        return [
            'subtitle' => match (true) {
                $interests->isEmpty() => 'Pendaftaran yang sedang dibuka',
                $matching === $picked->count() => 'Sesuai bidang minatmu: '.$bidang,
                // No card of the student's bidang: an open lomba of them that is not listed has the student in it already.
                $matching === 0 => (Competition::query()->openForRegistration()->whereIn('bidang', $interestValues)->exists()
                    ? 'Kamu sudah terdaftar di semua lomba bidang minatmu ('.$bidang.') yang dibuka.'
                    : 'Belum ada lomba bidang minatmu ('.$bidang.') yang dibuka.').' Ini pendaftaran lain yang sedang dibuka.',
                // Holds at every width: the cards of the student's bidang stand first, the hidden fourth is another one.
                default => 'Lomba bidang minatmu ('.$bidang.') ditampilkan lebih dulu',
            },
            'cards' => $picked->map(fn (Competition $c): array => LombaPresenter::card($c, $registrants, route('siswa.lomba.show', $c)))->all(),
        ];
    }

    /** @param array<string, mixed> $parameters */
    private function route(string $name, array $parameters = []): ?string
    {
        return Route::has($name) ? route($name, $parameters) : null;
    }
}
