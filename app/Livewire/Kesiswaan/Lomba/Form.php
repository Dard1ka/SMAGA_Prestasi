<?php

declare(strict_types=1);

namespace App\Livewire\Kesiswaan\Lomba;

use App\Actions\Competitions\CompetitionDates;
use App\Actions\Competitions\CreateCompetition;
use App\Actions\Competitions\UpdateCompetition;
use App\Actions\Competitions\VerifyCompetition;
use App\Domain\Points\PointCalculator;
use App\Domain\Points\PointFormatter;
use App\Domain\Points\WeightTable;
use App\Enums\Bidang;
use App\Enums\CompetitionStatus;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\Level;
use App\Enums\OrganizerType;
use App\Enums\Participation;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\WeightCategory;
use App\Models\Competition;
use App\Models\User;
use App\Models\WeightVersion;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Drawer form of Kelola lomba (child of Lomba\Index): "Tambah lomba" (CreateCompetition → antrean, D-27),
 * "Ubah lomba" (UpdateCompetition) and "Periksa usulan" (UpdateCompetition + VerifyCompetition). All rules
 * live in the Actions; this component only maps their errors onto the `form.*` fields. The rumus preview
 * ("Jika Juara I: …") comes from App\Domain\Points with the active weight version.
 */
class Form extends Component
{
    use WithFileUploads;

    public const DIALOG = 'lomba-form';

    /** Keys of $form = CreateCompetition::rules() keys. */
    private const FIELDS = [
        'name', 'description', 'bidang', 'organizer_name', 'organizer_type', 'weight_category', 'event_status', 'level', 'mode',
        'registration_opens_at', 'registration_closes_at', 'starts_on', 'final_on', 'location', 'participation', 'max_team_size',
        'default_mentor_id', 'website_url', 'registration_url',
    ];

    #[Locked]
    public ?string $competitionId = null;

    /** create | edit | verify */
    #[Locked]
    public string $formMode = 'create';

    /** @var array<string, string|int|null> */
    public array $form = [];

    /** @var TemporaryUploadedFile|null */
    public $juknis = null;

    /** @var TemporaryUploadedFile|null */
    public $cover = null;

    public bool $removeCover = false;

    /**
     * "Tanggal lomba ini sudah lewat. Tetap publikasikan" (M2-B, Q1-04): the tick "Simpan & publikasikan"
     * needs when the registration deadline or the final date has passed. Never needed for "Simpan".
     */
    public bool $confirmPast = false;

    public function mount(): void
    {
        $this->authorize('manage', Competition::class);
        $this->form = self::blank();
    }

    #[On('lomba-form-open')]
    public function open(?string $id = null): void
    {
        $this->authorize('manage', Competition::class);
        $this->resetErrorBag();
        $this->reset(['juknis', 'cover', 'removeCover', 'confirmPast']);

        if ($id === null) {
            $this->competitionId = null;
            $this->formMode = 'create';
            $this->form = self::blank();
        } else {
            $competition = $this->competition($id);
            $this->authorize('update', $competition);

            $this->competitionId = $competition->getKey();
            $this->formMode = $competition->status === CompetitionStatus::MenungguVerifikasi ? 'verify' : 'edit';
            $this->form = self::fromModel($competition);
        }

        $this->dispatch('sth-modal-open', id: self::DIALOG);
    }

    public function save(CreateCompetition $create, UpdateCompetition $update): void
    {
        $this->resetErrorBag();

        if (! $this->validateFiles()) {
            return;
        }

        try {
            if ($this->competitionId === null) {
                $create->handle($this->actor(), $this->form, $this->juknis, $this->cover);
                $message = 'Lomba tersimpan dan masuk antrean verifikasi.';
            } else {
                $update->handle($this->actor(), $this->competition($this->competitionId), $this->form, $this->juknis, $this->cover, $this->removeCover);
                $message = 'Perubahan lomba tersimpan.';
            }
        } catch (ValidationException $e) {
            $this->mapErrors($e);

            return;
        }

        $this->finish($message);
    }

    public function saveAndPublish(UpdateCompetition $update, VerifyCompetition $verify): void
    {
        $this->resetErrorBag();

        if ($this->competitionId === null || $this->formMode !== 'verify' || ! $this->validateFiles()) {
            return;
        }

        try {
            $competition = $this->competition($this->competitionId);
            $this->authorize('verify', $competition);
            $update->handle($this->actor(), $competition, $this->form, $this->juknis, $this->cover, $this->removeCover);
            // Without the tick VerifyCompetition refuses past dates under CompetitionDates::PAST_KEY; the
            // data above is saved either way, as with missing fields.
            $verify->handle($this->actor(), $competition->refresh(), CompetitionStatus::Dipublikasikan, null, $this->confirmPast);
        } catch (ValidationException $e) {
            $this->mapErrors($e);
            $this->reset(['juknis', 'cover']);

            return;
        }

        $this->finish('Lomba dipublikasikan ke katalog.');
    }

