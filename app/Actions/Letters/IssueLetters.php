<?php

declare(strict_types=1);

namespace App\Actions\Letters;

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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Tata Usaha issues the surat tugas of one approved registration (D-06, SECURITY §3g/§3m-4/§3m-6):
 * both numbers typed by TU (unique per type + year, DB index is the final guard), one siswa letter for the
 * whole team + one Surat Perintah Tugas for the guru pembimbing (skipped when the registration has no
 * mentor), each rendered to PDF with the signature + stamp image from settings and a verification QR.
 * PDFs go to the private disk (files rows, SHA-256), letters are immutable afterwards (a correction is
 * RevokeLetter: cabut + new version). Each letter stores the signature file id and the printed schedule
 * (B3, §3m-5). The registration stays `disetujui` until SendLetters (TU reviews the PDFs first, step 3).
 *
 * Validation errors use the keys `siswa_sequence`, `guru_sequence`, `issued_on`, `basis_number`,
 * `basis_date`, `schedule_day`, `schedule_time`, `place`, `registration`, `signature`.
 */
final class IssueLetters
{
    public const MSG_TAKEN = 'Nomor ini sudah dipakai.';

    public const TEMPLATES = LetterPdf::TEMPLATES;

    public function __construct(
        private readonly LetterNumber $numbers,
        private readonly LetterPdf $pdf,
    ) {}

