<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Domain\Registrations\RegistrationStateMachine;
use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Registrations\MemberResponded;
use App\Notifications\Registrations\RegistrationAwaitingPrincipal;
use App\Policies\RegistrationPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * An invited anggota joins the team (UI-MAP "RespondToInvitation", yes). Only the invitee, only while the
 * registration is `menunggu_konfirmasi` and until the registration deadline (SECURITY §3m-3). QA10: the deadline,
 * not the lomba's published status (RegistrationPolicy::teamWindowOpen): a lomba Kesiswaan closed early keeps its
 * existing registrations running, as CloseCompetition says. When every remaining member has confirmed (and the team
 * still has ≥ 2 people) the registration moves to `menunggu_kepsek` and the Kepala Sekolah is notified.
 */
final class ConfirmMembership
{
    /** D-36: a team needs at least this many people who did not decline, the ketua included. */
    public const MIN_TEAM_SIZE = 2;

    public function __construct(private readonly RegistrationStateMachine $machine) {}

    /**
     * D-36: a team registration left with fewer than MIN_TEAM_SIZE people who did not decline (the ketua included; an
     * invitation the scheduler expired counts as declined, D-50). advanceIfTeamReady() never moves such a team on, so
     * the ketua has to invite a replacement while the team window is open, or cancel. The one rule for the screens (SV1:
     * Lomba saya said "Semua anggota sudah konfirmasi." for it). False for an individu registration. Always counted in
     * the database: a screen may have loaded `members` with a filter (Lomba saya's list loads only the viewer's row).
     */
    public static function teamIsShort(Registration $registration): bool
    {
        return self::teamIsShortWith($registration, $registration->members()->whereNull('declined_at')->count());
    }

    /**
     * teamIsShort() for a caller that already counted the people who did not decline ($people, the ketua included), so a
     * list of cards runs no COUNT query per card (Lomba saya's `active_members_count`, the Beranda's `team_size`, both
     * loaded with the filter `whereNull('declined_at')`). False for an individu registration.
     */
    public static function teamIsShortWith(Registration $registration, int $people): bool
    {
        return $registration->isTeam() && self::tooFew($people);
    }

    /**
     * D-36: would a team with these member rows go on to approval? Everyone who did not decline confirmed, and they are
     * not too few (MIN_TEAM_SIZE). The rule of advanceIfTeamReady(), which reads the rows locked there; a screen that
     * says what a step does next asks it with the rows the step would leave (Pendaftaran\Form "Batalkan undangan?").
     *
     * @param  Collection<int, RegistrationMember>  $members
     */
    public static function teamIsReady(Collection $members): bool
    {
        $remaining = $members->whereNull('declined_at');

        return ! self::tooFew($remaining->count()) && $remaining->whereNull('confirmed_at')->isEmpty();
    }

    /** Fewer people who did not decline than a team needs (D-36). */
    private static function tooFew(int $people): bool
    {
        return $people < self::MIN_TEAM_SIZE;
    }

    /** @throws ValidationException */
    public function handle(User $actor, Registration $registration): Registration
    {
        Gate::forUser($actor)->authorize('respond', $registration);

        /** @var Student $student */
        $student = $actor->loadMissing('student')->getRelation('student');

        [$locked, $ready] = DB::transaction(function () use ($actor, $registration, $student): array {
            [$locked, $member] = $this->lockPendingInvite($registration, $student);

            $elsewhere = RegistrationMember::query()
                ->where('student_id', $student->getKey())
                ->where('registration_id', '!=', $locked->getKey())
                ->whereNull('declined_at')
                ->whereHas('registration', fn (Builder $r) => $r
                    ->where('competition_id', $locked->competition_id)
                    ->whereIn('status', RegistrationStatus::nonFinal()))
                ->exists();

            if ($elsewhere) {
                throw ValidationException::withMessages(['registration' => 'Kamu sudah terdaftar di tim lain untuk lomba ini.']);
            }

            $member->forceFill(['confirmed_at' => now()])->save();
            $ready = $this->advanceIfTeamReady($locked);

            activity('registration')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('registration.member_confirmed')
                ->withProperties(['member_id' => $member->getKey(), 'status' => $locked->status->value])
                ->log('registration.member_confirmed');

            return [$locked, $ready];
        });

        $this->notifyAfterResponse($locked, (string) $actor->name, true, $ready);

        return $locked;
    }

    /**
     * Lock the registration + the actor's pending anggota row; re-check the policy conditions (also the deadline, for
     * a confirm and a decline alike).
     *
     * @return array{0: Registration, 1: RegistrationMember}
     *
     * @throws ValidationException
     */
    public function lockPendingInvite(Registration $registration, Student $student): array
    {
        /** @var Registration $locked */
        $locked = Registration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();
        $locked->setRelation('competition', Competition::withTrashed()->find($locked->competition_id));

        /** @var RegistrationMember|null $member */
        $member = $locked->members()->where('student_id', $student->getKey())->lockForUpdate()->first();

        if (
            $locked->status !== RegistrationStatus::MenungguKonfirmasi
            || $member === null
            || $member->role !== MemberRole::Anggota
            || $member->confirmed_at !== null
            || $member->declined_at !== null
        ) {
            throw ValidationException::withMessages(['registration' => 'Undangan ini sudah tidak berlaku.']);
        }

        if (! RegistrationPolicy::teamWindowOpen($locked)) {
            throw ValidationException::withMessages(['registration' => 'Batas pendaftaran sudah lewat.']);
        }

        return [$locked, $member];
    }

    /**
     * All remaining (not declined) members confirmed and at least 2 people → menunggu_kepsek (teamIsReady()).
     * Call inside the transaction with the registration locked: the rows are read here, never from a list loaded
     * before the change.
     */
    public function advanceIfTeamReady(Registration $locked): bool
    {
        if (! self::teamIsReady($locked->members()->get())) {
            return false;
        }

        $this->machine->assertTransition($locked->status, RegistrationStatus::MenungguKepsek);
        $locked->forceFill(['status' => RegistrationStatus::MenungguKepsek])->save();

        return true;
    }

    /**
     * Does the ketua hear about an answer (confirm or decline)? Not when the ketua's account cannot log in (QA10, D-109:
     * Undangan tim says "Ketua tim sudah diberi tahu" only then, like PublishPublication::notifiesStudent()).
     */
    public static function notifiesLeader(Registration $registration): bool
    {
        return self::leaderToTell($registration) !== null;
    }

    /** The ketua's account when an answer notice may go to it. */
    public static function leaderToTell(Registration $registration): ?User
    {
        $leader = $registration->loadMissing('leader.user')->leader?->user;

        return $leader !== null && $leader->canLogIn() ? $leader : null;
    }

    /** Leader hears about the answer; the Kepala Sekolah hears when the team is complete. */
    public function notifyAfterResponse(Registration $registration, string $memberName, bool $accepted, bool $ready): void
    {
        self::leaderToTell($registration)?->notify(new MemberResponded($registration, $memberName, $accepted));

        if ($ready) {
            Notification::send(CreateRegistration::principals(), new RegistrationAwaitingPrincipal($registration));
        }
    }
}
