<?php

declare(strict_types=1);

namespace App\Livewire\Siswa;

use App\Actions\Talent\ApplyTalent;
use App\Actions\Talent\CancelTalentApplication;
use App\Actions\Talent\SaveTalentProfile;
use App\Actions\Talent\TalentOtherText;
use App\Enums\RegistrationStatus;
use App\Http\Controllers\FileController;
use App\Http\Middleware\EnsureTalentChosen;
use App\Livewire\Akun\Profil;
use App\Models\Registration;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\TalentBranch;
use App\Models\TalentProfile;
use App\Models\TalentType;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Talenta saya, a tab of Profil saya since D-153 (before: the menu item "Profil talenta"; UI-MAP #15, board
 * Profil-Talenta; replaces the school's Google Form, D-21, TALENT-FORM.md):
 * the student's talent choices, "Tambah talenta" (jenis → cabang from the DB, OLPAIN and "Lainnya" without cabang,
 * "Sebutkan" for the jenis or a cabang "Lainnya" (D-143), optional bukti), "Hapus" at any time (+ Urungkan), and the
 * Riwayat talenta form. Every ID sent by the browser is re-read from the DB and checked for ownership inside the
 * Actions.
 *
 * D-142: no stages, no review request, no decision note. A choice shows its guru pembina when Kesiswaan named one,
 * else the day it was added.
 */
// D-153: the tab "Talenta saya" of Profil saya, so the page carries that title and the tabs of Akun\Profil::tabs().
#[Title('Profil saya')]
#[Layout('layouts::app', ['subtitle' => 'Pilih bidang yang ingin kamu kembangkan. Boleh lebih dari satu.'])]
final class ProfilTalenta extends Component
{
    use WithFileUploads;

    public string $jenis = '';

    public string $cabang = '';

    /** "Sebutkan": the student's own talent for the jenis or a cabang "Lainnya" (D-143). */
    public string $lainnya = '';

    /** @var TemporaryUploadedFile|null */
    public $bukti = null;

