<?php

declare(strict_types=1);

namespace App\Actions\Letters;

use App\Domain\Letters\LetterNumber;
use App\Domain\Letters\PdfDocument;
use App\Domain\Letters\PdfQrCode;
use App\Domain\Letters\PdfRenderer;
use App\Enums\FilePurpose;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\SettingKey;
use App\Models\File;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Tata Usaha issues the surat tugas of one approved registration (D-06, SECURITY §3g/§3m-4/§3m-6):
 * both numbers typed by TU (unique per type + year, DB index is the final guard), one siswa letter for the
 * whole team + one Surat Perintah Tugas for the guru pembimbing (skipped when the registration has no
 * mentor), each rendered to PDF with the signature + stamp image from settings and a verification QR.
 * PDFs go to the private disk (files rows, SHA-256), letters are immutable afterwards. The registration
 * stays `disetujui` until SendLetters (TU reviews the PDFs first, mockup step 3).
 *
 * Validation errors use the keys `siswa_sequence`, `guru_sequence`, `issued_on`, `basis_number`,
 * `basis_date`, `schedule_day`, `schedule_time`, `place`, `registration`, `signature`.
 */
final class IssueLetters
{
    public const MSG_TAKEN = 'Nomor ini sudah dipakai.';

    public const TEMPLATES = [
        'siswa' => 'surat-tugas-siswa',
        'guru' => 'surat-perintah-tugas-guru',
    ];

    public function __construct(
        private readonly LetterNumber $numbers,
        private readonly PdfRenderer $renderer,
        private readonly PdfQrCode $qr,
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

        $signature = $this->signatureImage();
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
                    ]);
                    $letter->forceFill(['generated_by' => $actor->getKey(), 'generated_at' => $now])->save();

                    $document = $this->render($type, $content, $letter, $signature['bytes']);
                    $file = $this->store($document, $type, $locked, $actor, $written);
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
            $this->cleanup($written);
            $this->throwTaken($draft, $hasMentor);
        } catch (Throwable $e) {
            $this->cleanup($written);

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

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The one signature + stamp image (settings.signature_file_id, private disk). TU never downloads it;
     * it only travels into the render sandbox (SECURITY §3m-5).
     *
     * @return array{bytes: string, file_id: string, sha256: string}
     */
    private function signatureImage(): array
    {
        $fileId = Setting::get(SettingKey::SignatureFileId);
        $file = is_string($fileId) && $fileId !== '' ? File::query()->find($fileId) : null;
        $bytes = $file !== null && $file->purpose === FilePurpose::Signature ? $file->storage()->get($file->path) : null;

        if (! is_string($bytes) || $bytes === '') {
            throw ValidationException::withMessages(['signature' => 'Tanda tangan dan stempel belum diunggah Admin.']);
        }

        return ['bytes' => $bytes, 'file_id' => (string) $file->getKey(), 'sha256' => hash('sha256', $bytes)];
    }

    private function render(LetterType $type, LetterContent $content, Letter $letter, string $signature): PdfDocument
    {
        $url = route('public.surat.verifikasi', ['code' => $letter->verification_code]);

        try {
            return $this->renderer->render(self::TEMPLATES[$type->value], [
                'content' => $content,
                'number' => $letter->full_number,
                'verification' => ['code' => $letter->verification_code, 'url' => $url],
            ], [
                'ttd.png' => $signature,
                'qr.png' => $this->qr->png($url, 6),
            ]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['signature' => 'Gambar tanda tangan tidak bisa dipakai. Minta Admin mengunggah ulang.']);
        }
    }

    /** @param list<array{disk: string, path: string}> $written */
    private function store(PdfDocument $document, LetterType $type, Registration $registration, User $actor, array &$written): File
    {
        $path = sprintf('surat/%s/%s.pdf', now()->format('Y'), Str::lower((string) Str::ulid()));
        Storage::disk('local')->put($path, $document->bytes);
        $written[] = ['disk' => 'local', 'path' => $path];

        $slug = Str::limit(Str::slug((string) $registration->competition?->name), 60, '');
        $prefix = $type === LetterType::Siswa ? 'surat-tugas-siswa-' : 'surat-perintah-tugas-guru-';

        $file = new File;
        $file->forceFill([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $prefix.($slug !== '' ? $slug : 'lomba').'.pdf',
            'mime' => 'application/pdf',
            'size' => $document->size(),
            'sha256' => $document->sha256(),
            'purpose' => FilePurpose::Letter,
            'owner_user_id' => $actor->getKey(),
        ])->save();

        return $file;
    }

    /** @param list<array{disk: string, path: string}> $written */
    private function cleanup(array $written): void
    {
        foreach ($written as $item) {
            rescue(fn () => Storage::disk($item['disk'])->delete($item['path']), null, false);
        }
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
