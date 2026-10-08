<?php

declare(strict_types=1);

namespace App\Livewire\Siswa\LombaSaya;

use App\Actions\Reports\SubmitReport;
use App\Actions\Reports\SubmitReportData;
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
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Laporkan hasil (UI-MAP #13, boards Lapor-Hasil-Mobile + the inline form of Lomba-Saya). One responsive
 * component: full page `siswa.lapor` (layouts::mobile) and embedded in Siswa\LombaSaya\Index (inline).
 * Live point preview from the active weight version ("final setelah diverifikasi"); SubmitReport does
 * every real check (ReportPolicy::create, deadline flag, StoreUpload). After sending → Lomba saya detail.
 */
final class LaporHasil extends Component
{
    use ReportHelpers;
    use WithFileUploads;

    private const FILE_FIELDS = ['sertifikat', 'pengumuman', 'laporan', 'artikel', 'poster'];

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

    public function mount(Registration $registration, bool $inline = false): void
    {
        $this->inline = $inline;
        $this->registrationId = (string) $registration->getKey();

        if (! Gate::allows('create', [Report::class, $registration])) {
            abort_unless(Gate::allows('view', $registration), 404);

            session()->flash('toast', ['tone' => 'info', 'message' => 'Laporan untuk lomba ini tidak bisa dikirim sekarang.']);
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
            $this->validateOnly($field);
        }
    }

    public function updatedFotoBaru(): void
    {
        $new = is_array($this->fotoBaru) ? $this->fotoBaru : array_filter([$this->fotoBaru]);
        $this->validate(['fotoBaru.*' => $this->fileRules('foto')], $this->messages(), ['fotoBaru.*' => 'foto']);

        $this->foto = array_slice([...$this->foto, ...array_values($new)], 0, SubmitReport::MAX_PHOTOS);
        $this->fotoBaru = [];
        $this->resetValidation('foto');
    }

    public function removeFoto(int $index): void
    {
        unset($this->foto[$index]);
        $this->foto = array_values($this->foto);
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
            $this->dispatch('sth-toast', message: 'Laporan untuk lomba ini tidak bisa dikirim sekarang.', tone: 'error');

            return;
        }

        $this->validate();

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

        session()->flash('toast', ['tone' => 'success', 'message' => 'Laporan terkirim. Kesiswaan akan memverifikasi laporanmu.']);
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
            'competition' => $competition,
            'isRiset' => $this->isRiset(),
            'isTeam' => $registration->type === RegistrationType::Tim,
            'deadline' => $this->deadlineInfo($competition),
            'marksLate' => SubmitReport::marksLate($competition, $report),
            'revision' => $report?->status === ReportStatus::PerluRevisi ? $report : null,
            'existing' => $this->existingFiles(),
            'resultOptions' => collect(ResultType::cases())->mapWithKeys(fn (ResultType $r): array => [$r->value => $r->label()])->all(),
            'preview' => $preview,
            // Tingkat and penyelenggara weights of the active version, shown before a result is picked.
            'base' => $preview ?? $this->previewBreakdown($competition, ResultType::Peserta),
            'resultLabel' => $this->hasil !== '' ? ResultType::tryFrom($this->hasil)?->label() : null,
            'abstractWords' => SubmitReport::wordCount($this->abstrak),
            'backUrl' => route('siswa.lomba-saya.show', ['registration' => $this->registrationId]),
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

    private function isRiset(): bool
    {
        return $this->registration->competition->bidang === Bidang::Riset;
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
