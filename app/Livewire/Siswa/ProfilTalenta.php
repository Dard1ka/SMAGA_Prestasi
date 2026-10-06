<?php

declare(strict_types=1);

namespace App\Livewire\Siswa;

use App\Actions\Talent\ApplyTalent;
use App\Actions\Talent\CancelTalentApplication;
use App\Actions\Talent\RequestTalentReview;
use App\Actions\Talent\SaveTalentProfile;
use App\Actions\Talent\TalentNote;
use App\Enums\RegistrationStatus;
use App\Enums\TalentStatus;
use App\Models\Registration;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\TalentBranch;
use App\Models\TalentProfile;
use App\Models\TalentType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Profil talenta (UI-MAP #15, board Profil-Talenta; replaces the school's Google Form, D-21, TALENT-FORM.md):
 * talent choices with their pipeline status, "Tambah talenta" (jenis → cabang from the DB, OLPAIN without
 * cabang, optional bukti), "Batalkan" while terdaftar (+ Urungkan), and the Riwayat talenta form.
 * Every ID sent by the browser is re-read from the DB and checked for ownership inside the Actions.
 *
 * Lead, 2026-10-04: each choice shows Kesiswaan's "catatan untuk siswa" of the latest decision; a "tidak
 * lolos" choice has "Minta ditinjau ulang" (optional message ≤ 500 characters, one open request per rejection,
 * RequestTalentReview) instead of a second application for the same jenis + cabang.
 */
#[Title('Profil talenta')]
#[Layout('layouts::app', ['subtitle' => 'Pilih bidang yang ingin kamu kembangkan. Boleh lebih dari satu.'])]
final class ProfilTalenta extends Component
{
    use WithFileUploads;

    /** id of the "Minta ditinjau ulang" dialog (<x-sth.modal>). */
    public const REVIEW_DIALOG = 'tinjau-ulang';

    /** Review requests one student may send per hour (on top of "one open request per rejection"). */
    public const REVIEWS_PER_HOUR = 10;

    public string $jenis = '';

    public string $cabang = '';

    /** @var TemporaryUploadedFile|null */
    public $bukti = null;

    /** Last cancelled choice, for "Urungkan": ['type' => ulid, 'branch' => ?ulid, 'title' => string]. */
    #[Locked]
    public ?array $undo = null;

    /** Rejected choice waiting in the "Minta ditinjau ulang" dialog. */
    #[Locked]
    public ?string $tinjauId = null;

    public string $pesanTinjau = '';

    /** @var list<string> */
    public array $minat = [];

    public string $minatBaru = '';

    public string $dikuasai = '';

    public string $organisasi = '';

    public string $smp = '';

    /** @var TemporaryUploadedFile|null picker; appended to */
    public $sertifikatPilih = null;

    /** @var list<TemporaryUploadedFile> */
    public array $sertifikatBaru = [];

    /** @var list<string> file ULIDs to detach on save */
    public array $sertifikatHapus = [];

    public bool $tersimpan = false;

