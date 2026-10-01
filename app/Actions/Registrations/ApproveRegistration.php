<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Domain\Registrations\RegistrationStateMachine;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\Approval\RegistrationApproved;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Kepala Sekolah approves one registration (D-05, UI-MAP #19): menunggu_kepsek → disetujui. The team
 * roster is locked from here on. Notifies the members, the guru pembimbing and Tata Usaha (queued, after
 * commit). Bulk approve = one call per row, each authorized on its own.
 */
final class ApproveRegistration
{
    public const MAX_NOTE = 500;

    public function __construct(private readonly RegistrationStateMachine $stateMachine) {}

    /**
     * @throws AuthorizationException when the actor may not decide (not the Kepala Sekolah)
     * @throws ValidationException when the registration was already decided or cancelled
     */
    public function handle(User $actor, Registration $registration, ?string $note = null): Registration
    {
        Gate::forUser($actor)->authorize('viewApprovalQueue', Registration::class);

        $note = trim((string) $note);

        if (mb_strlen($note) > self::MAX_NOTE) {
            throw ValidationException::withMessages(['note' => 'Catatan maksimal 500 karakter.']);
        }

        $locked = DB::transaction(function () use ($actor, $registration, $note): Registration {
            /** @var Registration $locked */
            $locked = Registration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

            self::assertWaiting($locked);
            Gate::forUser($actor)->authorize('decide', $locked);
            $this->stateMachine->assertTransition($locked->status, RegistrationStatus::Disetujui);

            $locked->forceFill([
                'status' => RegistrationStatus::Disetujui,
                'principal_decision_by' => $actor->getKey(),
                'principal_decided_at' => now(),
                'principal_note' => $note === '' ? null : $note,
            ])->save();

            activity('registration')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('registration.approved')
                ->withProperties(['from' => RegistrationStatus::MenungguKepsek->value, 'to' => RegistrationStatus::Disetujui->value])
                ->log('registration.approved');

            return $locked;
        });

        $registration->setRawAttributes($locked->getAttributes(), true);

        $this->notify($locked);

        return $registration;
    }

    /** Shared guard for approve and reject: only rows still waiting for the Kepala Sekolah. */
    public static function assertWaiting(Registration $registration): void
    {
        if ($registration->status !== RegistrationStatus::MenungguKepsek) {
            throw ValidationException::withMessages([
                'registration' => $registration->status === RegistrationStatus::Dibatalkan
                    ? 'Pendaftaran ini sudah dibatalkan siswa.'
                    : 'Pendaftaran ini sudah diputuskan.',
            ]);
        }
    }

    /**
     * Team members who confirmed (and did not decline) and can still sign in.
     *
     * @return Collection<int, User>
     */
    public static function memberUsers(Registration $registration): Collection
    {
        return $registration->members()
            ->whereNotNull('confirmed_at')
            ->whereNull('declined_at')
            ->with('student.user')
            ->get()
            ->map(fn ($member) => $member->student?->user)
            ->filter(fn (?User $user): bool => $user !== null && $user->canLogIn())
            ->values();
    }

    private function notify(Registration $registration): void
    {
        $members = self::memberUsers($registration);

        if ($members->isNotEmpty()) {
            Notification::send($members, RegistrationApproved::for($registration, 'siswa'));
        }

        $mentor = $registration->mentor_id !== null ? User::query()->find($registration->mentor_id) : null;

        if ($mentor !== null && $mentor->canLogIn()) {
            $mentor->notify(RegistrationApproved::for($registration, 'guru'));
        }

        $tu = User::query()->role(RoleName::TataUsaha->value)->canLogIn()->get();

        if ($tu->isNotEmpty()) {
            Notification::send($tu, RegistrationApproved::for($registration, 'tu'));
        }
    }
}
