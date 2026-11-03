<?php

declare(strict_types=1);

namespace App\Livewire\Siswa\LombaSaya;

use App\Actions\Reports\SubmitReport;
use App\Actions\Reports\SubmitReportData;
use App\Domain\Deadlines\ReportFollowUp;
use App\Domain\Points\PointBreakdown;
use App\Enums\Bidang;
use App\Enums\RegistrationType;
use App\Enums\ReportFileKind;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Livewire\Siswa\LombaSaya\Concerns\ReportHelpers;
use App\Models\Registration;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Laporkan hasil (UI-MAP #13, boards Lapor-Hasil-Mobile + the inline form of Lomba-Saya). One responsive
 * component: full page `siswa.lapor` (layouts::mobile) and embedded in Siswa\LombaSaya\Index (inline).
 * Live point preview from the active weight version ("final setelah diverifikasi"); SubmitReport does
 * every real check (ReportPolicy::create, StoreUpload). No deadline since D-151. After sending → Lomba saya detail.
 */
final class LaporHasil extends Component
{
    use ReportHelpers;
    use WithFileUploads {
        _uploadErrored as private livewireUploadErrored;
    }

    /** id of the form in the yellow card of Lomba saya ($inline) and on the full page; app.js scrolls inside it. */
    public const FORM_INLINE = 'lapor-form-inline';

    public const FORM_PAGE = 'lapor-form';

    /**
     * "Tambah foto" sends every chosen photo in ONE request, so a pick of photos under 5 MB each can still pass the
     * request limit (25 MB in production) and come back without messages (413): the general sentence ("Periksa ukuran
     * file") then blamed a size the student had kept. Only for that answer of fotoBaru (_uploadErrored()).
     */
    public const FOTO_UPLOAD_FAILED = 'Foto dokumentasi gagal di-upload. Pilih lebih sedikit foto sekaligus, maks. 5 MB per foto.';

    /**
     * A 422 of Livewire's temporary upload for "Tambah foto" (one photo over the limit of that request) refuses the
     * whole pick, which is one request: its own message under the picker names the size, not that no photo of the pick
     * was added. This sentence stands with the messages of the photos (render(), r3 review); FOTO_UPLOAD_FAILED says it
     * by itself.
     */
    public const FOTO_PICK_NOT_ADDED = 'Tidak ada foto dari pilihan ini yang ditambahkan.';

    /** A refused form or send that is not explained by a report sent elsewhere. */
    public const MSG_CANNOT_SEND = 'Laporan untuk lomba ini tidak bisa dikirim sekarang.';

    /** The report was sent in another tab meanwhile (the sentence of Lomba saya, Index::reportSentElsewhere()). */
    public const MSG_SENT_ELSEWHERE = 'Laporan hasil sudah dikirim. Halaman sudah diperbarui.';

    private const FILE_FIELDS = ['sertifikat', 'pengumuman', 'laporan', 'artikel', 'poster'];

    /** Error keys of the photo messages (photoMessages()): a new pick and "Hapus foto" take them away. */
    private const PHOTO_MESSAGES = ['foto', 'foto.*', 'fotoBaru', 'fotoBaru.*'];

    /** Fields whose message from a refused "Kirim laporan" goes as soon as the field is right (updated()). */
    private const ANSWER_FIELDS = ['hasil', 'asli', 'judul', 'abstrak'];

    #[Locked]
    public string $registrationId = '';

    #[Locked]
    public bool $inline = false;

    public string $hasil = '';

    /** @var TemporaryUploadedFile|null */
    public $sertifikat = null;

    /** @var list<TemporaryUploadedFile> */
    public array $foto = [];

    /** @var list<TemporaryUploadedFile>|TemporaryUploadedFile|null picker for "Tambah foto" (appended to) */
    public $fotoBaru = [];

    /** @var TemporaryUploadedFile|null */
    public $pengumuman = null;

    public string $judul = '';

    public string $abstrak = '';

    /** @var TemporaryUploadedFile|null */
    public $laporan = null;

    /** @var TemporaryUploadedFile|null */
    public $artikel = null;

    /** @var TemporaryUploadedFile|null */
    public $poster = null;

    public bool $asli = false;

    /**
     * The last "Kirim laporan" passed the form's rules and SubmitReport refused it with a message under "hasil" that is
     * not about the picked result ("Laporan sudah dikirim.", "Laporan untuk lomba ini tidak bisa dikirim sekarang.", a
     * refused transition): another tile does not judge that message away (updated(), r3 review); the next send decides.
     */
    #[Locked]
    public bool $hasilRefusedBySend = false;

    public function mount(Registration $registration, bool $inline = false): void
    {
        $this->inline = $inline;
        $this->registrationId = (string) $registration->getKey();

        if (! Gate::allows('create', [Report::class, $registration])) {
            abort_unless(Gate::allows('view', $registration), 404);

            session()->flash('toast', ['tone' => 'info', 'message' => self::MSG_CANNOT_SEND]);
            $this->redirectRoute('siswa.lomba-saya.show', ['registration' => $this->registrationId]);

            return;
        }

        $report = $registration->report;

        if ($report !== null && $report->status === ReportStatus::PerluRevisi) {
            $this->hasil = $report->claimed_result->value;
            $this->judul = (string) $report->research_title;
            $this->abstrak = (string) $report->research_abstract;
        }
    }

    #[Computed]
    public function registration(): Registration
    {
        $registration = Registration::query()
            ->with(['competition', 'report.files.file'])
            ->withCount(['members as active_members_count' => fn ($m) => $m->whereNull('declined_at')])
            ->find($this->registrationId);

        abort_if($registration === null, 404);

        return $registration;
    }

    #[Computed]
    public function preview(): ?PointBreakdown
    {
        return $this->previewBreakdown($this->registration->competition, $this->hasil !== '' ? $this->hasil : null);
    }

    public function updated(string $property): void
    {
        $field = explode('.', $property)[0];

        if (in_array($field, self::FILE_FIELDS, true) && $this->{$field} !== null) {
            $this->checkChosenFile($field);

            return;
        }

        // The message a refused "Kirim laporan" left under a field goes as soon as the field is right (a result picked,
        // the tick set, the title or the abstract filled in); a wrong value keeps it. A field without a message is not
        // judged while it is being filled in: the send names what is still missing. A refusal of SubmitReport under
        // "hasil" is not about the result, so a picked tile leaves it ($hasilRefusedBySend).
        if (in_array($field, self::ANSWER_FIELDS, true)
            && $this->getErrorBag()->has($field)
            && ! ($field === 'hasil' && $this->hasilRefusedBySend)) {
            $this->validateOnly($field);
        }
    }

    /**
     * "Tambah foto": every photo of the pick is checked on its own. The pick leaves fotoBaru before anything is checked:
     * Livewire appends every later pick to this property (WithFileUploads::_finishUpload(), append), so a refused photo
     * left in it refused every later photo too. The photos that pass join $foto up to SubmitReport::MAX_PHOTOS, in the
     * order picked; one message per reason names the photos that were not added (under the photo list, which stays on
     * the screen when the picker is gone at five photos).
     */
    public function updatedFotoBaru(): void
    {
        $picked = array_values(array_filter(
            is_array($this->fotoBaru) ? $this->fotoBaru : [$this->fotoBaru],
            fn (mixed $file): bool => $file instanceof TemporaryUploadedFile,
        ));
        $this->fotoBaru = [];

        if ($picked === []) {
            return;
        }

        // The messages of the previous pick (and of a failed upload) go with a new pick.
        $this->resetValidation(self::PHOTO_MESSAGES);

        $errors = Validator::make(['fotoBaru' => $picked], ['fotoBaru.*' => $this->fileRules('foto')], $this->messages(), ['fotoBaru.*' => 'foto'])
            ->errors();
        $room = max(0, SubmitReport::MAX_PHOTOS - count($this->foto));
        $added = [];
        /** @var array<string, list<string>> $refused message => names of the photos it refused */
        $refused = [];
        /** @var list<string> $noRoom good photos picked after the fifth */
        $noRoom = [];

        foreach ($picked as $i => $photo) {
            $reason = $errors->first("fotoBaru.{$i}");

            if ($reason !== '') {
                // A refused photo never takes a place: the photos after it can still fill the five.
                $refused[$reason][] = $photo->getClientOriginalName();
            } elseif (count($added) < $room) {
                $added[] = $photo;
            } else {
                $noRoom[] = $photo->getClientOriginalName();
            }
        }

        $this->foto = [...$this->foto, ...$added];

        foreach ($refused as $reason => $names) {
            $this->addError('foto', self::notAdded($reason, $names));
        }

        if ($noRoom !== []) {
            $this->addError('foto', self::notAdded('Maksimal '.SubmitReport::MAX_PHOTOS.' foto.', $noRoom));
        }
    }

    /**
     * Livewire's answer to a failed upload (WithFileUploads::_uploadErrored()), unchanged except for one case: the photo
     * picker's upload that came back without validation messages (413 from nginx or PHP, no network, a 5xx) says to pick
     * fewer photos at once (FOTO_UPLOAD_FAILED). A 422 of Livewire's temporary upload keeps its own messages per photo
     * (render() adds FOTO_PICK_NOT_ADDED under the photo list: the whole pick stayed out), and every other field keeps the
     * general sentence of validation.uploaded.
     */
    public function _uploadErrored(string $name, ?string $errorsInJson, bool $isMultiple): void
    {
        if ($name !== 'fotoBaru' || self::uploadAnswerHasMessages($errorsInJson)) {
            $this->livewireUploadErrored($name, $errorsInJson, $isMultiple);

            return;
        }

        $this->dispatch('upload:errored', name: $name)->self();

        throw ValidationException::withMessages([$name => self::FOTO_UPLOAD_FAILED]);
    }

    /**
     * "Hapus foto" also takes away the messages of the earlier pick and of a refused send (the same ones a new pick
     * resets, updatedFotoBaru()): "Maksimal 5 foto. Tidak ditambahkan: …" next to four photos, with a red picker, said
     * the opposite of the screen (third browser check). The next send names anything still missing.
     */
    public function removeFoto(int $index): void
    {
        unset($this->foto[$index]);
        $this->foto = array_values($this->foto);
        $this->resetValidation(self::PHOTO_MESSAGES);
    }

    public function removeFile(string $field): void
    {
        if (in_array($field, self::FILE_FIELDS, true)) {
            $this->{$field} = null;
            $this->resetValidation($field);
        }
    }

    public function kirim(SubmitReport $submit): void
    {
        $registration = $this->registration;

        if (! Gate::allows('create', [Report::class, $registration])) {
            if ($this->sentElsewhere($registration)) {
                return;
            }

            $this->dispatch('sth-toast', message: self::MSG_CANNOT_SEND, tone: 'error');

            return;
        }

        $this->hasilRefusedBySend = false;
        $rulesPassed = false;

        try {
            $this->validate();
            $rulesPassed = true;

            $isRiset = $this->isRiset();

            $submit->handle($this->user(), $registration, new SubmitReportData(
                result: ResultType::from($this->hasil),
                authentic: $this->asli,
                certificate: $this->sertifikat,
                photos: array_values($this->foto),
                announcement: $this->pengumuman,
                researchTitle: $isRiset ? $this->judul : null,
                researchAbstract: $isRiset ? $this->abstrak : null,
                researchReport: $isRiset ? $this->laporan : null,
                article: $isRiset ? $this->artikel : null,
                poster: $isRiset ? $this->poster : null,
            ));
        } catch (ValidationException $e) {
            // The form's rules passed, so a message under "hasil" is SubmitReport's and not about the picked result.
            $this->hasilRefusedBySend = $rulesPassed && array_key_exists('hasil', $e->errors());

            // A file the Action refused (StoreUpload reads its content) does not stay chosen either: its drop zone comes
            // back with the reason, not a "Siap dikirim" row. The photos stay: that message does not say which one.
            foreach (array_intersect(self::FILE_FIELDS, array_keys($e->errors())) as $field) {
                $this->{$field} = null;
            }

            // "Kirim laporan" sits at the end of the form and the first error is often at its top ("Pilih hasil yang
            // diraih." under the result tiles): app.js brings the first marked field of this form into view (SV1).
            $this->dispatch('sth-form-invalid', form: $this->formId());

            throw $e;
        }

        session()->flash('toast', ['tone' => 'success', 'message' => 'Laporan terkirim. Sekolah akan memverifikasi laporanmu.']);
        $this->redirectRoute('siswa.lomba-saya.show', ['registration' => $this->registrationId], navigate: true);
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        $existing = $this->existingKinds();
        $isRiset = $this->isRiset();

        return [
            'hasil' => ['required', Rule::enum(ResultType::class)],
            'asli' => ['accepted'],
            'sertifikat' => [in_array(ReportFileKind::Sertifikat->value, $existing, true) ? 'nullable' : 'required', ...$this->fileRules('sertifikat')],
            'foto' => [in_array(ReportFileKind::Foto->value, $existing, true) ? 'nullable' : 'required', 'array', 'max:'.SubmitReport::MAX_PHOTOS],
            'foto.*' => $this->fileRules('foto'),
            'pengumuman' => ['nullable', ...$this->fileRules('pengumuman')],
            'judul' => $isRiset ? ['required', 'string', 'max:'.SubmitReport::MAX_TITLE] : ['nullable'],
            'abstrak' => $isRiset ? ['required', 'string', 'max:3000', function (string $attribute, mixed $value, \Closure $fail): void {
                if (SubmitReport::wordCount((string) $value) > SubmitReport::MAX_ABSTRACT_WORDS) {
                    $fail('Abstrak maksimal '.SubmitReport::MAX_ABSTRACT_WORDS.' kata.');
                }
            }] : ['nullable'],
            'laporan' => [$isRiset && ! in_array(ReportFileKind::LaporanRiset->value, $existing, true) ? 'required' : 'nullable', ...$this->fileRules('laporan')],
            'artikel' => ['nullable', ...$this->fileRules('artikel')],
            'poster' => ['nullable', ...$this->fileRules('poster')],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'hasil.required' => 'Pilih hasil yang diraih.',
            'hasil.enum' => 'Pilih hasil yang diraih.',
            'asli.accepted' => 'Centang pernyataan keaslian dokumen dulu.',
            'sertifikat.required' => 'Upload sertifikat atau piagam.',
            'foto.required' => 'Tambahkan minimal 1 foto dokumentasi.',
            'foto.max' => 'Maksimal '.SubmitReport::MAX_PHOTOS.' foto.',
            'judul.required' => 'Isi judul penelitian.',
            'judul.max' => 'Judul maksimal '.SubmitReport::MAX_TITLE.' karakter.',
            'abstrak.required' => 'Isi abstrak penelitian.',
            'abstrak.max' => 'Abstrak maksimal '.SubmitReport::MAX_ABSTRACT_WORDS.' kata.',
            'laporan.required' => 'Upload laporan penelitian (PDF).',
            'sertifikat.max' => 'Ukuran file maksimal 5 MB.',
            'sertifikat.extensions' => 'Pakai PDF, JPG, PNG, atau WEBP.',
            'pengumuman.max' => 'Ukuran file maksimal 5 MB.',
            'pengumuman.extensions' => 'Pakai PDF, JPG, PNG, atau WEBP.',
            'poster.max' => 'Ukuran file maksimal 5 MB.',
            'poster.extensions' => 'Pakai PDF, JPG, PNG, atau WEBP.',
            'laporan.max' => 'Ukuran file maksimal 10 MB.',
            'laporan.extensions' => 'Laporan harus PDF.',
            'artikel.max' => 'Ukuran file maksimal 10 MB.',
            'artikel.extensions' => 'Artikel harus PDF.',
            'foto.*.max' => 'Ukuran foto maksimal 5 MB.',
            'foto.*.extensions' => 'Foto harus JPG, PNG, atau WEBP.',
            'fotoBaru.*.max' => 'Ukuran foto maksimal 5 MB.',
            'fotoBaru.*.extensions' => 'Foto harus JPG, PNG, atau WEBP.',
        ];
    }

    public function render(): View
    {
        $registration = $this->registration;
        $competition = $registration->competition;
        $report = $registration->report;
        $preview = $this->preview;

        $data = [
            'formId' => $this->formId(),
            'competition' => $competition,
            'isRiset' => $this->isRiset(),
            'isTeam' => $registration->type === RegistrationType::Tim,
            // D-151: no deadline; the page only asks to report now.
            'prompt' => ReportFollowUp::PROMPT,
            'revision' => $report?->status === ReportStatus::PerluRevisi ? $report : null,
            'existing' => $this->existingFiles(),
            'resultOptions' => collect(ResultType::cases())->mapWithKeys(fn (ResultType $r): array => [$r->value => $r->label()])->all(),
            'preview' => $preview,
            // Tingkat and penyelenggara weights of the active version, shown before a result is picked.
            'base' => $preview ?? $this->previewBreakdown($competition, ResultType::Peserta),
            'resultLabel' => $this->hasil !== '' ? ResultType::tryFrom($this->hasil)?->label() : null,
            'abstractWords' => SubmitReport::wordCount($this->abstrak),
            'backUrl' => route('siswa.lomba-saya.show', ['registration' => $this->registrationId]),
            ...$this->photoMessages(),
        ];

        $subtitle = collect([
            $competition->name,
            $competition->bidang?->label(),
            $registration->type === RegistrationType::Tim ? 'Tim '.$registration->active_members_count.' orang' : 'Individu',
        ])->filter()->implode(' · ');

        return view('livewire.siswa.lomba-saya.lapor-hasil', $data)
            ->layout('layouts::mobile', [
                'title' => 'Laporkan hasil',
                'subtitle' => $subtitle,
                'back' => $data['backUrl'],
                'backLabel' => 'Kembali ke Lomba saya',
            ])
            ->title('Laporkan hasil');
    }

    /** @return list<string> uploaded (Livewire) file rules; the Action re-checks with StoreUpload */
    private function fileRules(string $field): array
    {
        $rules = SubmitReport::rules($field);

        return ['file', 'max:'.$rules->maxKilobytes(), 'extensions:'.implode(',', $rules->extensions())];
    }

    /**
     * A file chosen for a single-file field is checked at once. A refused file does not stay chosen: the drop zone comes
     * back with the reason under it, never a "Siap dikirim" row for a file that cannot be sent (the other messages of the
     * form stay, validateOnly() keeps them).
     */
    private function checkChosenFile(string $field): void
    {
        try {
            $this->validateOnly($field);
        } catch (ValidationException $e) {
            $this->{$field} = null;

            throw $e;
        }
    }

    /**
     * The messages of the photos, shown under the photo list in a live region (r3 review: a screen reader heard nothing
     * after a pick): every message of a pick (one per reason, updatedFotoBaru()) and of a send, and FOTO_PICK_NOT_ADDED
     * after a 422 of the picker's upload. `fotoInvalid` marks the picker while such a message stands, unless
     * <x-sth.file-drop> already marks it for its own message (fotoBaru, fotoBaru.*), which would print the attribute twice.
     *
     * @return array{fotoMessages: list<string>, fotoInvalid: bool}
     */
    private function photoMessages(): array
    {
        $bag = $this->getErrorBag();
        $pickerMessages = collect([$bag->get('fotoBaru'), $bag->get('fotoBaru.*')])->flatten();
        $sendPhoto = $bag->first('foto.*');

        $messages = [
            ...array_values($bag->get('foto')),
            ...($sendPhoto !== '' ? [$sendPhoto] : []),
        ];

        if ($pickerMessages->contains(fn (string $message): bool => $message !== self::FOTO_UPLOAD_FAILED)) {
            $messages[] = self::FOTO_PICK_NOT_ADDED;
        }

        return [
            'fotoMessages' => $messages,
            'fotoInvalid' => ($bag->has('foto') || $sendPhoto !== '') && $pickerMessages->isEmpty(),
        ];
    }

    /** @param list<string> $names "Ukuran foto maksimal 5 MB. Tidak ditambahkan: foto-panggung.jpg." */
    private static function notAdded(string $reason, array $names): string
    {
        return $reason.' Tidak ditambahkan: '.implode(', ', $names).'.';
    }

    /**
     * The ketua sent the report in another tab since this form was drawn (third browser check): ReportPolicy::create
     * refuses "Kirim laporan", and the generic error kept the old form with its files. Answered as Lomba saya answers its
     * own button (Index::reportSentElsewhere()): a warning toast, and the registration's page drawn again from its state
     * (inline or full page), so the old form and its files are gone. Any other refusal keeps the generic error.
     */
    private function sentElsewhere(Registration $registration): bool
    {
        $report = $registration->report;
        $leader = (string) $registration->leader_student_id === (string) $this->user()->student()->value('id');

        if (! $leader || $report === null || $report->status === ReportStatus::PerluRevisi || ! Gate::allows('view', $registration)) {
            return false;
        }

        session()->flash('toast', ['tone' => 'warning', 'message' => self::MSG_SENT_ELSEWHERE]);
        $this->redirectRoute('siswa.lomba-saya.show', ['registration' => $this->registrationId], navigate: true);

        return true;
    }

    private function isRiset(): bool
    {
        return $this->registration->competition->bidang === Bidang::Riset;
    }

    private function formId(): string
    {
        return $this->inline ? self::FORM_INLINE : self::FORM_PAGE;
    }

    /**
     * Did Livewire's upload answer carry validation messages (the 422 of its temporary upload)? The same test as
     * WithFileUploads::_uploadErrored(): JSON with a non-empty "errors".
     */
    private static function uploadAnswerHasMessages(?string $errorsInJson): bool
    {
        if ($errorsInJson === null) {
            return false;
        }

        $answer = json_decode($errorsInJson, true);

        return is_array($answer) && ! empty($answer['errors']);
    }

    /** @return list<string> kinds already sent in an earlier (perlu revisi) report */
    private function existingKinds(): array
    {
        return array_keys($this->existingFiles());
    }

    /** @return array<string, list<string>> kind => original names of the files sent before (revision only) */
    private function existingFiles(): array
    {
        $report = $this->registration->report;

        if ($report === null || $report->status !== ReportStatus::PerluRevisi) {
            return [];
        }

        return $report->files
            ->groupBy(fn (ReportFile $f): string => $f->kind->value)
            ->map(fn ($files) => $files->map(fn (ReportFile $f): string => (string) $f->file?->original_name)->values()->all())
            ->all();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
