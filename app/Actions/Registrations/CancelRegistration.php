<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Domain\Registrations\InvalidTransition;
use App\Domain\Registrations\RegistrationStateMachine;
use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Registrations\RegistrationCancelled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The leader cancels while the registration waits for members (`menunggu_konfirmasi`) or for the
 * Kepala Sekolah (`menunggu_kepsek`) → `dibatalkan` + cancelled_at (ARCHITECTURE §6, lead 2026-10-01).
 * Other (not declined) members are notified. Everyone becomes free to register for the lomba again.
 */
final class CancelRegistration
{
    public function __construct(private readonly RegistrationStateMachine $machine) {}

    /** @throws ValidationException */
    public function handle(User $actor, Registration $registration): Registration
    {
        Gate::forUser($actor)->authorize('cancel', $registration);

        /** @var Student $student */
        $student = $actor->loadMissing('student')->getRelation('student');

        $locked = DB::transaction(function () use ($actor, $registration, $student): Registration {
            /** @var Registration $locked */
            $locked = Registration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->leader_student_id !== $student->getKey()) {
                throw ValidationException::withMessages(['registration' => 'Hanya ketua yang bisa membatalkan.']);
            }

            $from = $locked->status;

            try {
                $this->machine->assertTransition($from, RegistrationStatus::Dibatalkan);
            } catch (InvalidTransition) {
                throw ValidationException::withMessages(['registration' => 'Pendaftaran ini sudah tidak bisa dibatalkan.']);
            }

            $locked->forceFill(['status' => RegistrationStatus::Dibatalkan, 'cancelled_at' => now()])->save();

            activity('registration')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('registration.cancelled')
                ->withProperties(['from' => $from->value])
                ->log('registration.cancelled');

            return $locked;
        });

        $others = User::query()->canLogIn()
            ->whereHas('student.memberships', fn ($m) => $m
                ->where('registration_id', $locked->getKey())
                ->whereNull('declined_at')
                ->where('student_id', '!=', $student->getKey()))
            ->get();

        Notification::send($others, new RegistrationCancelled($locked));

        return $locked;
    }
}
