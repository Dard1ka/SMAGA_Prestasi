<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Domain\Registrations\RegistrationStateMachine;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Report;
use App\Models\User;
use App\Notifications\Verification\BinaanReportDecided;
use App\Notifications\Verification\ReportRevisionRequested;
use App\Support\StaffRoleLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Kesiswaan asks for a corrected report ("Minta revisi", note required): report → perlu_revisi,
 * registration laporan_dikirim → menunggu_laporan, so the leader can send it again (SubmitReport re-uses
 * the row). The members get the note (queued), and so does the guru pembimbing (D-110).
 */
final class RequestReportRevision
{
    /** Audit event of a revision request; wasRevised() reads it back. */
    public const EVENT_REVISION_REQUESTED = 'report.revision_requested';

    public function __construct(private readonly RegistrationStateMachine $stateMachine) {}

    public function handle(User $actor, Report $report, string $note): Report
    {
        Gate::forUser($actor)->authorize('verify', $report);

        $note = self::requiredNote($note, 'Tulis catatan agar siswa tahu yang perlu diperbaiki.');

        $locked = DB::transaction(function () use ($actor, $report, $note): Report {
            /** @var Report $locked */
            $locked = Report::query()->whereKey($report->getKey())->lockForUpdate()->firstOrFail();
            /** @var Registration $registration */
            $registration = Registration::query()->whereKey($locked->registration_id)->lockForUpdate()->firstOrFail();

            VerifyReport::assertOpen($locked, $registration);
            $this->stateMachine->assertTransition($registration->status, RegistrationStatus::MenungguLaporan);

            $locked->forceFill([
                'status' => ReportStatus::PerluRevisi,
                'note' => $note,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
            ])->save();

            $registration->forceFill(['status' => RegistrationStatus::MenungguLaporan])->save();

            activity('report')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event(self::EVENT_REVISION_REQUESTED)
                ->withProperties(['registration_id' => $registration->getKey(), 'note' => $note])
                ->log(self::EVENT_REVISION_REQUESTED);

            return $locked;
        });

        $report->setRawAttributes($locked->getAttributes(), true);

        $competitionName = self::competitionName($locked);

        $decider = StaffRoleLabel::forDecision($actor);

        foreach (self::memberUsers($locked) as $user) {
            $user->notify(ReportRevisionRequested::for($locked, $competitionName, $note, $decider));
        }

        self::mentorUser($locked)?->notify(BinaanReportDecided::revision($locked, $competitionName, $note, $decider));

        return $locked;
    }

    /**
     * Was this report sent back with "Minta revisi" at least once (FS1 QA-05)? Read from the audit row of that request,
     * as ApproveRegistration::mentorWasReplaced() does. SubmitReport re-uses the row, so a `dikirim` report with such a
     * row is the corrected one the ketua sent again: Verifikasi hasil tags it "Laporan diperbaiki", as the bell and
     * the mail of ReportSubmitted already say "Laporan hasil diperbaiki".
     */
    public static function wasRevised(Report $report): bool
    {
        return Activity::query()
            ->where('event', self::EVENT_REVISION_REQUESTED)
            ->where('subject_type', $report->getMorphClass())
            ->where('subject_id', $report->getKey())
            ->exists();
    }

    public static function requiredNote(string $note, string $message): string
    {
        $note = trim($note);

        if ($note === '') {
            throw ValidationException::withMessages(['note' => $message]);
        }

        if (mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['note' => 'Catatan maksimal 1.000 karakter.']);
        }

        return $note;
    }

    public static function competitionName(Report $report): string
    {
        $competitionId = Registration::withTrashed()->whereKey($report->registration_id)->value('competition_id');

        return (string) (Competition::withTrashed()->whereKey($competitionId)->value('name') ?? 'lomba');
    }

    /**
     * Active accounts of the confirmed members of the report's registration.
     *
     * @return list<User>
     */
    public static function memberUsers(Report $report): array
    {
        $registration = Registration::query()
            ->with(['members' => fn ($q) => $q->whereNotNull('confirmed_at')->whereNull('declined_at'), 'members.student.user'])
            ->find($report->registration_id);

        if ($registration === null) {
            return [];
        }

        $users = [];

        foreach ($registration->members as $member) {
            $user = $member->student?->user;

            if ($user !== null && $user->canLogIn()) {
                $users[] = $user;
            }
        }

        return $users;
    }

    /**
     * The guru pembimbing of the report's registration who hears about a decision (D-110): an active account that
     * still holds the guru role, else null (no guru, frozen, archived or role taken away).
     */
    public static function mentorUser(Report $report): ?User
    {
        $mentorId = Registration::withTrashed()->whereKey($report->registration_id)->value('mentor_id');

        return $mentorId === null ? null : User::query()->gurus()->canLogIn()->whereKey($mentorId)->first();
    }

    /**
     * The report can be sent again after "Minta revisi": only the ketua may send it (ReportPolicy::create), so only
     * while the ketua's account can log in. A team member who was told (audience()) cannot do it in the ketua's place.
     */
    public static function leaderCanResend(Report $report): bool
    {
        $leader = Registration::query()->with('leader.user')->find($report->registration_id)?->leader?->user;

        return $leader !== null && $leader->canLogIn();
    }

    /**
     * Who hears about a decision on this report (D-109, D-110), so a screen promises a notice only to the people who
     * get one: `told` = members whose account can log in (memberUsers()), `of` = the confirmed members still in the
     * team, `mentor` = the guru pembimbing is told (mentorUser()).
     *
     * @return array{told: int, of: int, mentor: bool}
     */
    public static function audience(Report $report): array
    {
        return [
            'told' => count(self::memberUsers($report)),
            'of' => RegistrationMember::query()
                ->where('registration_id', $report->registration_id)
                ->whereNotNull('confirmed_at')
                ->whereNull('declined_at')
                ->count(),
            'mentor' => self::mentorUser($report) !== null,
        ];
    }
}