    public function mount(): void
    {
        Gate::authorize('viewAny', TalentApplication::class);
        abort_if($this->student() === null, 403);

        $profile = $this->profile();

        if ($profile !== null) {
            $this->minat = array_values(array_map('strval', (array) $profile->interests));
            $this->dikuasai = (string) $profile->skills;
            $this->organisasi = (string) $profile->organizations;
            $this->smp = (string) $profile->junior_high_achievements;
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['minat', 'minatBaru', 'dikuasai', 'organisasi', 'smp', 'sertifikatBaru', 'sertifikatHapus'], true)) {
            $this->tersimpan = false;
        }
    }

    // Tambah talenta --------------------------------------------------------------------------

    public function pilihJenis(string $typeId): void
    {
        if (! TalentType::query()->whereKey($typeId)->exists()) {
            return;
        }

        $this->jenis = $typeId;
        $this->cabang = '';
        $this->resetValidation(['jenis', 'cabang']);
    }

    public function pilihCabang(string $branchId): void
    {
        if ($this->jenis === '' || ! TalentBranch::query()->whereKey($branchId)->where('talent_type_id', $this->jenis)->active()->exists()) {
            return;
        }

        $this->cabang = $branchId;
        $this->resetValidation('cabang');
    }

    public function hapusBukti(): void
    {
        $this->bukti = null;
        $this->resetValidation('bukti');
    }

    public function updatedBukti(): void
    {
        $this->validateOnly('bukti', ['bukti' => ['nullable', 'file', 'max:5120', 'extensions:pdf,jpg,jpeg,png,webp']], [
            'bukti.max' => 'Ukuran file maksimal 5 MB.',
            'bukti.extensions' => 'Pakai PDF, JPG, atau PNG.',
        ]);
    }

    public function daftar(ApplyTalent $apply): void
    {
        Gate::authorize('create', TalentApplication::class);

        $type = TalentType::query()->find($this->jenis);

        if ($type === null) {
            $this->addError('jenis', 'Pilih jenis talenta dulu.');

            return;
        }

        $branch = $this->cabang !== ''
            ? TalentBranch::query()->whereKey($this->cabang)->where('talent_type_id', $type->getKey())->first()
            : null;

        $apply->handle($this->user(), $type, $branch, $this->bukti);

        $title = $this->titleOf($type, $branch);
        $this->reset(['jenis', 'cabang', 'bukti', 'undo']);
        unset($this->applications);

        $this->dispatch('sth-toast', message: 'Terdaftar di penjaringan '.$title.'.', tone: 'success');
    }

    public function batalkan(string $applicationId, CancelTalentApplication $cancel): void
    {
        $application = $this->ownApplication($applicationId);

        $cancel->handle($this->user(), $application);

        $this->undo = [
            'type' => (string) $application->talent_type_id,
            'branch' => $application->talent_branch_id !== null ? (string) $application->talent_branch_id : null,
            'title' => $this->titleOf($application->talentType, $application->talentBranch),
        ];
        unset($this->applications);

        $this->dispatch('sth-toast', message: $this->undo['title'].' dibatalkan.', tone: 'info');
    }

    public function urungkan(ApplyTalent $apply): void
    {
        if ($this->undo === null) {
            return;
        }

        $type = TalentType::query()->findOrFail($this->undo['type']);
        $branch = $this->undo['branch'] !== null ? TalentBranch::query()->findOrFail($this->undo['branch']) : null;

        $apply->handle($this->user(), $type, $branch);

        $title = $this->undo['title'];
        $this->undo = null;
        unset($this->applications);

        $this->dispatch('sth-toast', message: $title.' dipulihkan.', tone: 'success');
    }

    // Minta ditinjau ulang --------------------------------------------------------------------

    /** Opens the dialog for one of the student's own rejected choices (not yours → 404, not allowed → 403). */
    public function mintaTinjau(string $applicationId): void
    {
        $application = $this->ownApplication($applicationId);
        Gate::authorize('requestReview', $application);

        $this->tinjauId = (string) $application->getKey();
        $this->pesanTinjau = '';
        $this->resetValidation('pesanTinjau');
        $this->dispatch('sth-modal-open', id: self::REVIEW_DIALOG);
    }

    public function kirimTinjau(RequestTalentReview $request): void
    {
        $application = $this->ownApplication((string) $this->tinjauId);

        // Sent from another tab, or Kesiswaan restored the choice meanwhile: say so instead of a 403 page.
        if (! Gate::allows('requestReview', $application)) {
            $this->closeReviewDialog();
            unset($this->applications);
            $this->dispatch('sth-toast', message: 'Permintaan sudah terkirim atau pilihan ini sudah dibuka kembali.', tone: 'error');

            return;
        }

        $this->resetValidation('pesanTinjau');
        $key = 'talenta-tinjau:'.$this->user()->getKey();

        if (RateLimiter::tooManyAttempts($key, self::REVIEWS_PER_HOUR)) {
            $this->addError('pesanTinjau', 'Terlalu sering. Coba lagi beberapa menit lagi.');

            return;
        }

        try {
            $request->handle($this->user(), $application, $this->pesanTinjau);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            if (isset($errors['pesan'])) {
                $this->addError('pesanTinjau', (string) ($errors['pesan'][0] ?? 'Periksa pesanmu.'));

                return;
            }

            $this->closeReviewDialog();
            unset($this->applications);
            $this->dispatch('sth-toast', message: (string) (collect($errors)->flatten()->first() ?? 'Permintaan tidak bisa dikirim.'), tone: 'error');

            return;
        }

        RateLimiter::hit($key, 3600);

        $title = $this->titleOf($application->talentType, $application->talentBranch);
        $this->closeReviewDialog();
        unset($this->applications);
        $this->dispatch('sth-toast', message: 'Permintaan tinjau ulang '.$title.' terkirim ke Kesiswaan.', tone: 'success');
    }

    // Riwayat talenta -------------------------------------------------------------------------

    public function tambahMinat(): void
    {
        $value = trim(preg_replace('/\s+/u', ' ', $this->minatBaru) ?? '');

        if ($value === '') {
            return;
        }

        if (count($this->minat) >= TalentProfile::MAX_INTERESTS) {
            $this->addError('minat', 'Maksimal '.TalentProfile::MAX_INTERESTS.' minat.');

            return;
        }

        if (mb_strlen($value) > SaveTalentProfile::MAX_INTEREST_LENGTH) {
            $this->addError('minat', 'Setiap minat maksimal '.SaveTalentProfile::MAX_INTEREST_LENGTH.' karakter.');

            return;
        }

        if (! in_array(mb_strtolower($value), array_map(mb_strtolower(...), $this->minat), true)) {
            $this->minat[] = $value;
        }

        $this->minatBaru = '';
        $this->tersimpan = false;
        $this->resetValidation('minat');
    }

    public function hapusMinat(int $index): void
    {
        unset($this->minat[$index]);
        $this->minat = array_values($this->minat);
        $this->tersimpan = false;
    }

    public function updatedSertifikatPilih(): void
    {
        $this->validateOnly('sertifikatPilih', ['sertifikatPilih' => ['file', 'max:5120', 'extensions:pdf,jpg,jpeg,png,webp']], [
            'sertifikatPilih.max' => 'Ukuran file maksimal 5 MB.',
            'sertifikatPilih.extensions' => 'Pakai PDF, JPG, atau PNG.',
        ]);

        if ($this->certificateSlotsLeft() <= 0) {
            $this->addError('sertifikat', 'Maksimal '.TalentProfile::MAX_CERTIFICATES.' sertifikat pendukung.');
            $this->sertifikatPilih = null;

            return;
        }

        $this->sertifikatBaru[] = $this->sertifikatPilih;
        $this->sertifikatPilih = null;
        $this->tersimpan = false;
    }

    public function hapusSertifikatBaru(int $index): void
    {
        unset($this->sertifikatBaru[$index]);
        $this->sertifikatBaru = array_values($this->sertifikatBaru);
    }

    public function hapusSertifikat(string $fileId): void
    {
        $profile = $this->profile();

        if ($profile !== null && $profile->certificates()->whereKey($fileId)->exists() && ! in_array($fileId, $this->sertifikatHapus, true)) {
            $this->sertifikatHapus[] = $fileId;
            $this->tersimpan = false;
        }
    }

    public function simpan(SaveTalentProfile $save): void
    {
        $save->handle(
            $this->user(),
            [
                'interests' => $this->minat,
                'skills' => $this->dikuasai,
                'organizations' => $this->organisasi,
                'junior_high_achievements' => $this->smp,
            ],
            array_values($this->sertifikatBaru),
            $this->sertifikatHapus,
        );

        $this->sertifikatBaru = [];
        $this->sertifikatHapus = [];
        $this->tersimpan = true;
        $this->dispatch('sth-toast', message: 'Riwayat talenta tersimpan.', tone: 'success');
    }

    // Data ------------------------------------------------------------------------------------

    /** @return Collection<int, TalentApplication> */
    #[Computed]
    public function applications(): Collection
    {
        return TalentApplication::query()
            ->where('student_id', $this->student()->getKey())
            ->with(['talentType', 'talentBranch', 'evidenceFile', 'mentor.staffProfile'])
            ->orderBy('created_at')
            ->get();
    }

    public function render(): View
    {
        $student = $this->student();
        $student->loadMissing(['user', 'classroom']);
        $applications = $this->applications;
        $types = TalentType::query()->ordered()->with(['branches' => fn ($q) => $q->active()])->get();
        $selectedType = $types->firstWhere('id', $this->jenis);
        $choiceKey = fn (TalentApplication $a): string => $a->talent_type_id.'|'.($a->talent_branch_id ?? '');
        $taken = $applications->map($choiceKey)->all();
        $rejected = $applications->filter(fn (TalentApplication $a): bool => $a->status === TalentStatus::TidakLolos)->map($choiceKey)->all();
        $profile = $student->talentProfile()->with('certificates')->first();

        $isTaken = fn (?string $branchId): bool => $selectedType !== null
            && in_array($selectedType->getKey().'|'.($branchId ?? ''), $taken, true);
        $isRejected = fn (?string $branchId): bool => $selectedType !== null
            && in_array($selectedType->getKey().'|'.($branchId ?? ''), $rejected, true);

        $helper = match (true) {
            $selectedType === null => 'Pilih jenis talenta dulu.',
            $selectedType->requiresBranch() && $this->cabang === '' => 'Pilih satu cabang dulu.',
            // A rejected choice is not applied for twice: the student asks for a second look (ApplyTalent).
            $isRejected($selectedType->requiresBranch() ? $this->cabang : null) => 'Pilihan ini belum lolos. Pakai "Minta ditinjau ulang" di daftar talenta pilihanmu.',
            $isTaken($selectedType->requiresBranch() ? $this->cabang : null) => 'Sudah dipilih. Pilih cabang lain.',
            default => null,
        };

        $reviewing = $this->tinjauId !== null ? $applications->firstWhere('id', $this->tinjauId) : null;

        return view('livewire.siswa.profil-talenta', [
            'identity' => [
                'name' => $student->user->name,
                'meta' => trim(($student->classroom?->name ?? '').' · NIS '.$student->nis, ' ·'),
            ],
            'rows' => $applications->map(fn (TalentApplication $a): array => [
                'id' => (string) $a->getKey(),
                'title' => $this->titleOf($a->talentType, $a->talentBranch),
                'bidang' => $a->talentType?->bidang,
                'status' => $a->status,
                'info' => $this->infoOf($a),
                'step' => $a->status->step(),
                'evidence' => $a->evidenceFile?->original_name,
                'evidenceUrl' => $a->evidence_file_id !== null && Route::has('files.show') ? route('files.show', $a->evidence_file_id) : null,
                'canCancel' => Gate::allows('cancel', $a),
                // Kesiswaan's note for the student of the latest decision (never the note for the guru pembina).
                'note' => $a->student_note,
                'rejected' => $a->status === TalentStatus::TidakLolos,
                'canRequestReview' => $a->status === TalentStatus::TidakLolos && Gate::allows('requestReview', $a),
                'reviewRequested' => $a->hasOpenReviewRequest(),
            ])->all(),
            'types' => $types,
            'selectedType' => $selectedType,
            'branches' => $selectedType?->branches->map(fn (TalentBranch $b): array => [
                'id' => (string) $b->getKey(),
                'name' => $b->name,
                'team' => $b->isTeam() ? 'Tim '.$b->team_size : null,
                'taken' => $isTaken((string) $b->getKey()),
                'rejected' => $isRejected((string) $b->getKey()),
            ])->all() ?? [],
            'helper' => $helper,
            // The dialog is only in the page while there is a rejected choice to ask about.
            'hasRejected' => $rejected !== [],
            'reviewing' => $reviewing !== null ? $this->titleOf($reviewing->talentType, $reviewing->talentBranch) : null,
            'reviewMax' => TalentNote::MAX_REVIEW_MESSAGE,
            'year' => CarbonImmutable::now()->year,
            'lombaCount' => Registration::query()
                ->whereHas('members', fn (Builder $m) => $m->where('student_id', $student->getKey())->whereNull('declined_at'))
                ->whereNotIn('status', [RegistrationStatus::Dibatalkan, RegistrationStatus::Ditolak])
                ->count(),
            'certificates' => ($profile?->certificates ?? collect())
                ->reject(fn ($file): bool => in_array((string) $file->getKey(), $this->sertifikatHapus, true))
                ->map(fn ($file): array => [
                    'id' => (string) $file->getKey(),
                    'name' => $file->original_name,
                    'size' => $file->size,
                    'url' => Route::has('files.show') ? route('files.show', $file) : null,
                ])->values()->all(),
            'slotsLeft' => $this->certificateSlotsLeft(),
            'portfolioUrl' => route('siswa.portofolio'),
        ]);
    }

    private function certificateSlotsLeft(): int
    {
        $current = $this->profile()?->certificates()->count() ?? 0;

        return TalentProfile::MAX_CERTIFICATES - ($current - count($this->sertifikatHapus)) - count($this->sertifikatBaru);
    }

    private function titleOf(?TalentType $type, ?TalentBranch $branch): string
    {
        return trim(($type?->code->label() ?? 'Talenta').($branch !== null ? ' · '.$branch->name : ''));
    }

    private function infoOf(TalentApplication $application): string
    {
        return match ($application->status) {
            TalentStatus::Terdaftar => 'Menunggu pemetaan Kesiswaan',
            TalentStatus::Pemetaan => 'Sedang dipetakan Kesiswaan',
            TalentStatus::Seleksi => $application->assessment_on !== null
                ? 'Seleksi/asesmen '.$application->assessment_on->locale('id')->translatedFormat('j M Y')
                : 'Menunggu jadwal seleksi',
            TalentStatus::Pembinaan => $application->mentor !== null
                ? 'Pembina '.trim(($application->mentor->staffProfile?->honorific ? $application->mentor->staffProfile->honorific.' ' : '').$application->mentor->name)
                : 'Peserta pembinaan',
            TalentStatus::TidakLolos => $application->review_requested_at !== null
                ? 'Belum lolos · permintaan tinjau ulang terkirim '.$application->review_requested_at->locale('id')->translatedFormat('j M Y')
                : 'Belum lolos tahun ini',
        };
    }

    /** The student's own choice, re-read from the DB; anyone else's (or an unknown id) is a 404. */
    private function ownApplication(string $applicationId): TalentApplication
    {
        $application = TalentApplication::query()
            ->where('student_id', $this->student()->getKey())
            ->with(['talentType', 'talentBranch'])
            ->find($applicationId);

        abort_if($application === null, 404);

        return $application;
    }

    private function closeReviewDialog(): void
    {
        $this->tinjauId = null;
        $this->pesanTinjau = '';
        $this->dispatch('sth-modal-close', id: self::REVIEW_DIALOG);
    }

    /** Always read fresh (the Riwayat form may just have created it). */
    private function profile(): ?TalentProfile
    {
        return TalentProfile::query()->where('student_id', $this->student()->getKey())->first();
    }

    private function student(): ?Student
    {
        return $this->user()->student;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
