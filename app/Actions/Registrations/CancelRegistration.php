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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The leader cancels while the registration waits for members (`menunggu_konfirmasi`) or for the
 * approval (`menunggu_kepsek`, Kepala Sekolah or Tata Usaha, D-150) → `dibatalkan` + cancelled_at (ARCHITECTURE §6,
 * lead 2026-10-01). Other (not declined) members and the guru pembimbing are notified, and every approver (Kepala
 * Sekolah and Tata Usaha, D-77, D-150) when the registration was already waiting for their decision (lead,
 * 2026-10-02). Everyone becomes free to register for the lomba again.
 * From `menunggu_lomba` (D-148) nobody is told: the anggota were never invited and the guru never heard of it (the
 * D-134 reasoning), so a notice would be the first they hear of a registration that no longer exists.
 * Who is told is audience(), which the "Batalkan pendaftaran?" dialogs read too (noticeLine(), D-109).
 */
final class CancelRegistration
{
    /**
     * The statuses the ketua may cancel from (RegistrationPolicy::cancel). Checked again under the lock: the state
     * machine also allows disetujui → dibatalkan, but only for Tata Usaha (D-155, CancelApprovedRegistration).
     */
    public const LEADER_STATUSES = [RegistrationStatus::MenungguLomba, RegistrationStatus::MenungguKonfirmasi, RegistrationStatus::MenungguKepsek];

    /** The second sentence of noticeLine() once the registration was forwarded. */
    public const REGISTER_AGAIN = 'Kamu bisa mendaftar lagi selama pendaftaran masih dibuka.';

    public function __construct(private readonly RegistrationStateMachine $machine) {}

    /**
     * Who hears of a cancel by $actor, the ketua: the one rule of handle() and of the "Batalkan pendaftaran?" dialogs on
     * Detail lomba › Pendaftaran lomba and on Lomba saya (noticeLine(); SV1: each dialog had its own copy or a fixed
     * text). Nobody from `menunggu_lomba` (D-148). Otherwise the other members who did not decline and can log in, the
     * guru pembimbing while still a guru (ApproveRegistration::mentorToTell(), QA10), and, only when the registration
     * already waited for the decision, every approver who can log in (Kepala Sekolah and Tata Usaha, D-77, D-150);
     * never $actor. $from = the status the cancel starts from (handle() passes the one it read under the lock), null =
     * the registration's status now.
     *
     * @return array{members: Collection<int, User>, mentor: ?User, approvers: Collection<int, User>}
     */
    public static function audience(Registration $registration, User $actor, ?RegistrationStatus $from = null): array
    {
        $from ??= $registration->status;

        if ($from === RegistrationStatus::MenungguLomba) {
            return ['members' => new Collection, 'mentor' => null, 'approvers' => new Collection];
        }

        $studentId = $actor->loadMissing('student')->getRelation('student')?->getKey();
        $mentor = ApproveRegistration::mentorToTell($registration);

        return [
            'members' => User::query()->canLogIn()
                ->whereKeyNot($actor->getKey())
                ->whereHas('student.memberships', fn (Builder $m) => $m
                    ->where('registration_id', $registration->getKey())
                    ->whereNull('declined_at')
                    ->when($studentId !== null, fn (Builder $q) => $q->where('student_id', '!=', $studentId)))
                ->get(),
            'mentor' => $mentor !== null && ! $mentor->is($actor) ? $mentor : null,
            // Only when they were asked to approve it: before menunggu_kepsek the approvers never heard of it.
            'approvers' => $from === RegistrationStatus::MenungguKepsek
                ? CreateRegistration::principals()->reject(fn (User $principal): bool => $principal->is($actor))->values()
                : new Collection,
        ];
    }

    /**
     * The line of the "Batalkan pendaftaran?" dialog for the ketua (both screens): who audience() tells, in one
     * sentence, then REGISTER_AGAIN. A dialog claims a notice only when one goes out (D-109), and a student never reads
     * another student's account status (as in "Batalkan undangan?", D-128): members nobody can tell are left out
     * without a reason, and with nobody to tell the line says so.
     */
    public static function noticeLine(Registration $registration, User $actor): string
    {
        if ($registration->status === RegistrationStatus::MenungguLomba) {
            return 'Pendaftaran ini belum diteruskan, jadi tidak ada yang diberi tahu.';
        }

        ['members' => $members, 'mentor' => $mentor, 'approvers' => $approvers] = self::audience($registration, $actor);

        $told = array_values(array_filter([
            $members->isNotEmpty() ? 'anggota tim' : null,
            $mentor !== null ? 'guru pembimbing' : null,
            $approvers->isNotEmpty() ? 'sekolah' : null,
        ]));

        if ($told === []) {
            return 'Tidak ada yang diberi tahu. '.self::REGISTER_AGAIN;
        }

        $last = array_pop($told);
        $list = $told === [] ? $last : implode(', ', $told).(count($told) > 1 ? ',' : '').' dan '.$last;

        return ucfirst($list).' akan diberi tahu. '.self::REGISTER_AGAIN;
    }

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

        // From menunggu_lomba audience() is empty (D-148); the dialogs promise exactly these notices (noticeLine()).
        ['members' => $members, 'mentor' => $mentor, 'approvers' => $approvers] = self::audience($locked, $actor, $from);

        if ($members->isNotEmpty()) {
            Notification::send($members, RegistrationCancelled::for($locked, 'siswa'));
        }

        $mentor?->notify(RegistrationCancelled::for($locked, 'guru'));

        if ($approvers->isNotEmpty()) {
            Notification::send($approvers, RegistrationCancelled::for($locked, 'kepsek'));
        }

        return $locked;
    }
}
