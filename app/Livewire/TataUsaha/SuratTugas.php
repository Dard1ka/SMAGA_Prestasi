<?php

declare(strict_types=1);

namespace App\Livewire\TataUsaha;

use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterContent;
use App\Actions\Letters\LetterDraft;
use App\Actions\Letters\RevokeLetter;
use App\Actions\Letters\SendLetters;
use App\Actions\Letters\StreamLetter;
use App\Actions\Templates\LetterTemplate;
use App\Domain\Letters\LetterNumber;
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
use App\Policies\LetterPolicy;
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
 * (`tu.beranda`, first one selected) or one of them (`tu.surat.show`).
 *
 * M1-A (lead, 2026-10-04): every letter has its OWN form, data and "Buat PDF". The two tabs "Surat siswa" /
 * "Surat guru pendamping" each show that letter's form (complete number typed by TU, letter date, Dasar,
 * Pelaksanaan — prefilled from the lomba), its live preview and, once issued, Download / Cetak / Koreksi.
 * "Kirim ke siswa dan guru" stays one action for the registration and needs every required letter.
 * No silent dead ends: the buttons stay enabled, the Actions validate, every wrong field gets its message, a
 * summary lists them and the page jumps there (browser event `sth-form-invalid`). After issue / revise / send
 * the sidebar badge gets the fresh queue count (`sth-badges`). Every action re-authorizes; ids are #[Locked].
 */
#[Title('Surat tugas')]
class SuratTugas extends Component
{
    /** Key of this screen's error toasts: `sth-toast-clear` with it takes them away again (app.js). */
    private const TOAST_KEY = 'surat-tugas';

    /** Action error key → form field (per letter). */
    private const ERROR_MAP = [
        'number' => 'nomor',
        'issued_on' => 'tanggalSurat',
        'basis_from' => 'dasarDari',
        'basis_number' => 'dasarNomor',
        'basis_date' => 'dasarTanggal',
        'basis_subject' => 'dasarHal',
        'schedule_day' => 'hari',
        'schedule_date' => 'tanggal',
        'schedule_time' => 'pukul',
        'place' => 'tempat',
    ];

    /** Form field → name in the error summary (same order as on screen). */
    private const FIELD_LABELS = [
        'nomor' => 'Nomor surat',
        'alasan' => 'Alasan pencabutan',
        'tanggalSurat' => 'Tanggal surat',
        'dasarDari' => 'Surat dari',
        'dasarNomor' => 'Nomor surat undangan',
        'dasarTanggal' => 'Tanggal surat undangan',
        'dasarHal' => 'Hal',
        'hari' => 'Hari',
        'tanggal' => 'Tanggal pelaksanaan',
        'pukul' => 'Pukul',
        'tempat' => 'Tempat',
    ];

    #[Locked]
    public ?string $registrationId = null;

    /**
     * Form of the student letter (keys: LetterDraft::defaults()).
     *
     * @var array<string, string>
     */
    public array $siswa = [];

    /**
     * Form of the guru letter (same keys; `dasarHal` is printed on this letter only).
     *
     * @var array<string, string>
     */
    public array $guru = [];

    public string $tab = 'siswa';

    /** B3: the current letter being corrected (cabut + new version), set only by startRevision(). */
    #[Locked]
    public ?string $revisingId = null;

    public string $alasan = '';

    /** A submit failed: show the summary of what is still wrong (cleared by the next success or tab change). */
    #[Locked]
    public bool $failed = false;

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

        if ($target === null) {
            return;
        }

        $this->registrationId = $target->getKey();
        $target->loadMissing('competition');
        $letters = $target->letters()->get();

        // Each letter: what it printed when it exists, else the prefill from the lomba.
        foreach (LetterType::cases() as $type) {
            $letter = $letters->firstWhere('type', $type);
            $this->{$type->value} = $letter !== null ? LetterDraft::fromLetter($letter, $target) : LetterDraft::defaults($target);
        }

