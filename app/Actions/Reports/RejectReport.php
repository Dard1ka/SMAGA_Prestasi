<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Domain\Registrations\RegistrationStateMachine;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Models\Registration;
use App\Models\Report;
use App\Models\User;
use App\Notifications\Verification\BinaanReportDecided;
use App\Notifications\Verification\ReportRejected;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Kesiswaan rejects a laporan hasil (reason required): report → ditolak, no achievements, and the
 * registration laporan_dikirim → selesai (the only terminal transition the state machine allows; the
 * tracker shows it as ended without points). The members get the reason (queued), and so does the guru
 * pembimbing (D-110). Nothing takes a rejected report back, so the screen asks first (D-128) and checks the
 * reason with reason() before it opens that dialog.
 */
final class RejectReport
{
    public const NOTE_REQUIRED = 'Tulis alasan penolakan untuk siswa.';

    public function __construct(private readonly RegistrationStateMachine $stateMachine) {}

    /**
     * The reason as it is stored and sent: trimmed, required, at most 1.000 characters (error key `note`).
     *
     * @throws ValidationException
     */
    public static function reason(string $note): string
    {
        return RequestReportRevision::requiredNote($note, self::NOTE_REQUIRED);
    }

    public function handle(User $actor, Report $report, string $note): Report
    {
        Gate::forUser($actor)->authorize('verify', $report);

        $note = self::reason($note);

        $locked = DB::transaction(function () use ($actor, $report, $note): Report {
            /** @var Report $locked */
            $locked = Report::query()->whereKey($report->getKey())->lockForUpdate()->firstOrFail();
            /** @var Registration $registration */
            $registration = Registration::query()->whereKey($locked->registration_id)->lockForUpdate()->firstOrFail();

            VerifyReport::assertOpen($locked, $registration);
            $this->stateMachine->assertTransition($registration->status, RegistrationStatus::Selesai);

            $locked->forceFill([
                'status' => ReportStatus::Ditolak,
                'note' => $note,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
            ])->save();

            $registration->forceFill(['status' => RegistrationStatus::Selesai])->save();

            activity('report')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('report.rejected')
                ->withProperties(['registration_id' => $registration->getKey(), 'note' => $note])
                ->log('report.rejected');

            return $locked;
        });

        $report->setRawAttributes($locked->getAttributes(), true);

        $competitionName = RequestReportRevision::competitionName($locked);

        foreach (RequestReportRevision::memberUsers($locked) as $user) {
            $user->notify(ReportRejected::for($locked, $competitionName, $note));
        }

        RequestReportRevision::mentorUser($locked)?->notify(BinaanReportDecided::rejected($locked, $competitionName, $note));

        return $locked;
    }
}