    /** Last deleted choice, for "Urungkan": ['type' => ulid, 'branch' => ?ulid, 'other' => ?string, 'title' => string]. */
    #[Locked]
    public ?array $undo = null;

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
        $this->lainnya = '';
        $this->resetValidation(['jenis', 'cabang', 'lainnya']);
    }

    public function pilihCabang(string $branchId): void
    {
        if ($this->jenis === '' || ! TalentBranch::query()->whereKey($branchId)->where('talent_type_id', $this->jenis)->active()->exists()) {
            return;
        }

        $this->cabang = $branchId;
        $this->lainnya = '';
        $this->resetValidation(['cabang', 'lainnya']);
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
        // The refusals of the previous try go; this try sets its own.
        $this->resetValidation(['jenis', 'cabang', 'lainnya']);

        $type = TalentType::query()->find($this->jenis);

        if ($type === null) {
            $this->addError('jenis', 'Pilih jenis talenta dulu.');

            return;
        }

        $branch = $this->cabang !== ''
            ? TalentBranch::query()->whereKey($this->cabang)->where('talent_type_id', $type->getKey())->first()
            : null;

        $gated = EnsureTalentChosen::applies($this->user());
        $application = $apply->handle($this->user(), $type, $branch, $this->bukti, $this->lainnya);

        $this->reset(['jenis', 'cabang', 'lainnya', 'bukti', 'undo']);
        unset($this->applications);

        // FS1 fix wave: the first talent lifts the gate (D-144); go back to the page it held, if any (a team invitation
        // link from a mail, a lomba). Only a path of this app, as the middleware stored it.
        $back = $gated ? session()->pull(EnsureTalentChosen::INTENDED) : null;

        if (is_string($back) && str_starts_with($back, '/') && ! str_starts_with($back, '//')) {
            session()->flash('toast', $application->title().' ditambahkan.');
            $this->redirect(url($back), navigate: true);

            return;
        }

        $this->dispatch('sth-toast', message: $application->title().' ditambahkan.', tone: 'success');
    }

    public function batalkan(string $applicationId, CancelTalentApplication $cancel): void
    {
        $application = $this->ownApplication($applicationId, withTrashed: true);

        // QA9: a stale tab (deleted in another tab) gets a toast, not an error page.
        if ($application->trashed()) {
            $this->staleChoice($application->title().' sudah kamu hapus.');

            return;
        }

        // D-142: any own choice, whatever its old status; nobody is notified.
        $cancel->handle($this->user(), $application);

        $this->undo = [
            'type' => (string) $application->talent_type_id,
            'branch' => $application->talent_branch_id !== null ? (string) $application->talent_branch_id : null,
            'other' => $application->other_text,
            'title' => $application->title(),
        ];
        unset($this->applications);

        $this->dispatch('sth-toast', message: $this->undo['title'].' dihapus.', tone: 'info');
    }

    public function urungkan(ApplyTalent $apply): void
    {
        if ($this->undo === null) {
            return;
        }

        $type = TalentType::query()->findOrFail($this->undo['type']);
        $branch = $this->undo['branch'] !== null ? TalentBranch::query()->findOrFail($this->undo['branch']) : null;

        $apply->handle($this->user(), $type, $branch, null, $this->undo['other'] ?? null);

        $title = $this->undo['title'];
        $this->undo = null;
        unset($this->applications);

        $this->dispatch('sth-toast', message: $title.' dipulihkan.', tone: 'success');
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
        $selectedBranch = $selectedType !== null && $this->cabang !== '' ? $selectedType->branches->firstWhere('id', $this->cabang) : null;
        $needsOther = $selectedType !== null && ApplyTalent::needsOtherText($selectedType, $selectedBranch);
        // Choices without a text of their own; a "Lainnya" cabang takes any number of texts (D-143), so it is never "taken".
        $taken = $applications
            ->filter(fn (TalentApplication $a): bool => $a->other_text === null)
            ->map(fn (TalentApplication $a): string => $a->talent_type_id.'|'.($a->talent_branch_id ?? ''))
            ->all();
        $profile = $student->talentProfile()->with('certificates')->first();

        $isTaken = fn (?string $branchId): bool => $selectedType !== null
            && in_array($selectedType->getKey().'|'.($branchId ?? ''), $taken, true);

        // `$blocked` disables the button; the "Sebutkan" hint does not: the typed text reaches the server with the click.
        $blocked = match (true) {
            $selectedType === null => 'Pilih jenis talenta dulu.',
            $selectedType->requiresBranch() && $this->cabang === '' => 'Pilih satu cabang dulu.',
            ! $needsOther && $isTaken($selectedType->requiresBranch() ? $this->cabang : null) => ApplyTalent::DUPLICATE_MESSAGE,
            default => null,
        };
        $helper = $blocked ?? ($needsOther && TalentOtherText::clean($this->lainnya) === null ? TalentOtherText::REQUIRED_MESSAGE : null);

        return view('livewire.siswa.profil-talenta', [
            // D-153: Data diri · Talenta saya · Keamanan akun.
            'tabs' => Profil::tabs($student->user),
            'identity' => [
                'name' => $student->user->name,
                'meta' => trim(($student->classroom?->name ?? '').' · NISN '.$student->nisn, ' ·'),
            ],
            'rows' => $applications->map(fn (TalentApplication $a): array => [
                'id' => (string) $a->getKey(),
                'title' => $a->title(),
                'bidang' => $a->talentType?->bidang,
                'info' => $this->infoOf($a),
                'evidence' => $a->evidenceFile?->original_name,
                'evidenceUrl' => $a->evidence_file_id !== null && Route::has('files.show') ? route('files.show', $a->evidence_file_id) : null,
                // M3-C: the click opens the preview popup; the href stays the download.
                'evidencePreview' => $a->evidenceFile !== null ? FileController::previewUrl($a->evidenceFile) : null,
                'evidencePreviewType' => $a->evidenceFile !== null ? FileController::previewType($a->evidenceFile) : null,
                'canCancel' => Gate::allows('cancel', $a),
            ])->all(),
            'types' => $types,
            'selectedType' => $selectedType,
            'branches' => $selectedType?->branches->map(fn (TalentBranch $b): array => [
                'id' => (string) $b->getKey(),
                'name' => $b->name,
                'team' => $b->isTeam() ? 'Tim '.$b->team_size : null,
                'taken' => ! $b->isOther() && $isTaken((string) $b->getKey()),
            ])->all() ?? [],
            'needsOther' => $needsOther,
            'otherMax' => TalentOtherText::MAX,
            'helper' => $helper,
            'blocked' => $blocked !== null,
            // QA10: joined (confirmed) registrations only, like Portofolio "Lomba diikuti"; an open invitation is not one.
            'lombaCount' => Registration::query()
                ->whereHas('members', fn (Builder $m) => $m->where('student_id', $student->getKey())->whereNotNull('confirmed_at')->whereNull('declined_at'))
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
            // D-144: a kelas X student without a talent is held here (EnsureTalentChosen) and reads why.
            'gate' => EnsureTalentChosen::applies($this->user()),
        ]);
    }

    private function certificateSlotsLeft(): int
    {
        $current = $this->profile()?->certificates()->count() ?? 0;

        return TalentProfile::MAX_CERTIFICATES - ($current - count($this->sertifikatHapus)) - count($this->sertifikatBaru);
    }

    /** The guru pembina when Kesiswaan named one (D-142), else the day the choice was added. */
    private function infoOf(TalentApplication $application): string
    {
        $mentor = $application->mentor;

        if ($mentor !== null) {
            $honorific = $mentor->staffProfile?->honorific;

            return 'Guru pembina '.trim(($honorific ? $honorific.' ' : '').$mentor->name);
        }

        return 'Ditambahkan '.$application->created_at?->locale('id')->translatedFormat('j M Y');
    }

    /**
     * The student's own choice, re-read from the DB; anyone else's (or an unknown id) is a 404. `$withTrashed`: a
     * choice the student cancelled (in another tab) is found too, so the screen can say so (QA9).
     */
    private function ownApplication(string $applicationId, bool $withTrashed = false): TalentApplication
    {
        $application = TalentApplication::query()
            ->when($withTrashed, fn (Builder $q) => $q->withTrashed())
            ->where('student_id', $this->student()->getKey())
            ->with(['talentType', 'talentBranch'])
            ->find($applicationId);

        abort_if($application === null, 404);

        return $application;
    }

    /** A stale tab (QA9): the choice changed since the list was drawn. Say how it is now and draw the list again. */
    private function staleChoice(string $message): void
    {
        unset($this->applications);
        $this->dispatch('sth-toast', message: $message, tone: 'warning');
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
