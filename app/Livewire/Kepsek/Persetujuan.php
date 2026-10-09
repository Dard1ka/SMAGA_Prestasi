<?php

declare(strict_types=1);

namespace App\Livewire\Kepsek;

use App\Actions\Registrations\ApproveRegistration;
use App\Actions\Registrations\RejectRegistration;
use App\Enums\Bidang;
use App\Enums\RegistrationStatus;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kepala Sekolah · Persetujuan (mockup Kepsek-Persetujuan, UI-MAP #19). Queue of registrations waiting for
 * the principal, bidang filter, bulk approve (one authorized Action call per row), per-row approve and reject
 * (reason required). Rows decided in this visit stay visible with their result until the page reloads.
 * Admin never reaches this screen (route role + RegistrationPolicy::viewApprovalQueue, D-01).
 */
#[Title('Persetujuan lomba')]
class Persetujuan extends Component
{
    #[Url(as: 'bidang', except: '')]
    public string $bidang = '';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    /** @var list<string> ULIDs ticked for bulk approve; re-validated against the queue on submit. */
    public array $selected = [];

    /** Row whose reject form is open. */
    public ?string $rejecting = null;

    public string $rejectNote = '';

    /** @var array<string, string> registration ULID → 'ok' | 'tolak', decided during this visit */
    #[Locked]
    public array $recent = [];

    /** @var list<string> rejected during this visit without telling any student (accounts not active; QA10, D-109) */
    #[Locked]
    public array $untold = [];

    /**
     * @var list<string> ULIDs of the waiting rows this screen has shown during this visit (QA9): a row among them that
     *                   was cancelled or decided elsewhere meanwhile is answered with a toast, any other id with a 404.
     */
    #[Locked]
    public array $shown = [];

    public const STEP = 20;

    public const MAX = 200;

    /** "Setujui terpilih" asks first and lists the ticked rows (QA6, Q1-10): one click approved a whole page. */
    public const BULK_DIALOG = 'setujui-terpilih';

    /** Rows on screen (clamped to STEP…MAX in visibleQuery()). */
    public int $limit = self::STEP;

    public function tampilkanLebih(): void
    {
        Gate::authorize('viewApprovalQueue', Registration::class);
        $this->limit = min(self::MAX, $this->limit + self::STEP);
    }

    public function mount(): void
    {
        Gate::authorize('viewApprovalQueue', Registration::class);

        if (Bidang::tryFrom($this->bidang) === null) {
            $this->bidang = '';
        }

        $this->q = mb_substr(trim($this->q), 0, 100);
    }

    public function updatedBidang(): void
    {
        if (Bidang::tryFrom($this->bidang) === null) {
            $this->bidang = '';
        }

        $this->selected = [];
        $this->closeReject();
    }

    public function toggleAll(): void
    {
        $pending = $this->pendingIds();
        $this->selected = count(array_intersect($this->selected, $pending)) === count($pending) && $pending !== [] ? [] : $pending;
    }

    public function approve(string $id, ApproveRegistration $approve): void
    {
        $registration = $this->findVisible($id);

        if ($this->decidedElsewhere($registration)) {
            return;
        }

        try {
            $approve->handle($this->user(), $registration);
        } catch (ValidationException $e) {
            $this->dispatch('sth-toast', message: (string) collect($e->errors())->flatten()->first(), tone: 'error');

            return;
        }

        $this->recent[$registration->getKey()] = 'ok';
        $this->selected = array_values(array_diff($this->selected, [$registration->getKey()]));
        $this->closeReject();
        $this->dispatch('sth-toast', message: 'Pendaftaran disetujui. Masuk antrean TU.', tone: 'success');
    }

    public function approveSelected(ApproveRegistration $approve): void
    {
        Gate::authorize('viewApprovalQueue', Registration::class);

        $ticked = array_map('strval', $this->selected);
        $ids = array_values(array_intersect($ticked, $this->pendingIds()));
        // Ticked rows the ketua cancelled or another device decided since the screen showed them (QA9): they used
        // to drop out without a word, and with nothing left the toast asked to pick rows.
        $gone = Registration::query()
            ->whereKey(array_values(array_intersect($ticked, $this->shown)))
            ->where('status', '!=', RegistrationStatus::MenungguKepsek)
            ->count();
        $this->dispatch('sth-modal-close', id: self::BULK_DIALOG);

        if ($ids === []) {
            $this->selected = [];
            $this->dispatch('sth-toast', message: $gone > 0
                ? $gone.' pendaftaran sudah diputuskan atau dibatalkan. Daftar sudah diperbarui.'
                : 'Pilih pendaftaran dulu.', tone: 'warning');

            return;
        }

        $done = 0;
        $failed = $gone;

        foreach ($ids as $id) {
            try {
                $approve->handle($this->user(), $this->findVisible($id));
                $this->recent[$id] = 'ok';
                $done++;
            } catch (ValidationException) {
                $failed++;
            }
        }

        $this->selected = [];
        $this->closeReject();

        $message = $done.' pendaftaran disetujui.'.($failed > 0 ? ' '.$failed.' sudah diputuskan atau dibatalkan sebelumnya.' : '');
        $this->dispatch('sth-toast', message: $message, tone: $failed > 0 ? 'warning' : 'success');
    }

    public function openReject(string $id): void
    {
        $registration = $this->findVisible($id);

        if ($this->decidedElsewhere($registration)) {
            return;
        }

        Gate::authorize('decide', $registration);

        $this->rejecting = $registration->getKey();
        $this->rejectNote = '';
        $this->resetErrorBag();
    }

    public function closeReject(): void
    {
        $this->rejecting = null;
        $this->rejectNote = '';
        $this->resetErrorBag();
    }

    public function reject(RejectRegistration $reject): void
    {
        if ($this->rejecting === null) {
            return;
        }

        $registration = $this->findVisible($this->rejecting);

        if ($this->decidedElsewhere($registration)) {
            return;
        }

        try {
            $reject->handle($this->user(), $registration, $this->rejectNote);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            if (isset($errors['note'])) {
                $this->addError('rejectNote', (string) $errors['note'][0]);

                return;
            }

            $this->dispatch('sth-toast', message: (string) collect($errors)->flatten()->first(), tone: 'error');
            $this->closeReject();

            return;
        }

        $this->recent[$registration->getKey()] = 'tolak';
        $this->selected = array_values(array_diff($this->selected, [$registration->getKey()]));
        $this->closeReject();

        // QA10 (D-109): claim a notice only for the members RejectRegistration told (they can log in).
        ['told' => $told, 'of' => $of] = RejectRegistration::studentsTold($registration);

        if ($told === 0) {
            $this->untold[] = (string) $registration->getKey();
        }

        $this->dispatch('sth-toast', message: match (true) {
            $told === 0 => 'Pendaftaran ditolak. Akun siswa tidak aktif, jadi tidak ada notifikasi.',
            $told < $of => 'Penolakan terkirim ke siswa yang akunnya aktif.',
            default => 'Penolakan terkirim ke siswa.',
        }, tone: 'success');
    }

    public function render(): View
    {
        $rows = $this->rows();
        $pendingIds = $rows->where('status', RegistrationStatus::MenungguKepsek)->modelKeys();
        $this->shown = array_values(array_unique([...$this->shown, ...array_map('strval', $pendingIds)]));
        $waiting = Registration::query()->awaitingPrincipal()->count();
        $matching = $this->filtered(Registration::query()->awaitingPrincipal())->count();

        // The sidebar is rendered once per page load: tell it the queue size after every approve/reject (app.js).
        $this->dispatch('sth-badges', badges: ['kepsek.persetujuan' => $waiting]);

        return view('livewire.kepsek.persetujuan', [
            'rows' => $rows,
            'selectedRows' => $rows->whereIn('id', array_intersect($this->selected, $pendingIds))->values(),
            'more' => $this->limit < self::MAX ? max(0, $matching - count($pendingIds)) : 0,
            'pendingIds' => $pendingIds,
            'allChecked' => $pendingIds !== [] && array_diff($pendingIds, $this->selected) === [],
            'checkedCount' => count(array_intersect($this->selected, $pendingIds)),
            'bidangOptions' => ['' => 'Semua bidang'] + Bidang::options(),
            'summary' => $this->summary($waiting),
            'upcoming' => $this->upcoming(),
        ])->layoutData([
            'subtitle' => 'Setujui pendaftaran agar Tata Usaha bisa membuat surat tugas.',
            'badges' => ['kepsek.persetujuan' => $waiting],
        ]);
    }

    /** @return Collection<int, Registration> */
    private function rows(): Collection
    {
        return $this->visibleQuery()
            ->with([
                'competition',
                'mentor.staffProfile',
                'members' => fn ($q) => $q->whereNull('declined_at')->orderByRaw("case when role = 'ketua' then 0 else 1 end")->orderBy('created_at'),
                'members.student.user',
                'members.student.classroom',
            ])
            ->get();
    }

    /**
     * The rows on screen: waiting (or decided during this visit), oldest first, at most $limit. A long queue made
     * the page several hundred kB, so the rest comes with "Tampilkan lebih banyak".
     *
     * @return Builder<Registration>
     */
    private function visibleQuery(): Builder
    {
        $recent = array_keys($this->recent);
        $this->limit = max(self::STEP, min(self::MAX, $this->limit));

        return $this->filtered(
            Registration::query()->where(fn (Builder $q) => $q
                ->where('status', RegistrationStatus::MenungguKepsek)
                ->when($recent !== [], fn (Builder $q) => $q->orWhereIn('id', $recent)))
        )
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($this->limit);
    }

    /**
     * Bidang filter + search (student name or lomba name) on top of a base query.
     *
     * @param  Builder<Registration>  $query
     * @return Builder<Registration>
     */
    private function filtered(Builder $query): Builder
    {
        $bidang = Bidang::tryFrom($this->bidang);
        $term = trim($this->q);

        return $query
            ->when($bidang !== null, fn (Builder $q) => $q->whereHas('competition', fn (Builder $c) => $c->withTrashed()->where('bidang', $bidang)))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereHas('competition', fn (Builder $c) => $c->withTrashed()->where('name', 'like', '%'.self::escapeLike($term).'%'))
                ->orWhereHas('members.student.user', fn (Builder $u) => $u->where('name', 'like', '%'.self::escapeLike($term).'%'))));
    }

    /** @return list<string> */
    private function pendingIds(): array
    {
        // Only rows that are on screen: "pilih semua" and bulk approve never touch a registration the Kepsek has not seen.
        /** @var list<string> $ids */
        $ids = $this->filtered(Registration::query()->awaitingPrincipal())
            ->whereIn('id', $this->visibleQuery()->pluck('id'))
            ->pluck('id')->map(fn ($id): string => (string) $id)->all();

        return $ids;
    }

    /**
     * A row of this screen (waiting, decided during this visit, or shown waiting during this visit and changed since:
     * decidedElsewhere() answers that one); anything else is a 404.
     */
    private function findVisible(string $id): Registration
    {
        Gate::authorize('viewApprovalQueue', Registration::class);

        $ids = [...array_map('strval', array_keys($this->recent)), ...$this->shown];

        return Registration::query()
            ->whereKey($id)
            ->where(fn (Builder $q) => $q->where('status', RegistrationStatus::MenungguKepsek)->orWhereIn('id', $ids))
            ->firstOrFail();
    }

    /**
     * The ketua cancelled the row or the Kepala Sekolah decided it on another device since this screen showed it
     * (QA9): findVisible() answered a full-screen 404 and the reason never showed. Say so; the next render drops the
     * row from the list.
     */
    private function decidedElsewhere(Registration $registration): bool
    {
        if ($registration->status === RegistrationStatus::MenungguKepsek) {
            return false;
        }

        $this->selected = array_values(array_diff($this->selected, [$registration->getKey()]));

        if ($this->rejecting === $registration->getKey()) {
            $this->closeReject();
        }

        $this->dispatch('sth-toast', message: ($registration->status === RegistrationStatus::Dibatalkan
            ? 'Pendaftaran ini sudah dibatalkan siswa.'
            : 'Pendaftaran ini sudah diputuskan.').' Daftar sudah diperbarui.', tone: 'warning');

        return true;
    }

    /** @return array{menunggu: int, disetujui: int, ditolak: int} */
    private function summary(int $waiting): array
    {
        $weekStart = CarbonImmutable::now()->startOfWeek();
        $decided = Registration::query()->whereNotNull('principal_decided_at')->where('principal_decided_at', '>=', $weekStart);

        return [
            'menunggu' => $waiting,
            'disetujui' => (clone $decided)->where('status', '!=', RegistrationStatus::Ditolak)->count(),
            'ditolak' => (clone $decided)->where('status', RegistrationStatus::Ditolak)->count(),
        ];
    }

    /**
     * Next five lomba (from today) with the number of SMAGA students in active registrations.
     *
     * @return list<array{day: string, month: string, name: string, meta: string}>
     */
    private function upcoming(): array
    {
        $active = [
            RegistrationStatus::MenungguKepsek, RegistrationStatus::Disetujui,
            RegistrationStatus::SuratTerbit, RegistrationStatus::Berlangsung,
        ];

        $competitions = Competition::query()
            ->whereDate('starts_on', '>=', CarbonImmutable::today())
            ->whereHas('registrations', fn (Builder $q) => $q->whereIn('status', $active))
            ->orderBy('starts_on')
            ->limit(5)
            ->get();

        return $competitions->map(function (Competition $competition) use ($active): array {
            $students = RegistrationMember::query()
                ->whereNull('declined_at')
                ->whereHas('registration', fn (Builder $q) => $q->where('competition_id', $competition->getKey())->whereIn('status', $active))
                ->distinct()
                ->count('student_id');

            return [
                'day' => $competition->starts_on?->format('j') ?? '',
                'month' => $competition->starts_on?->translatedFormat('M') ?? '',
                'name' => $competition->name,
                'meta' => $students.' siswa SMAGA'.($competition->level !== null ? ' · '.$competition->level->shortLabel() : ''),
            ];
        })->all();
    }

    private function user(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        return $user;
    }

    /** "Luring · Kota Semarang"; an online lomba whose place is "Daring" too says it once (QA5, Q1-27). */
    public static function modeAndPlace(?Competition $competition): string
    {
        $mode = (string) ($competition?->mode?->label() ?? '');
        $place = trim((string) ($competition?->location ?? ''));

        return $place === '' || mb_strtolower($place) === mb_strtolower($mode)
            ? $mode
            : ($mode === '' ? $place : $mode.' · '.$place);
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
