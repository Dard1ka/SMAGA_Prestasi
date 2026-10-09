<?php

declare(strict_types=1);

namespace App\Livewire\Guru;

use App\Enums\RegistrationStatus;
use App\Enums\TalentStatus;
use App\Livewire\Guru\Concerns\PresentsBinaan;
use App\Livewire\Siswa\LombaSaya\Concerns\ReportHelpers;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\TalentApplication;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Guru · Siswa binaan (UI-MAP §4e `guru.beranda`, backlog #3, owner B4). The guru's own binaan only:
 * registrations where `mentor_id` = the guru (status, compact Jejak Prestasi tracker, report state) and
 * talent applications where `mentor_id` = the guru (pembinaan stage). Read-only; one registration opens
 * in Guru\BinaanDetail (`guru.binaan.show`, 404 for anyone else's). Topbar search posts `?q=` here.
 */
#[Title('Siswa binaan')]
// One search box per screen (D-90, backlog #60): this page has its own, so the top bar shows none.
#[Layout('layouts::app', ['subtitle' => 'Siswa bimbingan di lomba dan pembinaan talenta.', 'search' => false])]
final class Binaan extends Component
{
    use PresentsBinaan, ReportHelpers;

    public const STEP = 20;

    private const TABS = ['aktif', 'selesai', 'talenta'];

    #[Url(as: 'tab', except: 'aktif')]
    public string $tab = 'aktif';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    public int $limit = self::STEP;

    public function mount(): void
    {
        Gate::authorize('viewBinaan', Registration::class);
        $this->normalize();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'q'], true)) {
            $this->limit = self::STEP;
        }

        $this->normalize();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->limit = self::STEP;
        $this->normalize();
    }

    public function showMore(): void
    {
        $this->limit += self::STEP;
    }

    public function render(): View
    {
        Gate::authorize('viewBinaan', Registration::class);
        $this->normalize();

        $counts = $this->counts();
        $isTalent = $this->tab === 'talenta';
        $query = $isTalent ? $this->talentQuery() : $this->registrationQuery($this->tab === 'selesai');
        $total = (clone $query)->count();
        $items = $query->limit($this->limit)->get();

        return view('livewire.guru.binaan', [
            'counts' => $counts,
            'isTalent' => $isTalent,
            'registrations' => $isTalent ? [] : $items->map(fn (Registration $r): array => $this->registrationCard($r))->all(),
            'talents' => $isTalent ? $items->map(fn (TalentApplication $a): array => $this->talentRow($a))->all() : [],
            // QA9: the caption "Pembinaan talenta, N siswa" counts students, once each (one may have two choices).
            'talentStudents' => $isTalent ? (clone $query)->reorder()->distinct()->count('student_id') : 0,
            'total' => $total,
            'hasMore' => $total > $this->limit,
            'lombaUrl' => route('guru.lomba.index'),
        ]);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $mine = fn (): Builder => Registration::query()->where('mentor_id', $this->user()->getKey());

        $students = RegistrationMember::query()
            ->whereNull('declined_at')
            ->whereHas('registration', fn (Builder $r) => $r->where('mentor_id', $this->user()->getKey()))
            ->pluck('student_id')
            ->merge(TalentApplication::query()->where('mentor_id', $this->user()->getKey())->pluck('student_id'))
            ->unique();

        return [
            'siswa' => $students->count(),
            'aktif' => $mine()->whereIn('status', RegistrationStatus::nonFinal())->count(),
            'selesai' => $mine()->whereNotIn('status', RegistrationStatus::nonFinal())->count(),
            'laporan' => $mine()->where('status', RegistrationStatus::MenungguLaporan)->count(),
            'verifikasi' => $mine()->where('status', RegistrationStatus::LaporanDikirim)->count(),
            // The Talenta tab lists every choice the guru is pembina of, "Tidak lolos" included: it counts rows.
            'talenta' => TalentApplication::query()->where('mentor_id', $this->user()->getKey())->count(),
            // QA9: the tile "Pembinaan talenta · siswa yang dibina" counts students, once each, whose choice with this
            // guru is still open (Terdaftar … Peserta pembinaan). Kesiswaan names the pembina during the selection and a
            // restore keeps him (D-104), so every open stage is coached; a "Tidak lolos" choice is not.
            'dibina' => TalentApplication::query()
                ->where('mentor_id', $this->user()->getKey())
                ->where('status', '!=', TalentStatus::TidakLolos->value)
                ->distinct()
                ->count('student_id'),
        ];
    }

    /** @return Builder<Registration> own binaan only (mentor_id = the guru) */
    private function registrationQuery(bool $finished): Builder
    {
        $term = trim($this->q);

        return Registration::query()
            ->where('mentor_id', $this->user()->getKey())
            ->when($finished,
                fn (Builder $q) => $q->whereNotIn('status', RegistrationStatus::nonFinal()),
                fn (Builder $q) => $q->whereIn('status', RegistrationStatus::nonFinal()))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereHas('competition', fn (Builder $c) => $c->withTrashed()->where('name', 'like', $this->like($term)))
                ->orWhereHas('members', fn (Builder $m) => $m->whereNull('declined_at')
                    ->whereHas('student.user', fn (Builder $u) => $u->where('name', 'like', $this->like($term))))))
            ->with([
                'competition',
                'report:id,registration_id,status',
                'members' => fn ($m) => $m->whereNull('declined_at')->orderByRaw("CASE WHEN role = 'ketua' THEN 0 ELSE 1 END"),
                'members.student:id,user_id,classroom_id',
                'members.student.user:id,name',
                'members.student.classroom:id,name',
            ])
            ->latest('updated_at')
            ->orderBy('id');
    }

    /** @return Builder<TalentApplication> own pembinaan only (mentor_id = the guru) */
    private function talentQuery(): Builder
    {
        $term = trim($this->q);

        return TalentApplication::query()
            ->where('mentor_id', $this->user()->getKey())
            ->when($term !== '', fn (Builder $q) => $q->whereHas('student.user', fn (Builder $u) => $u->where('name', 'like', $this->like($term))))
            ->with(['student:id,user_id,classroom_id', 'student.user:id,name', 'student.classroom:id,name', 'talentType', 'talentBranch'])
            ->latest('updated_at')
            ->orderBy('id');
    }

    /** @return array<string, mixed> */
    private function registrationCard(Registration $registration): array
    {
        $competition = $registration->competition;
        $members = $registration->members;
        [$chip, $tone] = $this->binaanChip($registration);

        return [
            'id' => (string) $registration->getKey(),
            'href' => route('guru.binaan.show', $registration),
            'title' => $competition?->name ?? 'Lomba',
            'bidang' => $competition?->bidang,
            'members' => $members->map(fn (RegistrationMember $m): string => trim(($m->student?->user?->name ?? 'Siswa').' ('.($m->student?->classroom?->name ?? '–').')'))->all(),
            'type' => $this->binaanType($registration, $members->count()),
            'status' => $registration->status,
            'chip' => $chip,
            'tone' => $tone,
            'date' => self::dateRange($competition?->starts_on, $competition?->final_on),
        ];
    }

    /** @return array<string, mixed> */
    private function talentRow(TalentApplication $application): array
    {
        $status = $application->status;

        return [
            'id' => (string) $application->getKey(),
            'name' => $application->student?->user?->name ?? 'Siswa',
            'kelas' => $application->student?->classroom?->name ?? '–',
            'type' => $application->talentType?->code?->label() ?? 'Talenta',
            'branch' => $application->talentBranch?->name,
            'bidang' => $application->talentType?->bidang,
            'status' => $status->label(),
            'tone' => $status->tone(),
            'step' => $status === TalentStatus::TidakLolos ? null : $status->step(),
            'assessment' => $application->assessment_on !== null ? self::longDate($application->assessment_on) : null,
            // Kesiswaan's "catatan untuk guru pembina" (internal). The note for the student is not shown here.
            'note' => $application->note !== null ? Str::limit($application->note, 300) : null,
        ];
    }

    private function like(string $term): string
    {
        return '%'.addcslashes(mb_substr($term, 0, 100), '%_\\').'%';
    }

    private function normalize(): void
    {
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'aktif';
        $this->q = mb_substr(trim($this->q), 0, 100);
        $this->limit = max(self::STEP, min(500, $this->limit));
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