        // Open on the first letter that still has to be made.
        $missing = SendLetters::missing($target, $letters);
        $this->tab = $missing !== [] ? $missing[0]->value : 'siswa';
    }

    public function setTab(string $tab): void
    {
        $registration = $this->selected();
        $this->tab = $tab === 'guru' && $registration->mentor_id !== null ? 'guru' : 'siswa';
        $this->clearFailure();
    }

    // --- live feedback while typing (the Actions decide on submit) ---

    public function updatedSiswa(mixed $value, ?string $key = null): void
    {
        $this->fieldChanged(LetterType::Siswa, (string) $key);
    }

    public function updatedGuru(mixed $value, ?string $key = null): void
    {
        $this->fieldChanged(LetterType::Guru, (string) $key);
    }

    public function updatedAlasan(): void
    {
        $this->resetErrorBag('alasan');
    }

    // --- issue one letter ---

    /** "Buat PDF" of the letter whose tab is open (the form on screen). */
    public function generate(IssueLetters $issue): void
    {
        $registration = $this->selected();
        $letterType = $this->letterType($this->tab, $registration);
        $this->resetErrorBag();

        try {
            $letter = $issue->handle($this->user(), $registration, $letterType, $this->draft($letterType));
        } catch (ValidationException $e) {
            $this->showActionErrors($e, $letterType, 'Belum bisa membuat PDF. Periksa isian yang ditandai.');

            return;
        }

        $this->clearFailure();
        $this->{$letterType->value} = LetterDraft::fromLetter($letter, $registration);
        $missing = SendLetters::missing($registration, $registration->letters()->get());

        $this->dispatch('sth-toast', tone: 'success', message: $missing !== []
            ? 'PDF '.self::typeName($letterType).' siap. Lanjut buat '.self::typeName($missing[0]).'.'
            : 'PDF '.self::typeName($letterType).' siap. Periksa lalu kirim.');
        $this->refreshBadge();
    }

    // --- B3: correction = cabut + new version (backlog #11) ---

    /** Open the correction form for one current letter of the selected registration. */
    public function startRevision(string $letterId): void
    {
        $letter = $this->currentLetter($letterId);
        Gate::authorize('revoke', $letter);

        $registration = $this->selected();
        $type = $letter->type;

        $this->resetErrorBag();
        $this->clearFailure();
        $this->revisingId = (string) $letter->getKey();
        $this->tab = $type->value;
        $this->alasan = '';
        // The new version starts from what the old one printed, with today's date and an empty number.
        $this->{$type->value} = array_merge(LetterDraft::fromLetter($letter, $registration), [
            'nomor' => '',
            'tanggalSurat' => CarbonImmutable::today()->format('Y-m-d'),
        ]);
    }

    public function cancelRevision(): void
    {
        $registration = $this->selected();
        $letter = $this->revisingId !== null ? $registration->letters()->whereKey($this->revisingId)->first() : null;

        $this->resetErrorBag();
        $this->clearFailure();
        $this->reset('revisingId', 'alasan');

        if ($letter !== null) {
            $this->{$letter->type->value} = LetterDraft::fromLetter($letter, $registration);
        }
    }

    public function revise(RevokeLetter $revoke): void
    {
        $registration = $this->selected();
        $letter = $this->currentLetter((string) $this->revisingId);
        $type = $letter->type;
        $this->tab = $type->value;
        $this->resetErrorBag();

        try {
            $new = $revoke->handle($this->user(), $letter, $this->alasan, $this->draft($type));
        } catch (ValidationException $e) {
            $this->showActionErrors($e, $type, 'Belum bisa mencabut surat. Periksa isian yang ditandai.');

            return;
        }

        $this->clearFailure();
        $this->reset('revisingId', 'alasan');
        $this->{$type->value} = LetterDraft::fromLetter($new, $registration);
        $this->dispatch('sth-toast', tone: 'success', message: $new->sent_at !== null
            ? 'Surat lama dicabut. Versi baru '.$new->full_number.' terkirim.'
            : 'Surat lama dicabut. Versi baru '.$new->full_number.' siap dikirim.');
        $this->refreshBadge();
    }

    /**
     * Revoked versions of the selected registration, newest first (view model).
     *
     * @return list<array{id: string, number: string, type: string, version: int, revokedOn: string, reason: string, by: string, replacement: ?string}>
     */
    private function history(Registration $registration): array
    {
        return $registration->letterHistory()
            ->whereNotNull('revoked_at')
            ->with(['replacement:id,replaces_letter_id,full_number', 'revoker:id,name'])
            ->orderByDesc('revoked_at')
            ->get()
            ->map(fn (Letter $l): array => [
                'id' => (string) $l->getKey(),
                'number' => (string) $l->full_number,
                'type' => $l->type->label(),
                'version' => (int) $l->version,
                'revokedOn' => (string) $l->revoked_at?->locale('id')->translatedFormat('j M Y, H.i'),
                'reason' => (string) $l->revoke_reason,
                'by' => (string) ($l->revoker?->name ?? '-'),
                'replacement' => $l->replacement?->full_number,
            ])
            ->all();
    }

    private function currentLetter(string $letterId): Letter
    {
        $registration = $this->selected();

        /** @var Letter $letter */
        $letter = Letter::query()->where('registration_id', $registration->getKey())->current()->whereKey($letterId)->firstOrFail();

        return $letter;
    }

    // --- end B3 ---

    public function send(SendLetters $send): void
    {
        $registration = $this->selected();
        $this->resetErrorBag();

        try {
            $send->handle($this->user(), $registration);
        } catch (ValidationException $e) {
            // Name what is missing, open that letter's tab and bring the message into view.
            $missing = SendLetters::missing($registration, $registration->letters()->get());

            if ($missing !== [] && $this->revisingId === null) {
                $this->tab = $missing[0]->value;
            }

            $message = (string) (collect($e->errors())->flatten()->first() ?? 'Surat belum bisa dikirim.');
            $this->addError('kirim', $message);
            $this->dispatch('sth-form-invalid', form: 'surat-kirim');
            $this->dispatch('sth-toast', message: $message, tone: 'error', key: self::TOAST_KEY);

            return;
        }

        $this->clearFailure();
        $this->dispatch('sth-toast', message: 'Surat terkirim ke siswa dan guru pembimbing.', tone: 'success');
        $this->refreshBadge();
    }

    public function download(string $letterId): StreamedResponse
    {
        $registration = $this->selected();
        $letter = Letter::query()->where('registration_id', $registration->getKey())->whereKey($letterId)->firstOrFail();

        return self::streamLetter($this->user(), $letter);
    }

    /** Shared with ArsipSurat and Kepsek\SuratSaya: policy check, audit, stream from the private disk. */
    public static function streamLetter(User $user, Letter $letter): StreamedResponse
    {
        return app(StreamLetter::class)->handle($user, $letter);
    }

    public function render(IssueLetters $issue): View
    {
        Gate::authorize('viewQueue', Letter::class);

        $queue = $this->queueQuery()
            ->with(['competition', 'members' => fn ($q) => $q->whereNotNull('confirmed_at')->whereNull('declined_at'), 'letters'])
            ->get();

        $registration = $this->registrationId !== null ? $this->selectedOrNull() : null;
        $letters = $registration?->letters()->with('file')->orderBy('type', 'desc')->get() ?? new Collection;

        $data = [
            'queue' => $queue,
            'registration' => $registration,
            'letters' => $letters,
            'signature' => $this->signatureName(),
        ];

        if ($registration !== null) {
            $required = LetterPolicy::requiredTypes($registration);
            $type = $this->tab === 'guru' && $registration->mentor_id !== null ? LetterType::Guru : LetterType::Siswa;
            $letter = $letters->firstWhere('type', $type);
            // B3 (#11): correction mode — the corrected letter's form opens again with a new number.
            $revising = $this->revisingId !== null ? $letters->firstWhere('id', $this->revisingId) : null;
            $editing = $letter === null || ($revising !== null && $revising->type === $type);
            $last = Letter::lastNumber($type);
            $missing = SendLetters::missing($registration, $letters);
            $typed = (new LetterNumber)->normalize((string) ($this->{$type->value}['nomor'] ?? ''));
            // An issued letter is shown as its PDF printed it, not as today's template, signer and classes would.
            $printedContent = $editing ? null : LetterContent::fromArray($letter->printed['content'] ?? null);
            $printedTemplate = $editing ? null : LetterTemplate::fromArray($letter->printed['template'] ?? null);
            $printed = $printedContent !== null && $printedTemplate !== null;

            $data += [
                'hasMentor' => $registration->mentor_id !== null,
                'type' => $type,
                'letter' => $letter,
                'editing' => $editing,
                'revising' => $revising !== null && $revising->type === $type ? $revising : null,
                'revisingOther' => $revising !== null && $revising->type !== $type ? $revising : null,
                'content' => $printed ? $printedContent : LetterContent::build($registration, $this->draft($type, lenient: true)),
                'letterTemplate' => $printed ? $printedTemplate : LetterTemplate::current(),
                'printed' => $printed,
                'number' => $editing ? ($typed !== '' ? $typed : null) : $letter->full_number,
                'lastNumber' => $last,
                'example' => $issue->example($type, $this->previewDate($type), $last),
                'problems' => $this->failed ? $this->problems($type) : [],
                'tabs' => array_map(fn (LetterType $t): array => [
                    'key' => $t->value,
                    'label' => $t === LetterType::Guru ? 'Surat guru pendamping' : 'Surat siswa',
                    'letter' => $letters->firstWhere('type', $t),
                ], $required),
                'missing' => array_map(self::typeName(...), $missing),
                'sent' => $letters->isNotEmpty() && $letters->every(fn (Letter $l): bool => $l->sent_at !== null),
                'history' => $this->history($registration),
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

    /** The sidebar is rendered once per page load: tell it the queue count after an action changed it. */
    private function refreshBadge(): void
    {
        $this->dispatch('sth-badges', badges: ['tu.surat' => $this->queueQuery()->count()]);
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

    /** The letter type of an action argument; a guru letter needs a guru pembimbing (else 404). */
    private function letterType(string $type, Registration $registration): LetterType
    {
        $letterType = LetterType::tryFrom($type);
        abort_if($letterType === null || ($letterType === LetterType::Guru && $registration->mentor_id === null), 404);

        return $letterType;
    }

    /**
     * Draft of one letter from its form. Lenient (preview): blanks and bad dates fall back instead of failing.
     * Strict (submit): a date that cannot be read is reported together with every other problem of the draft.
     *
     * @throws ValidationException keys as in IssueLetters
     */
    private function draft(LetterType $type, bool $lenient = false): LetterDraft
    {
        $form = $this->form($type);
        $issuedOn = self::parseDate($form['tanggalSurat']);
        $basisDate = self::parseDate($form['dasarTanggal']);

        $draft = new LetterDraft(
            number: $form['nomor'],
            issuedOn: $issuedOn ?? CarbonImmutable::today(),
            basisFrom: $form['dasarDari'],
            basisNumber: trim($form['dasarNomor']) !== '' ? trim($form['dasarNomor']) : null,
            basisDate: $basisDate,
            scheduleDay: $form['hari'],
            scheduleDate: $form['tanggal'],
            scheduleTime: $form['pukul'],
            place: $form['tempat'],
            basisSubject: $form['dasarHal'],
        );

        if ($lenient) {
            return $draft;
        }

        $dates = [];

        if ($issuedOn === null) {
            $dates['issued_on'] = trim($form['tanggalSurat']) === '' ? 'Isi tanggal surat.' : 'Tanggal surat tidak valid.';
        }

        if ($basisDate === null && trim($form['dasarTanggal']) !== '') {
            $dates['basis_date'] = 'Tanggal surat undangan tidak valid.';
        }

        if ($dates !== []) {
            throw ValidationException::withMessages($dates + IssueLetters::draftErrors($draft, $type));
        }

        return $draft;
    }

    /**
     * The form of a letter with every key present as a string (tampered or missing keys become '').
     *
     * @return array<string, string>
     */
    private function form(LetterType $type): array
    {
        $raw = $this->{$type->value};
        $form = [];

        foreach (self::ERROR_MAP as $field) {
            $form[$field] = is_scalar($raw[$field] ?? null) ? (string) $raw[$field] : '';
        }

        return $form;
    }

    private function previewDate(LetterType $type): CarbonImmutable
    {
        return self::parseDate($this->form($type)['tanggalSurat']) ?? CarbonImmutable::today();
    }

    /** One field changed: drop its old message; a typed number is checked at once (format, already used). */
    private function fieldChanged(LetterType $type, string $field): void
    {
        $key = $type->value.'.'.$field;
        $this->resetErrorBag([$key, 'umum', 'kirim']);

        if ($field !== 'nomor') {
            return;
        }

        $number = $this->form($type)['nomor'];

        if (trim($number) === '') {
            return;
        }

        $error = (new LetterNumber)->numberError($number);

        if ($error === null && IssueLetters::isTaken($type, $number)) {
            $error = IssueLetters::MSG_TAKEN;
        }

        if ($error !== null) {
            $this->addError($key, $error);
        }
    }

    /**
     * Put the Action's errors on the fields of that letter's form, keep the rest (signature, registration,
     * letter) as general messages, and ask the browser to jump to the summary (`sth-form-invalid`).
     */
    private function showActionErrors(ValidationException $e, LetterType $type, string $toast): void
    {
        $this->resetErrorBag();

        foreach ($e->errors() as $key => $messages) {
            $message = (string) ($messages[0] ?? 'Periksa isian ini.');

            if ($key === 'reason') {
                $this->addError('alasan', $message);
            } elseif (isset(self::ERROR_MAP[$key])) {
                $this->addError($type->value.'.'.self::ERROR_MAP[$key], $message);
            } else {
                $this->addError('umum', $message);
            }
        }

        $this->failed = true;
        $this->dispatch('sth-form-invalid', form: 'surat-form-'.$type->value);
        $this->dispatch('sth-toast', message: $toast, tone: 'error', key: self::TOAST_KEY);
    }

    /**
     * The failed submit is over (success, another letter, a correction opened or closed): drop the summary and the
     * error toast that reported it. Error toasts stay until closed (toast.blade.php), so without this one said
     * "Belum bisa membuat PDF" long after the PDF was made (QA retest 2026-10-06).
     */
    private function clearFailure(): void
    {
        $this->failed = false;
        $this->dispatch('sth-toast-clear', key: self::TOAST_KEY);
    }

    /**
     * What is still wrong in one letter's form, in screen order: [label, message] (label null = general).
     *
     * @return list<array{0: string|null, 1: string}>
     */
    private function problems(LetterType $type): array
    {
        $bag = $this->getErrorBag();
        $problems = [];

        foreach ($bag->get('umum') as $message) {
            $problems[] = [null, (string) $message];
        }

        foreach (self::FIELD_LABELS as $field => $label) {
            $key = $field === 'alasan' ? 'alasan' : $type->value.'.'.$field;

            if ($bag->has($key)) {
                $problems[] = [$label, (string) $bag->first($key)];
            }
        }

        return $problems;
    }

    private static function typeName(LetterType $type): string
    {
        return $type === LetterType::Guru ? 'surat guru pendamping' : 'surat siswa';
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
