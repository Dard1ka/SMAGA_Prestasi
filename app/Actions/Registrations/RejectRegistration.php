<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Domain\Registrations\RegistrationStateMachine;
use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\Approval\RegistrationRejected;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Kepala Sekolah rejects one registration with a required reason (D-05, UI-MAP #19):
 * menunggu_kepsek → ditolak (final). The reason is stored as principal_note and sent to the team members
 * (queued, after commit).
 */
final class RejectRegistration
{
    public const MIN_NOTE = 5;

    public const MAX_NOTE = 500;

    public function __construct(private readonly RegistrationStateMachine $stateMachine) {}

    /**
     * @throws AuthorizationException when the actor may not decide (not the Kepala Sekolah)
     * @throws ValidationException when the reason is missing or the registration was already decided
     */
    public function handle(User $actor, Registration $registration, string $note): Registration
    {
        Gate::forUser($actor)->authorize('viewApprovalQueue', Registration::class);

        $note = trim($note);

        if (mb_strlen($note) < self::MIN_NOTE) {
            throw ValidationException::withMessages(['note' => 'Tulis alasan penolakan dulu.']);
        }

        if (mb_strlen($note) > self::MAX_NOTE) {
            throw ValidationException::withMessages(['note' => 'Alasan maksimal 500 karakter.']);
        }

        $locked = DB::transaction(function () use ($actor, $registration, $note): Registration {
            /** @var Registration $locked */
            $locked = Registration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

            ApproveRegistration::assertWaiting($locked);
            Gate::forUser($actor)->authorize('decide', $locked);
            $this->stateMachine->assertTransition($locked->status, RegistrationStatus::Ditolak);

            $locked->forceFill([
                'status' => RegistrationStatus::Ditolak,
                'principal_decision_by' => $actor->getKey(),
                'principal_decided_at' => now(),
                'principal_note' => $note,
            ])->save();

            activity('registration')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('registration.rejected')
                ->withProperties([
                    'from' => RegistrationStatus::MenungguKepsek->value,
                    'to' => RegistrationStatus::Ditolak->value,
                    'note' => $note,
                ])
                ->log('registration.rejected');

            return $locked;
        });

        $registration->setRawAttributes($locked->getAttributes(), true);

        $members = ApproveRegistration::memberUsers($locked);

        if ($members->isNotEmpty()) {
            Notification::send($members, new RegistrationRejected($locked));
        }

        return $registration;
    }
}
