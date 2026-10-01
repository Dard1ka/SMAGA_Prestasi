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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Tata Usaha sends the issued letters (mockup step 3 "Kirim ke siswa dan guru"): marks every letter
 * sent, moves the registration disetujui → surat_terbit (ARCHITECTURE §6) and notifies the team members
 * (shared siswa letter) and the guru pembimbing (Surat Perintah Tugas), queued after commit.
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
            $letters = Letter::query()->where('registration_id', $locked->getKey())->lockForUpdate()->get();

            if ($locked->status !== RegistrationStatus::Disetujui || $letters->isEmpty() || $letters->contains(fn (Letter $l): bool => $l->sent_at !== null)) {
                throw ValidationException::withMessages(['registration' => 'Surat ini sudah dikirim atau belum dibuat.']);
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

        $mentor = $locked->mentor_id !== null ? User::query()->find($locked->mentor_id) : null;

        if ($mentor !== null && $mentor->canLogIn() && $locked->letters()->where('type', LetterType::Guru)->exists()) {
            $mentor->notify(LettersIssued::for($locked, 'guru'));
        }

        return $registration;
    }
}
