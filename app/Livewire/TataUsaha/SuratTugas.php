<?php

declare(strict_types=1);

namespace App\Livewire\TataUsaha;

use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterContent;
use App\Actions\Letters\LetterDraft;
use App\Actions\Letters\RevokeLetter;
use App\Actions\Letters\SendLetters;
use App\Actions\Letters\StreamLetter;
use App\Actions\Registrations\ApproveRegistration;
use App\Actions\Registrations\CancelApprovedRegistration;
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
use App\Support\StaffRoleLabel;
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
use Livewire\Attributes\Url;
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

    /** "Kirim" asks first once every letter exists (QA6, Q1-10): the send cannot be taken back. */
    public const SEND_DIALOG = 'kirim-surat';

    /** D-155: "Hapus dari antrean" asks for the reason first; the cancel cannot be taken back. */
    public const CANCEL_DIALOG = 'hapus-antrean';

    public const MSG_CANCELLED_ELSEWHERE = 'Pendaftaran ini sudah tidak ada di antrean surat. Daftar sudah diperbarui.';

    /**
     * What both letters print about the event (QA6, Q1-15, D-116). A letter that is not made yet takes these from the
     * other letter when TU typed them there or that letter was issued with them, but never over a field TU typed for
     * this letter itself, not even an emptied one or one set back to the lomba value (D-83: two forms, never one shared
     * draft). The number and the letter date stay per letter; the invitation (Surat dari, Nomor, Tanggal, Hal) is not
     * shared since D-154: the guru letter keeps its own (D-157: "surat gurunya dibuat default saja"), the student letter
     * prints its optional one as "Dasar" number 2.
     */
    private const SHARED_FIELDS = ['hari', 'tanggal', 'pukul', 'tempat'];

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

    /** Top bar search (backlog #60): lomba or student name; the badge still counts the whole queue. */
    #[Url(as: 'q', except: '')]
    public string $q = '';

    /** B3: the current letter being corrected (cabut + new version), set only by startRevision(). */
    #[Locked]
    public ?string $revisingId = null;

    /** "Koreksi" on Arsip surat names its letter (QA6, Q1-13): read once in mount(), emptied when the correction ends. */
    #[Url(as: 'koreksi', except: '')]
    public string $koreksi = '';

    public string $alasan = '';

    /** D-155: the registration "Hapus dari antrean" asked about; set only by askCancel(). */
    #[Locked]
    public ?string $cancellingId = null;

    /** D-155: the reason typed in the dialog (CancelApprovedRegistration checks it). */
    public string $cancelNote = '';

    /**
     * Form keys TU typed per letter during this visit (`siswa` / `guru` => list of keys), so carrySharedFields() can
     * tell a typed value from a prefill even when they are equal.
     *
     * @var array<string, list<string>>
     */
    #[Locked]
    public array $typed = [];

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
            $target = $this->searched($this->queueQuery())->first();
        }

        if ($target !== null) {
            $this->select($target);
        }

        $this->openCorrection();
    }

    /** A search that found nothing left no registration open: open the first match once there is one again. */
    public function updatedQ(): void
    {
        Gate::authorize('viewQueue', Letter::class);

        if ($this->registrationId === null && ($target = $this->searched($this->queueQuery())->first()) !== null) {
            $this->select($target);
        }
    }

    private function select(Registration $target): void
    {
        $this->registrationId = $target->getKey();
        $this->typed = [];
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
        $this->carrySharedFields($target, $letters);
    }

    /** `?koreksi=<letter>` from Arsip surat: that letter's correction form, else just the page. */
    private function openCorrection(): void
    {
        $letterId = $this->koreksi;
        $this->koreksi = '';

        if ($letterId === '' || $this->registrationId === null) {
            return;
        }

        $letter = Letter::query()->where('registration_id', $this->registrationId)->current()->whereKey($letterId)->first();

        if ($letter !== null && Gate::allows('revoke', $letter)) {
            $this->startRevision((string) $letter->getKey());
            $this->koreksi = (string) $letter->getKey();
        }
    }

    /**
     * See SHARED_FIELDS. Only into a letter that is not issued yet, never while correcting.
     *
     * @param  Collection<int, Letter>|null  $letters
     */
    private function carrySharedFields(Registration $registration, ?Collection $letters = null): void
    {
        if ($this->revisingId !== null) {
            return;
        }

        $letters ??= $registration->letters()->get();

        foreach (LetterType::cases() as $into) {
            if ($letters->firstWhere('type', $into) !== null) {
                continue;
            }

            foreach (LetterType::cases() as $from) {
                if ($from === $into) {
                    continue;
                }

                $issued = $letters->firstWhere('type', $from) !== null;

                foreach (self::SHARED_FIELDS as $key) {
                    $theirs = trim((string) ($this->{$from->value}[$key] ?? ''));

                    if ($theirs !== '' && ! $this->typedIn($into, $key) && ($issued || $this->typedIn($from, $key))) {
                        $this->{$into->value}[$key] = $theirs;
                    }
                }
            }
        }
    }

    private function typedIn(LetterType $type, string $key): bool
    {
        return in_array($key, $this->typed[$type->value] ?? [], true);
    }

    public function setTab(string $tab): void
    {
        $registration = $this->selected();
        $this->tab = $tab === 'guru' && $registration->mentor_id !== null ? 'guru' : 'siswa';
        $this->carrySharedFields($registration);
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
        $this->carrySharedFields($registration);
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

        if ($letter === null) {
            return;
        }

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
        $this->reset('revisingId', 'alasan', 'koreksi');

        if ($letter !== null) {
            $this->{$letter->type->value} = LetterDraft::fromLetter($letter, $registration);
        }
    }

    public function revise(RevokeLetter $revoke): void
    {
        $registration = $this->selected();
        $letter = $this->currentLetter((string) $this->revisingId);

        if ($letter === null) {
            return;
        }

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
        $this->reset('revisingId', 'alasan', 'koreksi');
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

    /**
     * A current letter of the selected registration, or null when another tab corrected it meanwhile (QA9): a 404 used
     * to hide "Surat ini sudah dicabut.", so revokedElsewhere() says it and shows the new version. A letter of another
     * registration is still a 404.
     */
    private function currentLetter(string $letterId): ?Letter
    {
        $registration = $this->selected();

        /** @var Letter $letter */
        $letter = Letter::query()->where('registration_id', $registration->getKey())->whereKey($letterId)->firstOrFail();

        if ($letter->revoked_at === null) {
            return $letter;
        }

        $this->revokedElsewhere($registration, $letter->type);

        return null;
    }

    /** Closes the correction form and shows the letter of that type as it stands now (the new version). */
    private function revokedElsewhere(Registration $registration, LetterType $type): void
    {
        $current = Letter::query()->where('registration_id', $registration->getKey())->where('type', $type)->current()->first();

        $this->resetErrorBag();
        $this->clearFailure();
        $this->reset('revisingId', 'alasan', 'koreksi');
        $this->tab = $type->value;
        $this->{$type->value} = $current !== null ? LetterDraft::fromLetter($current, $registration) : LetterDraft::defaults($registration);
        $this->dispatch('sth-toast', message: 'Surat ini sudah dicabut. Tampilan sudah diperbarui.', tone: 'warning');
    }

    // --- end B3 ---

    public function send(SendLetters $send): void
    {
        $registration = $this->selected();
        $this->resetErrorBag();
        $this->dispatch('sth-modal-close', id: self::SEND_DIALOG);

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

        // QA10 (D-109): name only who SendLetters told (accounts that can log in; the guru only with a guru letter).
        $told = array_values(array_filter([
            SendLetters::notifiesStudents($registration) ? 'siswa' : null,
            SendLetters::notifiesMentor($registration) ? 'guru pembimbing' : null,
        ]));
        $this->dispatch('sth-toast', message: $told === []
            ? 'Surat ditandai terkirim. Tidak ada akun aktif yang diberi tahu.'
            : 'Surat terkirim ke '.implode(' dan ', $told).'.', tone: 'success');
        $this->refreshBadge();
    }

    // --- D-155: remove an approved registration from the queue (cancel it with a reason) ---

    /** Opens the dialog for the selected registration (D-128 pattern: the confirm acts only on this stored question). */
    public function askCancel(): void
    {
        Gate::authorize('viewQueue', Letter::class);
        $registration = $this->registrationId !== null ? $this->selectedOrNull() : null;

        if ($registration === null || $registration->status !== RegistrationStatus::Disetujui) {
            $this->cancelledElsewhere();

            return;
        }

        Gate::authorize('cancelApproved', $registration);

        $this->resetErrorBag('cancelNote');
        $this->cancelNote = '';
        $this->cancellingId = (string) $registration->getKey();
        $this->dispatch('sth-modal-open', id: self::CANCEL_DIALOG);
    }

    /** The dialog closed without a cancel (X, Esc, "Batal"): forget the question. */
    public function forgetCancel(): void
    {
        $this->resetErrorBag('cancelNote');
        $this->reset('cancellingId', 'cancelNote');
    }

    /** "Hapus dari antrean" in the dialog. Without askCancel() first it does nothing (a double click is not an error). */
    public function cancelQueued(CancelApprovedRegistration $cancel): void
    {
        if ($this->cancellingId === null) {
            return;
        }

        Gate::authorize('viewQueue', Letter::class);
        $this->resetErrorBag('cancelNote');
        $registration = Registration::query()->find($this->cancellingId);

        if ($registration === null || $registration->status !== RegistrationStatus::Disetujui) {
            $this->cancelledElsewhere();

            return;
        }

        // Who will hear of it, read before the cancel (the same rule the Action uses after it).
        $told = CancelApprovedRegistration::audience($registration, $this->user());

        try {
            $cancel->handle($this->user(), $registration, $this->cancelNote);
        } catch (ValidationException $e) {
            if (array_key_exists('note', $e->errors())) {
                $this->addError('cancelNote', (string) $e->errors()['note'][0]);

                return;
            }

            $this->cancelledElsewhere();

            return;
        }

        $this->dispatch('sth-modal-close', id: self::CANCEL_DIALOG);
        $this->reset('cancellingId', 'cancelNote');
        $this->clearFailure();
        $this->dispatch('sth-toast', message: 'Pendaftaran dibatalkan dan dihapus dari antrean. '.self::toldSentence($told), tone: 'success');
        $this->openNextInQueue();
        $this->refreshBadge();
    }

    /** A registration another tab, account or "Kirim" took out of the queue meanwhile: a toast and the fresh queue. */
    private function cancelledElsewhere(): void
    {
        $this->dispatch('sth-modal-close', id: self::CANCEL_DIALOG);
        $this->reset('cancellingId', 'cancelNote');
        $this->dispatch('sth-toast', message: self::MSG_CANCELLED_ELSEWHERE, tone: 'warning');
        $this->openNextInQueue();
        $this->refreshBadge();
    }

    /** After a registration left the queue: the first one still in it (and its address), or the empty queue. */
    private function openNextInQueue(): void
    {
        $current = $this->registrationId !== null ? $this->selectedOrNull() : null;

        if ($current !== null && $current->status === RegistrationStatus::Disetujui) {
            return;
        }

        $this->reset('revisingId', 'alasan', 'koreksi');
        $next = $this->searched($this->queueQuery())->first();

        if ($next !== null) {
            $this->select($next);
            $this->dispatch('sth-address', url: route('tu.surat.show', $next));

            return;
        }

        $this->registrationId = null;
        $this->typed = [];
        $this->dispatch('sth-address', url: route('tu.beranda'));
    }

    /**
     * What the dialog says before the click: the registration is cancelled, which letters are revoked, who is told.
     *
     * @param  Collection<int, Letter>  $letters  the current letters of the registration
     */
    private function cancelNotice(Registration $registration, Collection $letters): string
    {
        $made = $letters->count();

        return 'Pendaftaran '.$registration->competition?->name.' dibatalkan dan hilang dari antrean.'
            .($made > 0 ? ' '.$made.' surat yang sudah dibuat ikut dicabut.' : '')
            .' '.self::toldSentence(CancelApprovedRegistration::audience($registration, $this->user()))
            .' Pembatalan tidak bisa dikembalikan; siswa bisa mendaftar lagi selama pendaftaran lomba masih dibuka.';
    }

    /**
     * "3 siswa, guru pembimbing, dan Drs. Bambang Hartono, M.Pd. (Kepala Sekolah) diberi tahu beserta alasannya." Only who
     * CancelApprovedRegistration::audience() names (D-109: never a notice nobody gets).
     *
     * @param  array{siswa: list<User>, guru: list<User>, pemutus: list<User>}  $told
     */
    public static function toldSentence(array $told): string
    {
        $parts = array_values(array_filter([
            $told['siswa'] !== [] ? count($told['siswa']).' siswa' : null,
            $told['guru'] !== [] ? 'guru pembimbing' : null,
            $told['pemutus'] !== [] ? $told['pemutus'][0]->name.' ('.StaffRoleLabel::forDecision($told['pemutus'][0]).') yang menyetujuinya' : null,
        ]));

        if ($parts === []) {
            return 'Tidak ada akun aktif yang diberi tahu.';
        }

        $last = array_pop($parts);
        $list = $parts === [] ? $last : implode(', ', $parts).(count($parts) > 1 ? ',' : '').' dan '.$last;

        return ucfirst($list).' diberi tahu beserta alasannya.';
    }

    // --- end D-155 ---

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

    /**
     * First sentence of the "Kirim surat sekarang?" dialog (QA10, D-109): only who SendLetters will really tell, the
     * members whose account can log in and a guru pembimbing who is still a guru.
     */
    private static function sendNotice(Registration $registration): string
    {
        $members = $registration->members()->whereNotNull('confirmed_at')->whereNull('declined_at')->count();
        $told = ApproveRegistration::memberUsers($registration)->count();
        $mentor = SendLetters::notifiesMentor($registration);

        $parts = array_values(array_filter([$told > 0 ? $told.' siswa' : null, $mentor ? '1 guru pembimbing' : null]));
        $sentence = $parts === [] ? 'Tidak ada akun aktif yang diberi tahu.' : implode(' dan ', $parts).' mendapat notifikasi dan bisa men-download suratnya.';

        if ($members > $told) {
            $sentence .= ' '.($members - $told).' siswa tidak diberi tahu karena akunnya tidak aktif.';
        }

        if ($registration->mentor_id !== null && ! $mentor && $registration->letters()->where('type', LetterType::Guru)->exists()) {
            $sentence .= ' Guru pembimbing tidak diberi tahu.';
        }

        return $sentence;
    }

    public function render(IssueLetters $issue): View
    {
        Gate::authorize('viewQueue', Letter::class);

        $queue = $this->searched($this->queueQuery())
            ->with(['competition', 'members' => fn ($q) => $q->whereNotNull('confirmed_at')->whereNull('declined_at'), 'letters', 'principalDecider.roles'])
            ->get();
        $total = trim($this->q) === '' ? $queue->count() : $this->queueQuery()->count();

        $registration = $this->registrationId !== null ? $this->selectedOrNull() : null;
        $letters = $registration?->letters()->with('file')->orderBy('type', 'desc')->get() ?? new Collection;

        $data = [
            'queue' => $queue,
            'queueTotal' => $total,
            'searching' => trim($this->q) !== '',
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
                'sendNotice' => self::sendNotice($registration),
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

            // D-155: "Hapus dari antrean" (Tata Usaha, while the registration is still approved and unsent).
            $canCancel = Gate::allows('cancelApproved', $registration);
            $data += [
                'canCancel' => $canCancel,
                'cancelNotice' => $canCancel ? $this->cancelNotice($registration, $letters) : '',
            ];
        }

        return view('livewire.tata-usaha.surat-tugas', $data)->layoutData([
            'subtitle' => 'Pendaftaran yang sudah disetujui.',
            'badges' => ['tu.surat' => $total],
        ]);
    }

    /** @return Builder<Registration> */
    private function queueQuery(): Builder
    {
        return Registration::query()->awaitingLetters()->orderBy('principal_decided_at')->orderBy('created_at');
    }

    /**
     * @param  Builder<Registration>  $query
     * @return Builder<Registration>
     */
    private function searched(Builder $query): Builder
    {
        $term = mb_substr(trim($this->q), 0, 100);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(fn (Builder $q) => $q
            ->whereHas('competition', fn (Builder $c) => $c->withTrashed()->where('name', 'like', $like))
            ->orWhereHas('members.student.user', fn (Builder $u) => $u->where('name', 'like', $like)));
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

        if (in_array($field, self::SHARED_FIELDS, true) && ! $this->typedIn($type, $field)) {
            $this->typed[$type->value][] = $field;
        }

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