    public function render(PointCalculator $calculator, PointFormatter $formatter): View
    {
        $competition = $this->competitionId !== null ? Competition::query()->with(['juknisFile', 'coverFile', 'submitter'])->find($this->competitionId) : null;

        return view('livewire.kesiswaan.lomba.form', [
            'title' => match ($this->formMode) {
                'edit' => 'Ubah lomba',
                'verify' => 'Periksa lomba',
                default => 'Tambah lomba',
            },
            'competition' => $competition,
            'submitterName' => $competition?->submitter?->name,
            'juknisUrl' => $competition?->juknisFile !== null && Route::has('files.show') ? route('files.show', $competition->juknisFile) : null,
            // Only publishing asks about past dates, so only the "Periksa lomba" drawer shows the tick.
            'pastDates' => $this->formMode === 'verify'
                ? array_values(CompetitionDates::past($this->form['registration_closes_at'] ?? null, $this->form['final_on'] ?? null))
                : [],
            'rumus' => $this->rumus($calculator, $formatter),
            'options' => [
                'bidang' => collect(Bidang::cases())->mapWithKeys(fn (Bidang $b): array => [$b->value => ['label' => $b->label(), 'icon' => $b->cssKey()]])->all(),
                'organizer_type' => OrganizerType::options(),
                'weight_category' => collect(WeightCategory::cases())->mapWithKeys(fn (WeightCategory $w): array => [
                    $w->value => ['label' => $w->value, 'sub' => $formatter->formatWeight($this->weights()->organizer($w))],
                ])->all(),
                'event_status' => EventStatus::options(),
                'mode' => EventMode::options(),
                'level' => collect(array_reverse(Level::cases()))->mapWithKeys(fn (Level $l): array => [$l->value => $l->shortLabel()])->all(),
                'participation' => Participation::options(),
                'mentors' => User::query()->role(RoleName::Guru->value)->canLogIn()->orderBy('name')->pluck('name', 'id')->all(),
            ],
        ]);
    }

    /** @return array{tingkat: ?string, capaian: string, penyelenggara: ?string, total: ?string} */
    private function rumus(PointCalculator $calculator, PointFormatter $formatter): array
    {
        $weights = $this->weights();
        $level = Level::tryFrom((string) ($this->form['level'] ?? ''));
        $category = WeightCategory::tryFrom((string) ($this->form['weight_category'] ?? ''));

        $total = $level !== null && $category !== null
            ? $formatter->format($calculator->breakdown($weights, $level, ResultType::Juara1, $category)->points)
            : null;

        return [
            'tingkat' => $level !== null ? $weights->level($level) : null,
            'capaian' => $weights->result(ResultType::Juara1),
            'penyelenggara' => $category !== null ? $weights->organizer($category) : null,
            'total' => $total,
        ];
    }

    private function weights(): WeightTable
    {
        return WeightVersion::active()?->toWeightTable() ?? WeightTable::defaults();
    }

    private function validateFiles(): bool
    {
        try {
            $this->validate([
                'juknis' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
                'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            ], [
                'juknis.mimes' => 'Pilih PDF, JPG, atau PNG.',
                'juknis.max' => 'Ukuran maksimal 5 MB.',
                'cover.image' => 'Pilih gambar JPG, PNG, atau WEBP.',
                'cover.mimes' => 'Pilih gambar JPG, PNG, atau WEBP.',
                'cover.max' => 'Ukuran maksimal 3 MB.',
            ]);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
            $this->dispatch('sth-form-invalid', form: self::DIALOG);

            return false;
        }

        return true;
    }

    private function mapErrors(ValidationException $e): void
    {
        $this->resetErrorBag();

        foreach ($e->errors() as $key => $messages) {
            // The question about past dates lands on its tick box (the view draws it and lists the dates).
            if ($key === CompetitionDates::PAST_KEY) {
                $this->addError('confirmPast', 'Centang dulu kalau lomba ini tetap dipublikasikan.');

                continue;
            }

            $this->addError(in_array($key, self::FIELDS, true) ? 'form.'.$key : $key, (string) ($messages[0] ?? 'Periksa isian ini.'));
        }

        // The alert and the marked fields sit in the scrolling drawer body, usually well above the footer
        // the user just clicked, so ask the browser to bring the first one into view.
        $this->dispatch('sth-form-invalid', form: self::DIALOG);
    }

    private function finish(string $message): void
    {
        $this->reset(['juknis', 'cover', 'removeCover', 'confirmPast']);
        $this->dispatch('sth-modal-close', id: self::DIALOG);
        $this->dispatch('lomba-saved');
        $this->dispatch('sth-toast', message: $message, tone: 'success');
    }

    private function competition(string $id): Competition
    {
        $competition = Competition::query()->find($id);

        abort_if($competition === null, 404);

        return $competition;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /** @return array<string, string|int|null> */
    private static function blank(): array
    {
        return array_fill_keys(self::FIELDS, null);
    }

    /** @return array<string, string|int|null> */
    private static function fromModel(Competition $c): array
    {
        $form = self::blank();

        foreach (self::FIELDS as $field) {
            $value = $c->getAttribute($field);
            $form[$field] = match (true) {
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                default => $value,
            };
        }

        return $form;
    }
}
