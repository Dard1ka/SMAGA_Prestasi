<?php

declare(strict_types=1);

namespace App\Livewire\Kesiswaan;

use App\Enums\TalentStatus;
use App\Livewire\Kesiswaan\Concerns\WithKesiswaanBadges;
use App\Models\Competition;
use App\Models\Report;
use App\Models\TalentApplication;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Kesiswaan landing (`kesiswaan.beranda`, UI-MAP §4a, no board): queue sizes + the next items of each queue.
 * Read-only; every link goes to the screen that owns the action.
 */
#[Title('Beranda')]
#[Layout('layouts::app', ['subtitle' => 'Antrean kerja Kesiswaan hari ini.'])]
class Beranda extends Component
{
    use WithKesiswaanBadges;

    public function mount(): void
    {
        $this->authorize('manage', Competition::class);
    }

    public function render(): View
    {
        $now = CarbonImmutable::now();
        $user = auth()->user();
        $badges = $this->kesiswaanBadges();

        $reports = $user?->can('viewQueue', Report::class)
            ? Report::query()->awaitingVerification()
                ->with(['registration' => fn ($q) => $q->withTrashed()->with(['competition' => fn ($c) => $c->withTrashed(), 'leader.user'])])
                ->orderBy('submitted_at')->limit(5)->get()
                ->map(fn (Report $r): array => [
                    'id' => $r->getKey(),
                    'title' => $r->registration?->competition?->name ?? 'Lomba',
                    'sub' => ($r->registration?->leader?->user?->name ?? 'Siswa').' · klaim '.$r->claimed_result->label(),
                    'when' => self::whenLabel($r->submitted_at),
                ])
            : collect();

        $uploads = Competition::query()->pendingVerification()->with('submitter')->oldest()->limit(5)->get()
            ->map(fn (Competition $c): array => [
                'title' => $c->name,
                'sub' => 'Dari '.($c->submitter?->name ?? 'akun dihapus'),
                'when' => self::whenLabel($c->created_at),
            ]);

        $closing = Competition::query()->openForRegistration($now)->orderBy('registration_closes_at')->limit(5)->get()
            ->map(fn (Competition $c): array => [
                'title' => $c->name,
                'bidang' => $c->bidang,
                'date' => self::shortDate($c->registration_closes_at),
            ]);

        $canTalent = $user?->can('manage', TalentApplication::class) ?? false;

        return view('livewire.kesiswaan.beranda', [
            'stats' => [
                ['icon' => 'inbox', 'tone' => 'orange', 'label' => 'Verifikasi usulan lomba', 'value' => (string) $badges['kesiswaan.usulan'], 'note' => 'menunggu diperiksa', 'href' => route('kesiswaan.lomba.index', ['tab' => 'verifikasi'])],
                ['icon' => 'shield', 'tone' => 'blue', 'label' => 'Laporan hasil', 'value' => (string) $badges['kesiswaan.verifikasi'], 'note' => 'menunggu verifikasi', 'href' => route('kesiswaan.verifikasi.index')],
                ['icon' => 'users', 'tone' => 'gray', 'label' => 'Talenta terdaftar', 'value' => (string) ($canTalent ? TalentApplication::query()->inStatus(TalentStatus::Terdaftar)->count() : 0), 'note' => 'belum dipetakan', 'href' => $canTalent ? route('kesiswaan.talenta.index', ['tahap' => 'terdaftar']) : null],
                ['icon' => 'trophy', 'tone' => 'green', 'label' => 'Lomba aktif', 'value' => (string) Competition::query()->openForRegistration($now)->count(), 'note' => 'pendaftaran dibuka', 'href' => route('kesiswaan.lomba.index')],
            ],
            'reports' => $reports,
            'uploads' => $uploads,
            'closing' => $closing,
        ])->layoutData(['badges' => $badges]);
    }
}