    /**
     * @return Collection<int, Letter> the new letters (siswa first)
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Registration $registration, LetterDraft $draft): Collection
    {
        Gate::forUser($actor)->authorize('viewQueue', Letter::class);

        $hasMentor = $registration->mentor_id !== null;
        self::validateDraft($draft, $hasMentor);

        $signature = $this->pdf->signature();
        $written = [];

        try {
            $letters = DB::transaction(function () use ($actor, $registration, $draft, $signature, &$written): Collection {
                /** @var Registration $locked */
                $locked = Registration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->status !== RegistrationStatus::Disetujui) {
                    throw ValidationException::withMessages(['registration' => 'Pendaftaran ini belum disetujui Kepala Sekolah atau suratnya sudah terbit.']);
                }

                if ($locked->letters()->exists()) {
                    throw ValidationException::withMessages(['registration' => 'Surat untuk pendaftaran ini sudah dibuat.']);
                }

                Gate::forUser($actor)->authorize('create', [Letter::class, $locked]);

                $content = LetterContent::build($locked, $draft);

                if ($content->students === []) {
                    throw ValidationException::withMessages(['registration' => 'Belum ada anggota yang terkonfirmasi.']);
                }

                $plan = [LetterType::Siswa->value => $draft->siswaSequence];

                if ($locked->mentor_id !== null) {
                    $plan[LetterType::Guru->value] = $draft->guruSequence;
                }

                $letters = collect();
                $now = now();

                foreach ($plan as $typeValue => $sequence) {
                    $type = LetterType::from($typeValue);
                    $number = $this->number($type, (int) $sequence, $draft->issuedOn);

                    if (self::isTaken($type, $draft->issuedOn->year, (int) $sequence)) {
                        throw ValidationException::withMessages([$typeValue.'_sequence' => self::MSG_TAKEN]);
                    }

                    $letter = new Letter([
                        'registration_id' => $locked->getKey(),
                        'type' => $type,
                        'sequence' => (int) $sequence,
                        'full_number' => $number,
                        'year' => $draft->issuedOn->year,
                        'month' => $draft->issuedOn->month,
                        'issued_on' => $draft->issuedOn->toDateString(),
                        'basis_number' => $content->basisNumber,
                        'basis_date' => $draft->basisDate?->toDateString(),
                        'schedule_day' => $content->scheduleDay,
                        'schedule_time' => $content->scheduleTime,
                        'place' => $content->place,
                    ]);
                    $letter->forceFill([
                        'version' => 1,
                        'signature_file_id' => $signature['file_id'],
                        'generated_by' => $actor->getKey(),
                        'generated_at' => $now,
                    ])->save();

                    $document = $this->pdf->render($type, $content, $letter, $signature['bytes']);
                    $file = $this->pdf->store($document, $type, $locked, $actor, $written);
                    $letter->forceFill(['file_id' => $file->getKey()])->save();

                    $letters->push($letter->setRelation('file', $file));
                }

                activity('letter')
                    ->performedOn($locked)
                    ->causedBy($actor)
                    ->event('letters.issued')
                    ->withProperties([
                        'letters' => $letters->map(fn (Letter $l): array => [
                            'id' => $l->getKey(),
                            'type' => $l->type->value,
                            'number' => $l->full_number,
                            'sha256' => $l->file?->sha256,
                        ])->all(),
                        'issued_on' => $draft->issuedOn->toDateString(),
                        'signature_file_id' => $signature['file_id'],
                        'signature_sha256' => $signature['sha256'],
                        'schedule' => [$content->scheduleDay, $content->scheduleTime, $content->place],
                    ])
                    ->log('letters.issued');

                return $letters;
            });
        } catch (UniqueConstraintViolationException) {
            $this->pdf->cleanup($written);
            $this->throwTaken($draft, $hasMentor);
        } catch (Throwable $e) {
            $this->pdf->cleanup($written);

            throw $e;
        }

        return $letters;
    }

    /** Is (type, year, sequence) already used? UI hint only; the DB unique index decides. */
    public static function isTaken(LetterType $type, int $year, int $sequence): bool
    {
        return Letter::query()->where('type', $type)->where('year', $year)->where('sequence', $sequence)->exists();
    }

    /** Full number from the settings format, e.g. 400.3.8/313/MRD/X/2026. */
    public function number(LetterType $type, int $sequence, CarbonImmutable $issuedOn): string
    {
        return $this->numbers->format((string) Setting::get($type->formatSetting()), $sequence, $issuedOn);
    }

    /**
     * Prefix and suffix around the sequence for the composed input ("400.3.8/" [313] "/MRD/X/2026").
     *
     * @return array{0: string, 1: string}
     */
    public function numberParts(LetterType $type, CarbonImmutable $issuedOn): array
    {
        $template = (string) Setting::get($type->formatSetting());
        $this->numbers->assertValidTemplate($template);
        $filled = strtr($template, [
            '{roman_month}' => $this->numbers->romanMonth($issuedOn->month),
            '{year}' => (string) $issuedOn->year,
        ]);
        [$prefix, $suffix] = explode('{seq}', $filled, 2);

        return [$prefix, $suffix];
    }

    /** @throws ValidationException */
    public static function validateDraft(LetterDraft $draft, bool $hasMentor): void
    {
        $errors = [];
        $numbers = new LetterNumber;

        if ($draft->siswaSequence === null || ! $numbers->isValidSequence($draft->siswaSequence)) {
            $errors['siswa_sequence'] = 'Isi nomor urut 1–99999.';
        }

        if ($hasMentor && ($draft->guruSequence === null || ! $numbers->isValidSequence($draft->guruSequence))) {
            $errors['guru_sequence'] = 'Isi nomor urut 1–99999.';
        }

        $errors += self::draftFieldErrors($draft);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Errors of the shared letter data (date, basis, schedule), without the sequences. Also used by
     * RevokeLetter for the new version.
     *
     * @return array<string, string>
     */
    public static function draftFieldErrors(LetterDraft $draft): array
    {
        $errors = [];
        $today = CarbonImmutable::today();

        if ($draft->issuedOn->lt($today->subYear()) || $draft->issuedOn->gt($today->addMonths(3))) {
            $errors['issued_on'] = 'Tanggal surat tidak wajar.';
        }

        if (mb_strlen((string) $draft->basisNumber) > 150) {
            $errors['basis_number'] = 'Maksimal 150 karakter.';
        }

        if ($draft->basisDate !== null && $draft->basisDate->gt($draft->issuedOn)) {
            $errors['basis_date'] = 'Tanggal surat undangan tidak boleh setelah tanggal surat.';
        }

        foreach (['schedule_day' => $draft->scheduleDay, 'schedule_time' => $draft->scheduleTime, 'place' => $draft->place] as $key => $value) {
            $length = mb_strlen(trim($value));

            if ($length === 0) {
                $errors[$key] = 'Wajib diisi.';
            } elseif ($length > 150) {
                $errors[$key] = 'Maksimal 150 karakter.';
            }
        }

        return $errors;
    }

    /** @throws ValidationException */
    private function throwTaken(LetterDraft $draft, bool $hasMentor): never
    {
        $errors = [];

        if (self::isTaken(LetterType::Siswa, $draft->issuedOn->year, (int) $draft->siswaSequence)) {
            $errors['siswa_sequence'] = self::MSG_TAKEN;
        }

        if ($hasMentor && self::isTaken(LetterType::Guru, $draft->issuedOn->year, (int) $draft->guruSequence)) {
            $errors['guru_sequence'] = self::MSG_TAKEN;
        }

        throw ValidationException::withMessages($errors !== [] ? $errors : ['registration' => 'Nomor sudah dipakai surat lain. Coba lagi.']);
    }
}
