<?php

declare(strict_types=1);

namespace App\Actions\Letters;

use App\Actions\Templates\LetterTemplate;
use App\Domain\Letters\LetterNumber;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Tata Usaha issues ONE surat tugas of an approved registration (D-06, SECURITY §3g/§3m-4/§3m-6; M1-A, lead
 * 2026-10-04: one letter at a time, each with its own number and data). `siswa` = one letter for the whole
 * team, `guru` = the Surat Perintah Tugas of the guru pembimbing (only when the registration has one).
 * The complete number is typed by TU and never used twice for the same letter type (the unique index
 * (type, full_number) is the final guard). The letter is rendered to PDF with the signature + stamp image
 * from settings and a verification QR, stored on the private disk (files row, SHA-256) and immutable
 * afterwards (a correction is RevokeLetter: cabut + new version). The registration stays `disetujui` until
 * SendLetters, which needs every required letter.
 *
 * Validation errors use the keys `number`, `issued_on`, `basis_from`, `basis_number`, `basis_date`,
 * `basis_subject`, `schedule_day`, `schedule_date`, `schedule_time`, `place`, `registration`, `signature`.
 * Every problem is reported at once (fields + missing signature), so the screen can list them.
 */
final class IssueLetters
{
    public const MSG_TAKEN = 'Nomor ini sudah dipakai surat lain. Ketik nomor yang belum dipakai.';

    public const TEMPLATES = LetterPdf::TEMPLATES;

    public const MAX_TEXT = 150;

    public const MAX_BASIS_FROM = 200;

    public function __construct(
        private readonly LetterNumber $numbers,
        private readonly LetterPdf $pdf,
    ) {}

