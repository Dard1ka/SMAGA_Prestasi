<?php

declare(strict_types=1);

namespace App\Actions\Letters;

use App\Actions\Registrations\ApproveRegistration;
use App\Actions\Users\AdminRecipients;
use App\Enums\LetterType;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\Admin\LetterRevokedNotice;
use App\Notifications\Letters\LetterRevised;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Letter correction (B3, backlog #11, SECURITY §3g "koreksi = cabut + surat versi baru"): Tata Usaha
 * revokes one issued letter with a required reason and issues its next version in the same step.
 * - the old row keeps its number + PDF and gets revoked_at / revoked_by / revoke_reason; the public
 *   verification page then shows "Dicabut" (only for letters that were sent);
 * - the new row: same registration + type, version + 1, replaces_letter_id → old, a NEW sequence typed by
 *   TU (the unique (type, year, sequence) index means an old number is never reused), the letter data from
 *   the draft (date, basis, schedule), members/mentor/signer re-read from the database, the current
 *   signature image, a fresh verification code and PDF ("menggantikan surat nomor …" in the footer);
 * - if the old letter was already sent, the new one is sent at once and its recipients are notified
 *   (LetterRevised: members for the siswa letter, the mentor for the guru letter); otherwise it waits
 *   for SendLetters like a first issue. The registration status never changes.
 * Allowed at any registration stage, always audited (`letter.revoked`). Error keys: reason, sequence,
 * letter, signature + IssueLetters::draftFieldErrors() keys.
 */
final class RevokeLetter
{
    public const MIN_REASON = 5;

    public const MAX_REASON = 500;

    public function __construct(
        private readonly IssueLetters $issue,
        private readonly LetterPdf $pdf,
    ) {}

    /**
     * @param  int|null  $sequence  the new number's sequence for the replacement
     * @return Letter the new version
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Letter $letter, string $reason, ?int $sequence, LetterDraft $draft): Letter
    {
        Gate::forUser($actor)->authorize('revoke', $letter);

        $reason = trim($reason);
        $errors = IssueLetters::draftFieldErrors($draft);

        if (mb_strlen($reason) < self::MIN_REASON) {
            $errors['reason'] = 'Tulis alasan pencabutan.';
        } elseif (mb_strlen($reason) > self::MAX_REASON) {
            $errors['reason'] = 'Alasan maksimal 500 karakter.';
        }

        if ($sequence === null || $sequence < 1 || $sequence > 99999) {
            $errors['sequence'] = 'Isi nomor urut 1–99999.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $signature = $this->pdf->signature();
        $written = [];

        try {
            [$new, $old, $registration] = DB::transaction(function () use ($actor, $letter, $reason, $sequence, $draft, $signature, &$written): array {
                /** @var Registration $registration */
                $registration = Registration::withTrashed()->whereKey($letter->registration_id)->lockForUpdate()->firstOrFail();
                /** @var Letter $old */
                $old = Letter::query()->whereKey($letter->getKey())->lockForUpdate()->firstOrFail();

                if ($old->revoked_at !== null) {
                    throw ValidationException::withMessages(['letter' => 'Surat ini sudah dicabut.']);
                }

                Gate::forUser($actor)->authorize('revoke', $old);

                $type = $old->type;

                if ($type === LetterType::Guru && $registration->mentor_id === null) {
                    throw ValidationException::withMessages(['letter' => 'Pendaftaran ini tidak punya guru pembimbing.']);
                }

                $content = LetterContent::build($registration, $draft);

                if ($content->students === []) {
                    throw ValidationException::withMessages(['letter' => 'Belum ada anggota yang terkonfirmasi.']);
                }

                if (IssueLetters::isTaken($type, $draft->issuedOn->year, (int) $sequence)) {
                    throw ValidationException::withMessages(['sequence' => IssueLetters::MSG_TAKEN]);
                }

                $now = now();
                $old->forceFill(['revoked_at' => $now, 'revoked_by' => $actor->getKey(), 'revoke_reason' => $reason])->save();

                $version = (int) Letter::query()
                    ->where('registration_id', $registration->getKey())
                    ->where('type', $type)
                    ->max('version') + 1;

                $new = new Letter([
                    'registration_id' => $registration->getKey(),
                    'type' => $type,
                    'sequence' => (int) $sequence,
                    'full_number' => $this->issue->number($type, (int) $sequence, $draft->issuedOn),
                    'year' => $draft->issuedOn->year,
                    'month' => $draft->issuedOn->month,
                    'issued_on' => $draft->issuedOn->toDateString(),
                    'basis_number' => $content->basisNumber,
                    'basis_date' => $draft->basisDate?->toDateString(),
                    'schedule_day' => $content->scheduleDay,
                    'schedule_time' => $content->scheduleTime,
                    'place' => $content->place,
                ]);
                $new->forceFill([
                    'version' => $version,
                    'replaces_letter_id' => $old->getKey(),
                    'signature_file_id' => $signature['file_id'],
                    'generated_by' => $actor->getKey(),
                    'generated_at' => $now,
                    'sent_at' => $old->sent_at !== null ? $now : null,
                ])->save();

                $document = $this->pdf->render($type, $content, $new, $signature['bytes'], $old->full_number);
                $file = $this->pdf->store($document, $type, $registration, $actor, $written, $version);
                $new->forceFill(['file_id' => $file->getKey()])->save();

                activity('letter')
                    ->performedOn($registration)
                    ->causedBy($actor)
                    ->event('letter.revoked')
                    ->withProperties([
                        'reason' => $reason,
                        'revoked' => ['id' => $old->getKey(), 'type' => $type->value, 'number' => $old->full_number, 'version' => $old->version, 'was_sent' => $old->sent_at !== null],
                        'issued' => ['id' => $new->getKey(), 'number' => $new->full_number, 'version' => $version, 'sha256' => $file->sha256, 'sent' => $new->sent_at !== null],
                        'issued_on' => $draft->issuedOn->toDateString(),
                        'signature_file_id' => $signature['file_id'],
                        'signature_sha256' => $signature['sha256'],
                        'schedule' => [$content->scheduleDay, $content->scheduleTime, $content->place],
                    ])
                    ->log('letter.revoked');

                return [$new->setRelation('file', $file), $old, $registration];
            });
        } catch (UniqueConstraintViolationException) {
            $this->pdf->cleanup($written);

            throw ValidationException::withMessages(['sequence' => IssueLetters::MSG_TAKEN]);
        } catch (Throwable $e) {
            $this->pdf->cleanup($written);

            throw $e;
        }

        if ($new->sent_at !== null) {
            $this->notify($registration, $old, $new);
        }

        // B5 (lead decision 2026-10-01): every revocation is reported to the Kepala Sekolah (no second approval).
        $this->notifyPrincipal($actor, $registration, $old, $new, $reason);

        return $new;
    }

    // --- B5: Kepala Sekolah notice (letters are issued in their name; SECURITY §3m-5) ---
    private function notifyPrincipal(User $actor, Registration $registration, Letter $old, Letter $new, string $reason): void
    {
        $principals = app(AdminRecipients::class)->principals();

        if ($principals->isEmpty()) {
            return;
        }

        $registration->loadMissing(['competition' => fn ($q) => $q->withTrashed()]);

        Notification::send($principals, new LetterRevokedNotice(
            (string) $actor->name,
            $old->type === LetterType::Guru ? 'surat perintah tugas guru' : 'surat tugas siswa',
            (string) $old->full_number,
            (string) $new->full_number,
            (string) ($registration->competition?->name ?? ''),
            $reason,
        ));
    }
    // --- end B5 ---

    private function notify(Registration $registration, Letter $old, Letter $new): void
    {
        $notification = new LetterRevised(
            $registration,
            $new->type === LetterType::Guru ? 'guru' : 'siswa',
            (string) $old->full_number,
            (string) $new->full_number,
            $new->file_id !== null ? (string) $new->file_id : null,
        );

        if ($new->type === LetterType::Siswa) {
            $members = ApproveRegistration::memberUsers($registration);

            if ($members->isNotEmpty()) {
                Notification::send($members, $notification);
            }

            return;
        }

        $mentor = $registration->mentor_id !== null ? User::query()->find($registration->mentor_id) : null;

        if ($mentor !== null && $mentor->canLogIn()) {
            $mentor->notify($notification);
        }
    }
}
