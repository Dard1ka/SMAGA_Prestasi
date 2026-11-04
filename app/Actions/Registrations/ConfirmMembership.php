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
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * Trash (smaga_server, 5 Nov 2026): a member whose student is in the trash is no person of the team (onlyPeople()), and
 * a team whose ketua is in the trash never moves on (leaderIsTrashed()); the answer itself is still saved.
 */
final class ConfirmMembership
{
    /** D-36: a team needs at least this many people who did not decline, the ketua included. */
    public const MIN_TEAM_SIZE = 2;

    public function __construct(private readonly RegistrationStateMachine $machine) {}

    /**
     * D-36: a team registration left with fewer than MIN_TEAM_SIZE people who did not decline (the ketua included; an
     * invitation the scheduler expired counts as declined, D-50; a student in the trash is no person, onlyPeople()).
     * advanceIfTeamReady() never moves such a team on, so the ketua has to invite a replacement while the team window is
     * open, or cancel. The one rule for the screens (SV1: Lomba saya said "Semua anggota sudah konfirmasi." for it). False
     * for an individu registration. Always counted in the database: a screen may have loaded `members` with a filter
     * (Lomba saya's list loads only the viewer's row).
     */
    public static function teamIsShort(Registration $registration): bool
    {
        return self::teamIsShortWith($registration, self::onlyPeople($registration->members())->count());
    }

    /**
     * teamIsShort() for a caller that already counted the people of the team ($people, the ketua included), so a list
     * of cards runs no COUNT query per card (Lomba saya's `active_members_count`, the Beranda's `team_size`). Count them
     * with the filter of onlyPeople(), or a member in the trash still counts. False for an individu registration.
     */
    public static function teamIsShortWith(Registration $registration, int $people): bool
    {
        return $registration->isTeam() && self::tooFew($people);
    }

    /**
     * The member rows that are people of the team (D-36), as a query: not declined, and the student not in the trash
     * (Admin › Kelas › Sampah, smaga_server 5 Nov 2026: the `students` row soft-deleted, which the `student` relation
     * leaves out). For a COUNT of the people: `withCount(['members as n' => fn (Builder $m) => ConfirmMembership::onlyPeople($m)])`.
     *
     * @param  Builder<RegistrationMember>|HasMany<RegistrationMember, Registration>  $members
     * @return Builder<RegistrationMember>|HasMany<RegistrationMember, Registration> the same query, filtered
     */
    public static function onlyPeople(Builder|HasMany $members): Builder|HasMany
    {
        return $members->whereNull('declined_at')->whereHas('student');
    }

    /**
     * onlyPeople() for rows already loaded (one query for the students in the trash).
     *
     * @param  Collection<int, RegistrationMember>  $members
     * @return Collection<int, RegistrationMember>
     */
    public static function peopleAmong(Collection $members): Collection
    {
        $remaining = $members->whereNull('declined_at')->values();
        $ids = $remaining->pluck('student_id')->filter()->map(fn (mixed $id): string => (string) $id)->unique()->values()->all();

        if ($ids === []) {
            return $remaining;
        }

        $present = Student::query()->whereKey($ids)->pluck('id')->map(fn (mixed $id): string => (string) $id)->flip();

        return $remaining->filter(fn (RegistrationMember $member): bool => $present->has((string) $member->student_id))->values();
    }

    /**
     * D-36: would a team with these member rows go on to approval? Every person of the team (peopleAmong(): not
     * declined, not in the trash) confirmed, and they are not too few (MIN_TEAM_SIZE). An invitee in the trash who has
     * not answered does not hold the team back, and a member in the trash who confirmed does not make it big enough.
     * The rule of advanceIfTeamReady(), which reads the rows locked there; a screen that says what a step does next asks
     * it with the rows the step would leave (Pendaftaran\Form "Batalkan undangan?").
     *
     * @param  Collection<int, RegistrationMember>  $members
     */
    public static function teamIsReady(Collection $members): bool
    {
        $people = self::peopleAmong($members);

        return ! self::tooFew($people->count()) && $people->whereNull('confirmed_at')->isEmpty();
    }

    /**
     * The ketua of the registration is in the trash (its `students` row soft-deleted; smaga_server, 5 Nov 2026). Such a
     * registration never goes on to the approvers and nobody is told about it (advanceIfTeamReady(),
     * ExpirePendingInvitations, ResumeWaitingRegistrations), so "Pulihkan" finds it where it stood.
     */
    public static function leaderIsTrashed(Registration $registration): bool
    {
        return Student::onlyTrashed()->whereKey($registration->leader_student_id)->exists();
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
     * All remaining people of the team (not declined, not in the trash) confirmed and at least 2 of them →
     * menunggu_kepsek (teamIsReady()). Never while the ketua is in the trash (leaderIsTrashed()): the approvers would be
     * asked about a team nobody leads, and "Pulihkan" must find it as it was. Call inside the transaction with the
     * registration locked: the rows are read here, never from a list loaded before the change.
     */
    public function advanceIfTeamReady(Registration $locked): bool
    {
        if (self::leaderIsTrashed($locked) || ! self::teamIsReady($locked->members()->get())) {
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
