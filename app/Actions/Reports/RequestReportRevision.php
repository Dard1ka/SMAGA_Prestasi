<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Domain\Registrations\RegistrationStateMachine;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Report;
use App\Models\User;
use App\Notifications\Verification\BinaanReportDecided;
use App\Notifications\Verification\ReportRevisionRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Kesiswaan asks for a corrected report ("Minta revisi", note required): report → perlu_revisi,
 * registration laporan_dikirim → menunggu_laporan, so the leader can send it again (SubmitReport re-uses
 * the row). The members get the note (queued), and so does the guru pembimbing (D-110).
 */
final class RequestReportRevision
{
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
                ->event('report.revision_requested')
                ->withProperties(['registration_id' => $registration->getKey(), 'note' => $note])
                ->log('report.revision_requested');

            return $locked;
        });

        $report->setRawAttributes($locked->getAttributes(), true);

        $competitionName = self::competitionName($locked);

        foreach (self::memberUsers($locked) as $user) {
            $user->notify(ReportRevisionRequested::for($locked, $competitionName, $note));
        }

        self::mentorUser($locked)?->notify(BinaanReportDecided::revision($locked, $competitionName, $note));

        return $locked;
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
}
