<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Domain\Registrations\RegistrationStateMachine;
use App\Enums\Permission;
use App\Enums\RegistrationStatus;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\Registrations\RegistrationCancelledBySchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * D-155 (lead, 2026-10-16: "buat tu bisa hapus antrean surat"): Tata Usaha removes an approved registration from the
 * letter queue with a required reason, before its letters were sent: disetujui → dibatalkan + cancelled_at. Letters
 * already made but not sent are revoked (QR answers "Dicabut", the PDF stays on record, the number is never reused).
 * Told after the commit, with the reason: the team members who confirmed and can log in, the guru pembimbing, and the
 * approver who approved it when that is somebody else. Audit `registration.cancelled` with `from` disetujui,
 * `reason` letter_queue, the note and the revoked letter ids; screens read the reason from there
 * (Registration::letterQueueCancellation()). Nothing brings it back: the students may register again while the
 * lomba's registration is open.
 */
final class CancelApprovedRegistration
{
    public const REASON = 'letter_queue';

    public const MIN_NOTE = 5;

    public const MAX_NOTE = 500;

    public const MSG_NOTE = 'Tulis alasan pembatalan, minimal 5 karakter.';

    public const MSG_GONE = 'Pendaftaran ini sudah tidak ada di antrean surat.';

    /** revoke_reason of the letters: shown to staff on Arsip surat; the note itself stays in the audit row. */
    public const LETTER_REASON = 'Pendaftaran dibatalkan dari antrean surat.';

    public function __construct(private readonly RegistrationStateMachine $machine) {}

    /** @throws ValidationException `note` (reason) or `registration` (no longer in the queue) */
    public function handle(User $actor, Registration $registration, string $note): Registration
    {
        $note = trim((string) preg_replace('/\s+/u', ' ', $note));

        if (mb_strlen($note) < self::MIN_NOTE) {
            throw ValidationException::withMessages(['note' => self::MSG_NOTE]);
        }

        if (mb_strlen($note) > self::MAX_NOTE) {
            throw ValidationException::withMessages(['note' => 'Alasan maksimal '.self::MAX_NOTE.' karakter.']);
        }

        $locked = DB::transaction(function () use ($actor, $registration, $note): Registration {
            /** @var Registration $locked */
            $locked = Registration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== RegistrationStatus::Disetujui) {
                throw ValidationException::withMessages(['registration' => self::MSG_GONE]);
            }

            Gate::forUser($actor)->authorize('cancelApproved', $locked);
            $this->machine->assertTransition($locked->status, RegistrationStatus::Dibatalkan);

            $now = now();
            $letters = Letter::query()->where('registration_id', $locked->getKey())->current()->lockForUpdate()->get();

            // A sent letter would have moved the registration on (surat_terbit); this one only guards the rule.
            if ($letters->contains(fn (Letter $letter): bool => $letter->sent_at !== null)) {
                throw ValidationException::withMessages(['registration' => self::MSG_GONE]);
            }

            foreach ($letters as $letter) {
                $letter->forceFill(['revoked_at' => $now, 'revoked_by' => $actor->getKey(), 'revoke_reason' => self::LETTER_REASON])->save();
            }

            $locked->forceFill(['status' => RegistrationStatus::Dibatalkan, 'cancelled_at' => $now])->save();

            activity('registration')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('registration.cancelled')
                ->withProperties([
                    'from' => RegistrationStatus::Disetujui->value,
                    'reason' => self::REASON,
                    'note' => $note,
                    'letters_revoked' => $letters->modelKeys(),
                ])
                ->log('registration.cancelled');

            return $locked;
        });

        $registration->setRawAttributes($locked->getAttributes(), true);

        foreach (self::audience($locked, $actor) as $audience => $users) {
            if ($users !== []) {
                Notification::send($users, RegistrationCancelledBySchool::for($locked, $audience, $actor, $note));
            }
        }

        return $registration;
    }

    /**
     * Who hears about it (also for the dialog, before the click): `siswa` = members who confirmed and can log in,
     * `guru` = the guru pembimbing while still a guru, `pemutus` = the approver who approved it, when that is somebody
     * else who can still approve. Never the actor.
     *
     * @return array{siswa: list<User>, guru: list<User>, pemutus: list<User>}
     */
    public static function audience(Registration $registration, User $actor): array
    {
        $mentor = ApproveRegistration::mentorToTell($registration);
        $approver = $registration->principal_decision_by !== null
            ? User::query()->canLogIn()->whereKey($registration->principal_decision_by)->first()
            : null;

        return [
            'siswa' => ApproveRegistration::memberUsers($registration)->reject(fn (User $user): bool => $user->is($actor))->values()->all(),
            'guru' => $mentor !== null && ! $mentor->is($actor) ? [$mentor] : [],
            'pemutus' => $approver !== null && ! $approver->is($actor) && $approver->can(Permission::RegistrationApprove->value) ? [$approver] : [],
        ];
    }
}
