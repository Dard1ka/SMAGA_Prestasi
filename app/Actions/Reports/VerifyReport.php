<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Actions\Achievements\RecordAchievements;
use App\Domain\Registrations\RegistrationStateMachine;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Achievement;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Report;
use App\Models\User;
use App\Models\WeightVersion;
use App\Notifications\Verification\AchievementReadyForPublication;
use App\Notifications\Verification\BinaanReportDecided;
use App\Notifications\Verification\ReportVerified;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Kesiswaan verifies a laporan hasil (UI-MAP #17): all four evidence checks ticked, final capaian set
 * (may differ from the student's claim). Creates one Achievement per confirmed member with full points
 * from the ACTIVE weight version (snapshot), report → diverifikasi, registration → selesai, one Humas
 * publication row (`siap`) per achievement. Notifies the members, the guru pembimbing (D-110) and Humas (queued,
 * after commit).
 */
final class VerifyReport
{
    /** Evidence checklist (mockup Kesiswaan-Verifikasi-Hasil): key → label. All are required. */
    public const CHECKS = [
        'nama_peserta' => 'Nama peserta sesuai',
        'nama_lomba' => 'Nama lomba sesuai',
        'sertifikat_asli' => 'Sertifikat asli',
        'peringkat_sesuai' => 'Peringkat sesuai pengumuman',
    ];

    public function __construct(
        private readonly RecordAchievements $recordAchievements,
        private readonly RegistrationStateMachine $stateMachine,
    ) {}

    /**
     * @param  array<string, bool>|list<string>  $checks  ticked check keys (list) or key => bool
     * @return Collection<int, Achievement>
     */
    public function handle(User $actor, Report $report, ResultType $finalResult, array $checks, ?string $note = null): Collection
    {
        Gate::forUser($actor)->authorize('verify', $report);

        if (self::missingChecks($checks) !== []) {
            throw ValidationException::withMessages(['checks' => 'Lengkapi 4 pemeriksaan bukti dulu.']);
        }

        $note = trim((string) $note);

        if (mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['note' => 'Catatan maksimal 1.000 karakter.']);
        }

        $version = WeightVersion::active();

        if ($version === null) {
            throw ValidationException::withMessages(['final_result' => 'Bobot poin belum diatur Admin.']);
        }

        [$lockedReport, $achievements] = DB::transaction(function () use ($actor, $report, $finalResult, $note, $version): array {
            /** @var Report $lockedReport */
            $lockedReport = Report::query()->whereKey($report->getKey())->lockForUpdate()->firstOrFail();
            /** @var Registration $registration */
            $registration = Registration::query()->whereKey($lockedReport->registration_id)->lockForUpdate()->firstOrFail();

            self::assertOpen($lockedReport, $registration);
            $this->stateMachine->assertTransition($registration->status, RegistrationStatus::Selesai);

            /** @var Competition $competition */
            $competition = Competition::withTrashed()->findOrFail($registration->competition_id);
            $now = now();

            $achievements = $this->recordAchievements->handle($actor, $lockedReport, $registration, $competition, $finalResult, $version, $now);

            $lockedReport->forceFill([
                'status' => ReportStatus::Diverifikasi,
                'final_result' => $finalResult,
                'verified_by' => $actor->getKey(),
                'verified_at' => $now,
                'note' => $note === '' ? null : $note,
            ])->save();

            $registration->forceFill(['status' => RegistrationStatus::Selesai])->save();

            activity('report')
                ->performedOn($lockedReport)
                ->causedBy($actor)
                ->event('report.verified')
                ->withProperties([
                    'registration_id' => $registration->getKey(),
                    'claimed_result' => $lockedReport->claimed_result?->value,
                    'final_result' => $finalResult->value,
                    'points' => $achievements->first()?->points,
                    'members' => $achievements->count(),
                    'weight_version_id' => $version->getKey(),
                    'checks' => array_keys(self::CHECKS),
                ])
                ->log('report.verified');

            return [$lockedReport, $achievements];
        });

        $report->setRawAttributes($lockedReport->getAttributes(), true);

        // New verified achievements change the public pages (W2-D request): drop the cached results.
        PublicAchievements::flush();

        $this->notify($lockedReport, $achievements);

        return $achievements;
    }

    /**
     * Check labels not ticked yet (empty = complete).
     *
     * @param  array<string, bool>|list<string>  $checks
     * @return list<string>
     */
    public static function missingChecks(array $checks): array
    {
        $ticked = array_is_list($checks)
            ? array_map('strval', $checks)
            : array_keys(array_filter($checks, fn (mixed $v): bool => (bool) $v));

        return array_values(array_diff_key(self::CHECKS, array_flip($ticked)));
    }

    /** Shared state guard for verify / revise / reject. */
    public static function assertOpen(Report $report, Registration $registration): void
    {
        if ($report->status !== ReportStatus::Dikirim || $registration->status !== RegistrationStatus::LaporanDikirim) {
            throw ValidationException::withMessages(['report' => 'Laporan ini sudah diputuskan.']);
        }
    }

    /** @param Collection<int, Achievement> $achievements */
    private function notify(Report $report, Collection $achievements): void
    {
        $achievements->load(['student.user', 'competition']);

        foreach ($achievements as $achievement) {
            $user = $achievement->student?->user;

            if ($user !== null && $user->canLogIn()) {
                $user->notify(ReportVerified::for($achievement));
            }
        }

        if ($achievements->isNotEmpty()) {
            RequestReportRevision::mentorUser($report)?->notify(BinaanReportDecided::verified($report, $achievements));
        }

        $humas = User::query()->role(RoleName::Humas->value)->canLogIn()->get();

        if ($humas->isNotEmpty() && $achievements->isNotEmpty()) {
            Notification::send($humas, AchievementReadyForPublication::for($achievements->first(), $achievements->count()));
        }
    }
}
