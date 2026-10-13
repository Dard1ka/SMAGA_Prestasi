<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Domain\Registrations\RegistrationStateMachine;
use App\Enums\CompetitionStatus;
use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Registrations\RegistrationAwaitingPrincipal;
use App\Notifications\Registrations\TeamInvitation;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * D-148: Kesiswaan decided a lomba, so its registrations that waited for it (`menunggu_lomba`, only the submitter can
 * make one) move in the same transaction as the decision (VerifyCompetition):
 * - published → individu `menunggu_kepsek` (the approvers are told), tim `menunggu_konfirmasi` (the anggota are invited
 *   now: `invited_at` = now). A "Pendaftaran ditutup" that passed before the publish does not cancel them: they were
 *   made before it. A team resumed after that deadline, or less than three days before it, gets three days from now
 *   to answer (`answer_until`, Registration::ANSWER_GRACE_DAYS; lead 2026-10-12).
 * - rejected → `dibatalkan` + cancelled_at. Nobody but the submitter hears of it, through the one rejection notice
 *   (VerifyCompetition): the anggota were never invited and the guru never told (the D-134 reasoning).
 * One audit row per registration (`registration.resumed` / `registration.cancelled`), causer = the verifier. A
 * registration the leader cancelled while it waited is not touched. Notices: notify(), after the commit.
 */
final class ResumeWaitingRegistrations
{
    public function __construct(private readonly RegistrationStateMachine $machine) {}

    /**
     * Call inside the caller's transaction, with the lomba row locked and already carrying the decision.
     *
     * @throws LogicException outside a transaction
     */
    public function handle(User $actor, Competition $locked, CompetitionStatus $decision): WaitingOutcome
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('ResumeWaitingRegistrations runs inside the transaction of the lomba decision.');
        }

        $waiting = Registration::query()
            ->where('competition_id', $locked->getKey())
            ->where('status', RegistrationStatus::MenungguLomba)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($waiting->isEmpty()) {
            return WaitingOutcome::none();
        }

        $submitterStudentId = $locked->submitted_by !== null
            ? Student::query()->where('user_id', $locked->submitted_by)->value('id')
            : null;
        $published = $decision === CompetitionStatus::Dipublikasikan;
        $now = CarbonImmutable::now();
        $invited = [];
        $toApprovers = [];
        $cancelled = 0;
        $submitterNow = null;

        foreach ($waiting as $registration) {
            $to = match (true) {
                ! $published => RegistrationStatus::Dibatalkan,
                $registration->isTeam() => RegistrationStatus::MenungguKonfirmasi,
                default => RegistrationStatus::MenungguKepsek,
            };
            $this->machine->assertTransition($registration->status, $to);

            if ($to === RegistrationStatus::Dibatalkan) {
                $registration->forceFill(['status' => $to, 'cancelled_at' => $now])->save();
                $cancelled++;
                $this->audit($actor, $registration, 'registration.cancelled', [
                    'from' => RegistrationStatus::MenungguLomba->value,
                    'reason' => 'competition_rejected',
                ]);
            } else {
                $registration->forceFill(['status' => $to])->save();
                $this->audit($actor, $registration, 'registration.resumed', [
                    'from' => RegistrationStatus::MenungguLomba->value,
                    'to' => $to->value,
                    'competition_id' => $locked->getKey(),
                ]);

                if ($to === RegistrationStatus::MenungguKonfirmasi) {
                    $this->extendAnswers($registration, $locked, $now);
                    $invited[] = ['registration' => $registration, 'users' => $this->invite($registration, $now)];
                } else {
                    $toApprovers[] = $registration;
                }
            }

            if ($submitterStudentId !== null && $registration->leader_student_id === $submitterStudentId) {
                $submitterNow = $to;
            }
        }

        return new WaitingOutcome($invited, $toApprovers, $cancelled, $submitterNow);
    }

    /**
     * "15 Okt 2026, 09.00": until when the anggota of a team resumed late may answer (the grace, D-148), for the screens
     * and the invitation; null for every other registration, whose answers close with "Pendaftaran ditutup".
     */
    public static function graceText(Registration $registration): ?string
    {
        if ($registration->answer_until === null) {
            return null;
        }

        return $registration->answersCloseAt()?->locale('id')->translatedFormat('j M Y, H.i');
    }

    /** Was this registration cancelled because Kesiswaan rejected its lomba (not by its leader)? Read from the audit. */
    public static function cancelledWithLomba(Registration $registration): bool
    {
        return $registration->status === RegistrationStatus::Dibatalkan
            && Activity::query()
                ->where('event', 'registration.cancelled')
                ->where('subject_type', $registration->getMorphClass())
                ->where('subject_id', $registration->getKey())
                ->where('properties->reason', 'competition_rejected')
                ->exists();
    }

    /** After the commit: the team invitations and the approvers' notice; never to the verifier. */
    public function notify(WaitingOutcome $outcome, ?User $actor = null): void
    {
        $notMe = fn (User $user): bool => $actor === null || ! $user->is($actor);

        foreach ($outcome->invited as ['registration' => $registration, 'users' => $users]) {
            Notification::send($users->filter($notMe)->values(), new TeamInvitation($registration));
        }

        if ($outcome->toApprovers === []) {
            return;
        }

        $principals = CreateRegistration::principals()->filter($notMe)->values();

        foreach ($outcome->toApprovers as $registration) {
            Notification::send($principals, new RegistrationAwaitingPrincipal($registration));
        }
    }

    /**
     * Stamp the invitation time on the anggota who have not answered, as CreateRegistration does for a published lomba.
     *
     * @return Collection<int, User> the accounts to send TeamInvitation to
     */
    private function invite(Registration $registration, DateTimeInterface $now): Collection
    {
        $open = $registration->members()
            ->where('role', MemberRole::Anggota)
            ->whereNull('confirmed_at')
            ->whereNull('declined_at')
            ->lockForUpdate()
            ->get();

        $open->each(fn (RegistrationMember $member) => $member->forceFill(['invited_at' => $now])->save());

        return User::query()->canLogIn()
            ->whereHas('student', fn ($q) => $q->whereKey($open->pluck('student_id')->all()))
            ->get();
    }

    /** Three days from now to answer when the lomba's deadline is closer than that or passed (null deadline: also). */
    private function extendAnswers(Registration $registration, Competition $locked, CarbonImmutable $now): void
    {
        $until = $now->addDays(Registration::ANSWER_GRACE_DAYS);
        $closes = $locked->registration_closes_at;

        if ($closes === null || $closes->lessThan($until)) {
            $registration->forceFill(['answer_until' => $until])->save();
        }
    }

    /** @param array<string, mixed> $properties */
    private function audit(User $actor, Registration $registration, string $event, array $properties): void
    {
        activity('registration')
            ->performedOn($registration)
            ->causedBy($actor)
            ->event($event)
            ->withProperties($properties)
            ->log($event);
    }
}
