<?php

declare(strict_types=1);

namespace App\Actions\Letters;

use App\Actions\Registrations\ApproveRegistration;
use App\Domain\Registrations\RegistrationStateMachine;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\Letters\LettersIssued;
use App\Policies\LetterPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Tata Usaha sends the issued letters (mockup step 3 "Kirim ke siswa dan guru"): marks every letter
 * sent, moves the registration disetujui → surat_terbit (ARCHITECTURE §6) and notifies the team members
 * (shared siswa letter) and the guru pembimbing (Surat Perintah Tugas), queued after commit.
 * M1-A: one action for both letters, allowed once every required letter has its PDF (the siswa letter, plus
 * the guru letter when the registration has a guru pembimbing); the error names what is missing.
 */
final class SendLetters
{
    public function __construct(private readonly RegistrationStateMachine $stateMachine) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException when there is nothing to send (no PDFs yet, or already sent)
     */
    public function handle(User $actor, Registration $registration): Registration
    {
        Gate::forUser($actor)->authorize('viewQueue', Letter::class);

        $locked = DB::transaction(function () use ($actor, $registration): Registration {
            /** @var Registration $locked */
            $locked = Registration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();
            // Current versions only: a letter revoked before sending is never sent (B3, RevokeLetter).
            $letters = Letter::query()->where('registration_id', $locked->getKey())->current()->lockForUpdate()->get();

            if ($locked->status !== RegistrationStatus::Disetujui || $letters->contains(fn (Letter $l): bool => $l->sent_at !== null)) {
                throw ValidationException::withMessages(['registration' => 'Surat pendaftaran ini sudah dikirim atau pendaftarannya belum disetujui.']);
            }

            // M1-A: letters are issued one at a time, so "Kirim" waits until every required one has its PDF.
            $missing = self::missing($locked, $letters);

            if ($missing !== []) {
                throw ValidationException::withMessages(['registration' => self::missingMessage($missing)]);
            }

            Gate::forUser($actor)->authorize('send', [Letter::class, $locked]);
            $this->stateMachine->assertTransition($locked->status, RegistrationStatus::SuratTerbit);

            $now = now();

            foreach ($letters as $letter) {
                $letter->forceFill(['sent_at' => $now])->save();
            }

            $locked->forceFill(['status' => RegistrationStatus::SuratTerbit])->save();

            activity('letter')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('letters.sent')
                ->withProperties([
                    'from' => RegistrationStatus::Disetujui->value,
                    'to' => RegistrationStatus::SuratTerbit->value,
                    'letters' => $letters->map(fn (Letter $l): string => $l->full_number)->all(),
                ])
                ->log('letters.sent');

            return $locked;
        });

        $registration->setRawAttributes($locked->getAttributes(), true);

        $members = ApproveRegistration::memberUsers($locked);

        if ($members->isNotEmpty()) {
            Notification::send($members, LettersIssued::for($locked, 'siswa'));
        }

        self::mentorToTell($locked)?->notify(LettersIssued::for($locked, 'guru'));

        return $registration;
    }

    /** Is any team member told that the letters were sent (confirmed members who can log in)? QA10, D-109: the toast asks. */
    public static function notifiesStudents(Registration $registration): bool
    {
        return ApproveRegistration::memberUsers($registration)->isNotEmpty();
    }

    /** Is the guru pembimbing told (QA10, D-109: the Surat tugas toast names only who was told)? */
    public static function notifiesMentor(Registration $registration): bool
    {
        return self::mentorToTell($registration) !== null;
    }

    /**
     * The guru pembimbing who gets the guru letter's notice: still a guru who can log in (the rule of the registration
     * notices, ApproveRegistration::mentorToTell(), QA10), and only when a guru letter exists.
     */
    public static function mentorToTell(Registration $registration): ?User
    {
        $mentor = ApproveRegistration::mentorToTell($registration);

        return $mentor !== null && $registration->letters()->where('type', LetterType::Guru)->exists() ? $mentor : null;
    }

    /**
     * Required letters (LetterPolicy::requiredTypes) that have no PDF yet.
     *
     * @param  EloquentCollection<int, Letter>  $letters  the current letters of the registration
     * @return list<LetterType>
     */
    public static function missing(Registration $registration, EloquentCollection $letters): array
    {
        return array_values(array_filter(
            LetterPolicy::requiredTypes($registration),
            fn (LetterType $type): bool => ! $letters->contains(fn (Letter $l): bool => $l->type === $type && $l->file_id !== null),
        ));
    }

    /** @param list<LetterType> $missing */
    public static function missingMessage(array $missing): string
    {
        $names = array_map(fn (LetterType $type): string => $type === LetterType::Guru ? 'surat guru pendamping' : 'surat siswa', $missing);

        return 'Belum bisa dikirim: '.implode(' dan ', $names).' belum dibuat. Buat PDF-nya dulu.';
    }
}
