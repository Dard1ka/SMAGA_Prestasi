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
 * Other (not declined) members and the guru pembimbing are notified, and the Kepala Sekolah when the
 * registration was already waiting for their decision (lead, 2026-10-02). Everyone becomes free to register
 * for the lomba again.
 * From `menunggu_lomba` (D-148) nobody is told: the anggota were never invited and the guru never heard of it (the
 * D-134 reasoning), so a notice would be the first they hear of a registration that no longer exists.
 */
final class CancelRegistration
{
    /**
     * The statuses the ketua may cancel from (RegistrationPolicy::cancel). Checked again under the lock: the state
     * machine also allows disetujui → dibatalkan, but only for Tata Usaha (D-155, CancelApprovedRegistration).
     */
    public const LEADER_STATUSES = [RegistrationStatus::MenungguLomba, RegistrationStatus::MenungguKonfirmasi, RegistrationStatus::MenungguKepsek];

    public function __construct(private readonly RegistrationStateMachine $machine) {}

    /** @throws ValidationException */
    public function handle(User $actor, Registration $registration): Registration
    {
        Gate::forUser($actor)->authorize('cancel', $registration);

        /** @var Student $student */
        $student = $actor->loadMissing('student')->getRelation('student');

        [$locked, $from] = DB::transaction(function () use ($actor, $registration, $student): array {
            /** @var Registration $locked */
            $locked = Registration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->leader_student_id !== $student->getKey()) {
                throw ValidationException::withMessages(['registration' => 'Hanya ketua yang bisa membatalkan.']);
            }

            $from = $locked->status;

            try {
                if (! in_array($from, self::LEADER_STATUSES, true)) {
                    throw new InvalidTransition($from->value, RegistrationStatus::Dibatalkan->value);
                }

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

            return [$locked, $from];
        });

        if ($from === RegistrationStatus::MenungguLomba) {
            return $locked;
        }

        $others = User::query()->canLogIn()
            ->whereHas('student.memberships', fn ($m) => $m
                ->where('registration_id', $locked->getKey())
                ->whereNull('declined_at')
                ->where('student_id', '!=', $student->getKey()))
            ->get();

        Notification::send($others, RegistrationCancelled::for($locked, 'siswa'));

        // QA10: only while the mentor still holds the guru role (ApproveRegistration::mentorToTell(), D-110 rule).
        $mentor = ApproveRegistration::mentorToTell($locked);

        if ($mentor !== null && ! $mentor->is($actor)) {
            $mentor->notify(RegistrationCancelled::for($locked, 'guru'));
        }

        // Only when they were asked to approve it: before menunggu_kepsek the Kepala Sekolah never heard of it.
        if ($from === RegistrationStatus::MenungguKepsek) {
            Notification::send(
                CreateRegistration::principals()->reject(fn (User $principal): bool => $principal->is($actor))->values(),
                RegistrationCancelled::for($locked, 'kepsek'),
            );
        }

        return $locked;
    }
}