    /**
     * @return Letter the new letter (with its `file` relation)
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Registration $registration, LetterType $type, LetterDraft $draft): Letter
    {
        Gate::forUser($actor)->authorize('viewQueue', Letter::class);

        $signature = $this->checked(self::draftErrors($draft, $type));
        $number = $this->numbers->normalize($draft->number);
        $written = [];

        try {
            $letter = DB::transaction(function () use ($actor, $registration, $type, $draft, $number, $signature, &$written): Letter {
                /** @var Registration $locked */
                $locked = Registration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->status !== RegistrationStatus::Disetujui) {
                    throw ValidationException::withMessages(['registration' => 'Pendaftaran ini belum disetujui atau suratnya sudah dikirim.']);
                }

                if ($type === LetterType::Guru && $locked->mentor_id === null) {
                    throw ValidationException::withMessages(['registration' => 'Pendaftaran ini tidak punya guru pembimbing, jadi tidak ada surat guru.']);
                }

                if ($locked->letters()->where('type', $type)->exists()) {
                    throw ValidationException::withMessages(['registration' => 'Surat ini sudah dibuat. Pakai Koreksi untuk menggantinya.']);
                }

                Gate::forUser($actor)->authorize('create', [Letter::class, $locked, $type]);

                $content = LetterContent::build($locked, $draft);

                if ($content->students === []) {
                    throw ValidationException::withMessages(['registration' => 'Belum ada anggota yang terkonfirmasi.']);
                }

                if (self::isTaken($type, $number)) {
                    throw ValidationException::withMessages(['number' => self::MSG_TAKEN]);
                }

                $version = (int) $locked->letterHistory()->where('type', $type)->max('version') + 1;

                $template = LetterTemplate::current();
                $letter = $this->newLetter($locked, $type, $number, $draft, $content);
                $letter->forceFill([
                    'version' => $version,
                    'signature_file_id' => $signature['file_id'],
                    'generated_by' => $actor->getKey(),
                    'generated_at' => now(),
                    'printed' => LetterPdf::printed($content, $template),
                ])->save();

                $document = $this->pdf->render($type, $content, $letter, $signature['bytes'], $template);
                $file = $this->pdf->store($document, $type, $locked, $actor, $written, $version);
                $letter->forceFill(['file_id' => $file->getKey()])->save();

                activity('letter')
                    ->performedOn($locked)
                    ->causedBy($actor)
                    ->event('letters.issued')
                    ->withProperties([
                        'letters' => [[
                            'id' => $letter->getKey(),
                            'type' => $type->value,
                            'number' => $letter->full_number,
                            'sha256' => $file->sha256,
                        ]],
                        'issued_on' => $draft->issuedOn->toDateString(),
                        'signature_file_id' => $signature['file_id'],
                        'signature_sha256' => $signature['sha256'],
                        'basis' => [$content->basisFrom, $content->basisNumber, $draft->basisDate?->toDateString()],
                        'schedule' => [$content->scheduleDay, $content->scheduleDate, $content->scheduleTime, $content->place],
                    ])
                    ->log('letters.issued');

                return $letter->refresh()->setRelation('file', $file);
            });
        } catch (UniqueConstraintViolationException) {
            $this->pdf->cleanup($written);

            throw ValidationException::withMessages(['number' => self::MSG_TAKEN]);
        } catch (Throwable $e) {
            $this->pdf->cleanup($written);

            throw $e;
        }

        return $letter;
    }

    /**
     * Stop with every problem at once (field errors + a missing signature image), or hand back the signature.
     * Shared with RevokeLetter.
     *
     * @param  array<string, string>  $errors
     * @return array{bytes: string, file_id: string, sha256: string}
     *
     * @throws ValidationException
     */
    public function checked(array $errors): array
    {
        $signature = null;

        try {
            $signature = $this->pdf->signature();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $errors[$key] = (string) $messages[0];
            }
        }

        if ($errors !== [] || $signature === null) {
            throw ValidationException::withMessages($errors);
        }

        return $signature;
    }

    /**
     * The unsaved letter row of a draft (mass-assignable columns only). Shared with RevokeLetter. Every data
     * column is set, so the model can be read right after save() (strict models).
     */
    public function newLetter(Registration $registration, LetterType $type, string $number, LetterDraft $draft, LetterContent $content): Letter
    {
        // D-154: a student letter prints no invitation, so its row keeps none.
        $invitation = LetterDraft::usesInvitation($type);

        return new Letter([
            'registration_id' => $registration->getKey(),
            'type' => $type,
            'sequence' => null,
            'full_number' => $number,
            'year' => $draft->issuedOn->year,
            'month' => $draft->issuedOn->month,
            'issued_on' => $draft->issuedOn->toDateString(),
            'basis_from' => $invitation ? $content->basisFrom : null,
            'basis_number' => $invitation ? $content->basisNumber : null,
            'basis_date' => $invitation ? $draft->basisDate?->toDateString() : null,
            'basis_subject' => LetterDraft::usesSubject($type) ? $content->basisSubject : null,
            'schedule_day' => $content->scheduleDay,
            'schedule_date' => $content->scheduleDate,
            'schedule_time' => $content->scheduleTime,
            'place' => $content->place,
        ]);
    }

    /** Is this number already used by a letter of the type (revoked versions too)? UI hint; the DB index decides. */
    public static function isTaken(LetterType $type, string $number): bool
    {
        $key = (new LetterNumber)->key($number);

        return $key !== '' && Letter::numberTaken($type, $key);
    }

    /**
     * The example shown under the number field: the Admin format of the type (now only an example) for the
     * letter date, with the sequence after the last used number when that one fits the format.
     */
    public function example(LetterType $type, CarbonImmutable $issuedOn, ?string $lastNumber = null): ?string
    {
        return $this->numbers->example(
            (string) Setting::get($type->formatSetting()),
            $issuedOn,
            $lastNumber,
            $type === LetterType::Guru ? 1681 : 313,
        );
    }

    /**
     * Errors of one letter's draft (number, date, basis, schedule). Also used by RevokeLetter for the new
     * version. Messages say what to do, because the screen lists them.
     *
     * @return array<string, string>
     */
    public static function draftErrors(LetterDraft $draft, LetterType $type): array
    {
        $errors = [];
        $today = CarbonImmutable::today();

        if (($error = (new LetterNumber)->numberError($draft->number)) !== null) {
            $errors['number'] = $error;
        }

        if ($draft->issuedOn->lt($today->subYear()) || $draft->issuedOn->gt($today->addMonths(3))) {
            $errors['issued_on'] = 'Tanggal surat paling lama setahun lalu dan paling jauh 3 bulan ke depan.';
        }

        $required = [
            'schedule_day' => [$draft->scheduleDay, self::MAX_TEXT, 'Isi hari pelaksanaan, misalnya Sabtu.'],
            'schedule_date' => [$draft->scheduleDate, self::MAX_TEXT, 'Isi tanggal pelaksanaan, misalnya 7 November 2026.'],
            'schedule_time' => [$draft->scheduleTime, self::MAX_TEXT, 'Isi pukul pelaksanaan, misalnya 07.00 WIB s.d Selesai.'],
            'place' => [$draft->place, self::MAX_TEXT, 'Isi tempat pelaksanaan.'],
        ];

        // D-154: only the guru letter prints the invitation; the student letter's Dasar comes from the template.
        if (LetterDraft::usesInvitation($type)) {
            $required = ['basis_from' => [$draft->basisFrom, self::MAX_BASIS_FROM, 'Isi asal surat undangan, misalnya nama penyelenggara.']] + $required;
        }

        if (LetterDraft::usesSubject($type)) {
            $required['basis_subject'] = [$draft->basisSubject, self::MAX_TEXT, 'Isi hal surat undangan, misalnya Undangan.'];
        }

        foreach ($required as $key => [$value, $max, $message]) {
            $length = mb_strlen(trim($value));

            if ($length === 0) {
                $errors[$key] = $message;
            } elseif ($length > $max) {
                $errors[$key] = 'Maksimal '.$max.' karakter.';
            }
        }

        if (LetterDraft::usesInvitation($type) && mb_strlen(trim((string) $draft->basisNumber)) > self::MAX_TEXT) {
            $errors['basis_number'] = 'Maksimal '.self::MAX_TEXT.' karakter.';
        }

        if (LetterDraft::usesInvitation($type) && $draft->basisDate !== null && $draft->basisDate->gt($draft->issuedOn)) {
            $errors['basis_date'] = 'Tanggal surat undangan tidak boleh setelah tanggal surat.';
        }

        return $errors;
    }
}
