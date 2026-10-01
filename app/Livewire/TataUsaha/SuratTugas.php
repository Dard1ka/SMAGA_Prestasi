<?php

declare(strict_types=1);

namespace App\Livewire\TataUsaha;

use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterContent;
use App\Actions\Letters\LetterDraft;
use App\Actions\Letters\SendLetters;
use App\Domain\Support\Dates;
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
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tata Usaha · Surat tugas (mockup TU-Surat-Tugas, UI-MAP #21): queue of approved registrations
 * (`tu.beranda`, first one selected) or one of them (`tu.surat.show`). Step 1 both numbers + letter data
 * (schedule prefilled from the lomba), step 2 live HTML preview of both letters, step 3 IssueLetters
 * (PDFs) then SendLetters. Every action re-authorizes; the selected ULID is #[Locked].
 */
#[Title('Surat tugas')]
class SuratTugas extends Component
{
    /** Form field → IssueLetters error key. */
    private const ERROR_MAP = [
        'siswa_sequence' => 'nomorSiswa',
        'guru_sequence' => 'nomorGuru',
        'issued_on' => 'tanggalSurat',
        'basis_number' => 'dasarNomor',
        'basis_date' => 'dasarTanggal',
        'schedule_day' => 'hariTanggal',
        'schedule_time' => 'pukul',
        'place' => 'tempat',
    ];

    #[Locked]
    public ?string $registrationId = null;

    public string $nomorSiswa = '';

    public string $nomorGuru = '';

    public string $tanggalSurat = '';

    public string $dasarNomor = '';

    public string $dasarTanggal = '';

    public string $hariTanggal = '';

    public string $pukul = '';

    public string $tempat = '';

    public string $tab = 'siswa';

    public function mount(?Registration $registration = null): void
    {
        Gate::authorize('viewQueue', Letter::class);

        if ($registration !== null && $registration->exists) {
            // Only approved registrations or ones that already have letters (done state); else 404.
            abort_unless($registration->status === RegistrationStatus::Disetujui || $registration->letters()->exists(), 404);
            $target = $registration;
        } else {
            $target = $this->queueQuery()->first();
        }

        if ($target !== null) {
            $this->registrationId = $target->getKey();
            $target->loadMissing('competition');
            $this->fill(LetterDraft::defaults($target));

            // Already issued: show what was printed (date + basis are stored on the letter).
            $issued = $target->letters()->orderBy('type', 'desc')->first();

            if ($issued !== null) {
                $this->fill([
                    'tanggalSurat' => $issued->issued_on->format('Y-m-d'),
                    'dasarNomor' => (string) $issued->basis_number,
                    'dasarTanggal' => $issued->basis_date?->format('Y-m-d') ?? '',
                ]);
            }
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'guru' ? 'guru' : 'siswa';
    }

    public function updatedNomorSiswa(): void
    {
        $this->nomorSiswa = self::digits($this->nomorSiswa);
        $this->checkTaken(LetterType::Siswa, 'nomorSiswa', $this->nomorSiswa);
    }

    public function updatedNomorGuru(): void
    {
        $this->nomorGuru = self::digits($this->nomorGuru);
        $this->checkTaken(LetterType::Guru, 'nomorGuru', $this->nomorGuru);
    }

    public function updatedTanggalSurat(): void
    {
        $this->resetErrorBag('tanggalSurat');
        $this->checkTaken(LetterType::Siswa, 'nomorSiswa', $this->nomorSiswa);
        $this->checkTaken(LetterType::Guru, 'nomorGuru', $this->nomorGuru);
    }

    public function generate(IssueLetters $issue): void
    {
        $registration = $this->selected();

        $this->validate([
            'nomorSiswa' => ['required', 'regex:/^\d{1,5}$/'],
            'nomorGuru' => [$registration->mentor_id !== null ? 'required' : 'nullable', 'regex:/^\d{1,5}$/'],
            'tanggalSurat' => ['required', 'date_format:Y-m-d'],
            'dasarNomor' => ['nullable', 'string', 'max:150'],
            'dasarTanggal' => ['nullable', 'date_format:Y-m-d'],
            'hariTanggal' => ['required', 'string', 'max:150'],
            'pukul' => ['required', 'string', 'max:150'],
            'tempat' => ['required', 'string', 'max:150'],
        ], [
            'required' => 'Wajib diisi.',
            'regex' => 'Isi nomor urut 1–99999.',
            'date_format' => 'Tanggal tidak valid.',
            'max' => 'Maksimal :max karakter.',
        ]);

        try {
            $issue->handle($this->user(), $registration, $this->draft());
        } catch (ValidationException $e) {
            $this->showActionErrors($e);

            return;
        }

        $this->dispatch('sth-toast', message: 'PDF surat siap. Periksa lalu kirim ke siswa dan guru.', tone: 'success');
    }

    public function send(SendLetters $send): void
    {
        $registration = $this->selected();

        try {
            $send->handle($this->user(), $registration);
        } catch (ValidationException $e) {
            $this->showActionErrors($e);

            return;
        }

        $this->dispatch('sth-toast', message: 'Surat terkirim ke siswa dan guru pembimbing.', tone: 'success');
    }

    public function download(string $letterId): StreamedResponse
    {
        $registration = $this->selected();
        $letter = Letter::query()->where('registration_id', $registration->getKey())->whereKey($letterId)->firstOrFail();

        return self::streamLetter($this->user(), $letter);
    }

    /** Shared with ArsipSurat: policy check, audit, stream from the private disk. */
    public static function streamLetter(User $user, Letter $letter): StreamedResponse
    {
        Gate::forUser($user)->authorize('view', $letter);

        $file = $letter->file_id !== null ? File::query()->find($letter->file_id) : null;
        abort_if($file === null || $file->purpose !== FilePurpose::Letter || ! $file->storage()->exists($file->path), 404);

        activity('letter')
            ->performedOn($letter)
            ->causedBy($user)
            ->event('letter.downloaded')
            ->withProperties(['number' => $letter->full_number])
            ->log('letter.downloaded');

        return $file->storage()->download($file->path, $file->original_name, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function render(IssueLetters $issue): View
    {
        Gate::authorize('viewQueue', Letter::class);

        $queue = $this->queueQuery()
            ->with(['competition', 'members' => fn ($q) => $q->whereNotNull('confirmed_at')->whereNull('declined_at'), 'letters'])
            ->get();

        $registration = $this->registrationId !== null ? $this->selectedOrNull() : null;
        $letters = $registration?->letters()->with('file')->orderBy('type', 'desc')->get() ?? new Collection;
        $issuedOn = $this->previewDate();

        $data = [
            'queue' => $queue,
            'registration' => $registration,
            'letters' => $letters,
            'issued' => $letters->isNotEmpty(),
            'sent' => $letters->isNotEmpty() && $letters->every(fn (Letter $l): bool => $l->sent_at !== null),
            'signature' => $this->signatureName(),
        ];

        if ($registration !== null) {
            $hasMentor = $registration->mentor_id !== null;
            $content = LetterContent::build($registration, $this->draft(lenient: true));

            $data += [
                'hasMentor' => $hasMentor,
                'content' => $content,
                'siswaParts' => $issue->numberParts(LetterType::Siswa, $issuedOn),
                'guruParts' => $issue->numberParts(LetterType::Guru, $issuedOn),
                'lastSiswa' => Letter::lastSequence(LetterType::Siswa, $issuedOn->year),
                'lastGuru' => Letter::lastSequence(LetterType::Guru, $issuedOn->year),
                'siswaNumber' => $letters->firstWhere('type', LetterType::Siswa)?->full_number,
                'guruNumber' => $letters->firstWhere('type', LetterType::Guru)?->full_number,
                'ready' => $this->nomorSiswa !== '' && (! $hasMentor || $this->nomorGuru !== '')
                    && ! $this->getErrorBag()->hasAny(['nomorSiswa', 'nomorGuru']),
            ];
        }

        return view('livewire.tata-usaha.surat-tugas', $data)->layoutData([
            'subtitle' => 'Pendaftaran yang sudah disetujui Kepala Sekolah.',
            'badges' => ['tu.surat' => $queue->count()],
        ]);
    }

    /** @return Builder<Registration> */
    private function queueQuery(): Builder
    {
        return Registration::query()->awaitingLetters()->orderBy('principal_decided_at')->orderBy('created_at');
    }

    private function selected(): Registration
    {
        Gate::authorize('viewQueue', Letter::class);

        $registration = $this->registrationId !== null ? $this->selectedOrNull() : null;
        abort_if($registration === null, 404);

        return $registration;
    }

    private function selectedOrNull(): ?Registration
    {
        $registration = Registration::query()
            ->with(['competition', 'mentor.staffProfile', 'members.student.user', 'members.student.classroom'])
            ->find($this->registrationId);

        if ($registration === null) {
            return null;
        }

        return $registration->status === RegistrationStatus::Disetujui || $registration->letters()->exists() ? $registration : null;
    }

    /** Draft from the form. Lenient (preview): blanks and bad dates fall back instead of failing. */
    private function draft(bool $lenient = false): LetterDraft
    {
        $issuedOn = self::parseDate($this->tanggalSurat);
        $basisDate = self::parseDate($this->dasarTanggal);

        if (! $lenient && $issuedOn === null) {
            throw ValidationException::withMessages(['tanggalSurat' => 'Tanggal tidak valid.']);
        }

        return new LetterDraft(
            siswaSequence: ctype_digit($this->nomorSiswa) ? (int) $this->nomorSiswa : null,
            guruSequence: ctype_digit($this->nomorGuru) ? (int) $this->nomorGuru : null,
            issuedOn: $issuedOn ?? CarbonImmutable::today(),
            basisNumber: trim($this->dasarNomor) !== '' ? trim($this->dasarNomor) : null,
            basisDate: $basisDate,
            scheduleDay: $this->hariTanggal,
            scheduleTime: $this->pukul,
            place: $this->tempat,
        );
    }

    private function previewDate(): CarbonImmutable
    {
        return self::parseDate($this->tanggalSurat) ?? CarbonImmutable::today();
    }

    private function checkTaken(LetterType $type, string $field, string $value): void
    {
        $this->resetErrorBag($field);

        if ($value === '') {
            return;
        }

        if ((int) $value < 1) {
            $this->addError($field, 'Isi nomor urut 1–99999.');

            return;
        }

        if (IssueLetters::isTaken($type, $this->previewDate()->year, (int) $value)) {
            $this->addError($field, IssueLetters::MSG_TAKEN);
        }
    }

    private function showActionErrors(ValidationException $e): void
    {
        $general = [];

        foreach ($e->errors() as $key => $messages) {
            $field = self::ERROR_MAP[$key] ?? null;

            if ($field !== null) {
                $this->addError($field, (string) $messages[0]);
            } else {
                $general[] = (string) $messages[0];
            }
        }

        $this->dispatch('sth-toast', message: $general[0] ?? 'Periksa isian yang ditandai.', tone: 'error');
    }

    private function signatureName(): ?string
    {
        $fileId = Setting::get(SettingKey::SignatureFileId);

        if (! is_string($fileId) || $fileId === '') {
            return null;
        }

        return File::query()->whereKey($fileId)->where('purpose', FilePurpose::Signature)->value('original_name');
    }

    private function user(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        return $user;
    }

    private static function digits(string $value): string
    {
        return mb_substr(preg_replace('/\D+/', '', $value) ?? '', 0, 5);
    }

    private static function parseDate(string $value): ?CarbonImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return Dates::toDate(trim($value));
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
