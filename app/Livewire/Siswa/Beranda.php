<?php

declare(strict_types=1);

namespace App\Livewire\Siswa;

use App\Domain\Deadlines\ReportDeadline;
use App\Domain\Points\PointFormatter;
use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Livewire\Siswa\Lomba\LombaPresenter;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\User;
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

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();
        $student = $user->loadMissing('student.classroom')->getRelation('student');
        $today = CarbonImmutable::today();

        $points = $student ? $this->points($student) : ['total' => '0', 'rank' => null, 'count' => 0];
        $openTotal = Competition::query()->openForRegistration()->count();

        return view('livewire.siswa.beranda', [
            'firstName' => strtok(trim((string) $user->name), ' ') ?: 'Siswa',
            // "Masih ada N lomba yang bisa kamu daftar": open lomba this student is not in yet (the lead, 2026-10-08).
            // openTotal tells "nothing is open" from "you are in every open lomba" when N is 0.
            'openCount' => $student ? $this->openFor($student)->count() : $openTotal,
            'openTotal' => $openTotal,
            'actions' => $student ? $this->actions($student, $today) : [],
            'mine' => $student ? $this->myRegistrations($student) : [],
            'points' => $points,
            'deadlines' => $this->deadlines($today),
            'recommendations' => $student ? $this->recommendations($student) : ['subtitle' => null, 'cards' => []],
            'links' => [
                'katalog' => route('siswa.lomba.index'),
                'lombaSaya' => $this->route('siswa.lomba-saya.index'),
                'peringkat' => $this->route('siswa.peringkat') ?? $this->route('public.peringkat'),
            ],
        ])->layoutData(['subtitle' => ucfirst($today->locale('id')->translatedFormat('l, j F Y'))]);
    }

    /**
     * Team invites waiting for this student, then reports this student (as ketua) still has to send.
     *
     * @return list<array<string, mixed>>
     */
    private function actions(Student $student, CarbonImmutable $today): array
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
            $actions[] = [
                'key' => 'invite-'.$invite->getKey(),
                'icon' => 'users',
                'title' => 'Undangan tim · '.$competition->name,
                'note' => 'Dari '.$invite->registration->leader?->user?->name.' · batas daftar '.LombaPresenter::date($competition->registration_closes_at),
                'button' => 'Lihat undangan',
                'href' => route('siswa.undangan.show', $invite->registration),
            ];
        }

        $deadline = app(ReportDeadline::class);
        $reports = Registration::query()
            ->where('leader_student_id', $student->getKey())
            ->where('status', RegistrationStatus::MenungguLaporan)
            ->with('competition')
            ->get();

        foreach ($reports as $registration) {
            $final = $registration->competition->final_on ?? $registration->competition->starts_on;

            if ($final === null) {
                continue;
            }

            $due = $deadline->dueDate($final);
            $left = $deadline->daysLeft($final, $today);
            $actions[] = [
                'key' => 'report-'.$registration->getKey(),
                'icon' => 'upload',
                'title' => $registration->competition->name.' · laporan hasil',
                'note' => 'Batas '.LombaPresenter::date($due).' · '.($left > 0 ? "{$left} hari lagi" : ($left === 0 ? 'hari ini' : 'terlambat')),
                'button' => 'Laporkan hasil',
                'href' => $this->route('siswa.lapor', ['registration' => $registration->getKey()])
                    ?? $this->route('siswa.lomba-saya.index')
                    ?? route('siswa.beranda'),
                'sort' => $left,
            ];
        }

        return $actions;
    }

    /** @return list<array<string, mixed>> */
    private function myRegistrations(Student $student): array
    {
        $deadline = app(ReportDeadline::class);

        return Registration::query()
            ->nonFinal()
            ->whereHas('members', fn (Builder $m) => $m->where('student_id', $student->getKey())->whereNull('declined_at'))
            ->with('competition')
            ->withCount(['members as team_size' => fn (Builder $m) => $m->whereNull('declined_at')])
            ->latest('updated_at')
            ->limit(3)
            ->get()
            ->map(function (Registration $r) use ($deadline): array {
                $competition = $r->competition;
                $label = $r->status->label();
                $final = $competition->final_on ?? $competition->starts_on;

                if ($r->status === RegistrationStatus::MenungguLaporan && $final !== null) {
                    $label .= ' · batas '.$deadline->dueDate($final)->locale('id')->translatedFormat('j M');
                }

                return [
                    'id' => $r->getKey(),
                    'title' => $competition->name,
                    'meta' => LombaPresenter::tingkat($competition).' · '.($r->isTeam() ? 'Tim '.$r->team_size : 'Individu'),
                    'bidang' => $competition->bidang?->value ?? 'sains',
                    'status' => $r->status,
                    'label' => $label,
                    'tone' => $r->status->tone(),
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
     * Open lomba the student is not in yet, preferring the bidang of their talent applications.
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

        $picked = $interests->isEmpty()
            ? new EloquentCollection
            : (clone $base)->whereIn('bidang', $interests->map(fn ($b) => $b->value)->all())->limit(self::RECOMMENDATIONS)->get();

        if ($picked->count() < self::RECOMMENDATIONS) {
            $picked = $picked->concat((clone $base)->whereKeyNot($picked->modelKeys())->limit(self::RECOMMENDATIONS - $picked->count())->get());
        }

        $registrants = LombaPresenter::registrants($picked);

        return [
            'subtitle' => $interests->isEmpty()
                ? 'Pendaftaran yang sedang dibuka'
                : 'Sesuai bidang minatmu: '.$interests->map(fn ($b) => $b->label())->implode(' & '),
            'cards' => $picked->map(fn (Competition $c): array => LombaPresenter::card($c, $registrants, route('siswa.lomba.show', $c)))->all(),
        ];
    }

    /** @param array<string, mixed> $parameters */
    private function route(string $name, array $parameters = []): ?string
    {
        return Route::has($name) ? route($name, $parameters) : null;
    }
}
