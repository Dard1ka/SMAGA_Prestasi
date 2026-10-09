<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Pengaturan;

use App\Actions\Settings\UpdateLimits;
use App\Domain\Archive\GraduationCalculator;
use App\Domain\Deadlines\ReportDeadline;
use App\Enums\SettingKey;
use App\Livewire\Admin\ArsipOtomatis;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Throwable;

/**
 * Tab "Batas & arsip": report deadline (days after the final, default 45 — D-25; the mockup's 30 is stale)
 * and automatic archive (months after the start of kelas XII, default 18 — D-13). Shows live examples,
 * the archive preview for the next 30 days and the latest archived accounts (alumni cannot log in, D-29).
 */
final class BatasArsip extends Component
{
    use RequiresRecentPassword;

    public int|string $reportDeadlineDays = 45;

    public int|string $archiveMonths = 18;

    public function mount(): void
    {
        Gate::authorize('update', Setting::class);
        $this->reportDeadlineDays = Setting::int(SettingKey::ReportDeadlineDays);
        $this->archiveMonths = Setting::int(SettingKey::ArchiveMonthsAfterGrade12);
    }

    public function save(UpdateLimits $update): void
    {
        Gate::authorize('update', Setting::class);

        $this->validate([
            'reportDeadlineDays' => ['required', 'integer', 'between:'.UpdateLimits::MIN_DAYS.','.UpdateLimits::MAX_DAYS],
            'archiveMonths' => ['required', 'integer', 'between:'.UpdateLimits::MIN_MONTHS.','.UpdateLimits::MAX_MONTHS],
        ], [], ['reportDeadlineDays' => 'batas laporan', 'archiveMonths' => 'arsip otomatis']);

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        /** @var User $user */
        $user = auth()->user();
        $result = $update->handle($user, (int) $this->reportDeadlineDays, (int) $this->archiveMonths);

        $message = $result['recalculated'] > 0
            ? "Tersimpan. Tanggal arsip {$result['recalculated']} siswa dihitung ulang."
            : 'Tersimpan.';

        $this->dispatch('sth-toast', message: $message, tone: 'success');
    }

    public function render(): View
    {
        $today = CarbonImmutable::today();
        $recentlyArchived = Student::query()
            ->alumni()
            ->with(['user:id,name,status', 'classroom:id,name'])
            ->withCount('achievements')
            ->orderByDesc('archive_at')
            ->limit(8)
            ->get(['id', 'user_id', 'classroom_id', 'grade12_start_date', 'archive_at']);

        return view('livewire.admin.pengaturan.batas-arsip', [
            'deadlineExample' => $this->deadlineExample(),
            'archiveExample' => $this->archiveExample(),
            // What the nightly job archives: active and pending accounts only (QA9).
            'dueSoon' => Student::query()
                ->active()
                ->whereBetween('archive_at', [$today->toDateString(), $today->addDays(30)->toDateString()])
                ->count(),
            'recentlyArchived' => $recentlyArchived,
            // QA9: the day of the archive (a manual one keeps a future archive_at).
            'archivedOn' => ArsipOtomatis::archivedOn($recentlyArchived),
        ]);
    }

    private function deadlineExample(): ?string
    {
        try {
            $final = CarbonImmutable::create(2026, 9, 24);

            return 'Final 24 Sep 2026 → batas lapor '.(new ReportDeadline((int) $this->reportDeadlineDays))->dueDate($final)->translatedFormat('j M Y').'.';
        } catch (Throwable) {
            return null;
        }
    }

    private function archiveExample(): ?string
    {
        try {
            $grade12 = CarbonImmutable::create(2027, 7, 1);

            return 'Kelas XII mulai Juli 2027 → arsip '.(new GraduationCalculator((int) $this->archiveMonths))->archiveAtFrom($grade12)->translatedFormat('F Y').'.';
        } catch (Throwable) {
            return null;
        }
    }

    protected function returnUrl(): string
    {
        return route('admin.pengaturan', ['tab' => 'batas']);
    }
}
