<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Pengaturan;

use App\Actions\Settings\PublishWeightVersion;
use App\Actions\Users\AdminRecipients;
use App\Domain\Points\PointCalculator;
use App\Domain\Points\WeightTable;
use App\Domain\Support\Decimal;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Enums\WeightCategory;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Models\User;
use App\Models\WeightVersion;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Tab "Bobot poin": 17 weights (tingkat, capaian, kategori penyelenggara) with a live example
 * (Nasional × Juara II × A). "Simpan bobot" → PublishWeightVersion (new version + queued recalculation).
 * Values use a decimal comma in the inputs ("0,85"); the example is computed server-side for display only.
 *
 * Saving asks first (D-128): the form's submit (the button "Simpan bobot", and Enter in any field) runs askSave(),
 * which opens DIALOG; only the dialog's "Simpan bobot" runs save(). SV1: the only submit button of the form used to be
 * the one inside the dialog, so Enter in a field saved at once, without the question. askSave() refuses first what the
 * Action would refuse (a wrong weight, a note over NOTE_MAX, nothing changed), so the dialog only opens for a save that
 * can go through; the dialog says who is told (D-128, D-109).
 */
final class Bobot extends Component
{
    use RequiresRecentPassword;

    /** id of the "Simpan bobot baru?" confirm dialog (<x-sth.modal>). */
    public const DIALOG = 'bobot-konfirmasi';

    /** id of the form; after a refusal app.js brings its first problem into view (event `sth-form-invalid`). */
    public const FORM = 'bobot-form';

    /** The dialog's text before the sentence of who is told. */
    public const DIALOG_TEXT = 'Poin semua prestasi akan dihitung ulang dengan bobot ini. Perubahan tercatat di log audit.';

    /** Limit and sentences of PublishWeightVersion::handle(), which checks both again under its lock. */
    public const NOTE_MAX = 255;

    public const NOTE_TOO_LONG = 'Catatan maksimal 255 karakter.';

    public const UNCHANGED = 'Tidak ada bobot yang berubah.';

    /** Who else is told, from AdminRecipients (D-109: a role is named only when an account of it gets the notice). */
    public const TOLD_NOBODY = 'Tidak ada akun lain yang diberi tahu.';

    /** @var array<string, array<string, string>> group => key => "0,85" */
    public array $weights = [];

    public string $note = '';

    /**
     * What the open "Simpan bobot baru?" dialog asks about: the weights and the note as they stood when askSave() asked,
     * and the sentence of who else is told. Set only by askSave(); save() saves these and nothing else.
     *
     * @var array{weights: array<string, array<string, string>>, note: string, told: string}|null
     */
    #[Locked]
    public ?array $asked = null;

    public function mount(): void
    {
        Gate::authorize('viewAny', WeightVersion::class);
        $this->fillFrom(WeightVersion::active()?->toWeightTable() ?? WeightTable::defaults());
    }

    /**
     * The form's submit: "Simpan bobot" under the form, or Enter in any field (D-128). Checks what PublishWeightVersion
     * would refuse (assertSavable()), so a wrong weight stands under its field and "Tidak ada bobot yang berubah." on the
     * tab before any question, then opens the dialog with the sentence of who is told. Nothing is saved here. The
     * password is asked first, so an answer in the dialog is never followed by the password page (as on Import siswa).
     */
    public function askSave(PublishWeightVersion $publish, AdminRecipients $recipients): void
    {
        Gate::authorize('create', WeightVersion::class);
        $this->asked = null;
        $this->resetErrorBag();

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        try {
            $this->assertSavable($publish);
        } catch (ValidationException $e) {
            // The button sits under a long form and "Tidak ada bobot yang berubah." at its top: without this the click
            // looked dead.
            $this->dispatch('sth-form-invalid', form: self::FORM);

            throw $e;
        }

        /** @var User $user */
        $user = auth()->user();

        $this->asked = ['weights' => $this->weights, 'note' => $this->note, 'told' => $this->toldSentence($recipients, $user)];
        $this->dispatch('sth-modal-open', id: self::DIALOG);
    }

    /**
     * "Simpan bobot" in the dialog. Without a question from askSave() there is nothing to confirm: it returns without a
     * word (never an error page), so a second click after the save changes nothing. A refusal of the Action under its
     * lock (an Admin in another tab saved the same weights meanwhile) closes the dialog and stands on the tab.
     */
    public function save(PublishWeightVersion $publish): void
    {
        $asked = $this->asked;
        $this->asked = null;
        $this->dispatch('sth-modal-close', id: self::DIALOG);

        if ($asked === null) {
            return;
        }

        Gate::authorize('create', WeightVersion::class);

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        /** @var User $user */
        $user = auth()->user();

        try {
            $version = $publish->handle($user, $asked['weights'], $asked['note'] !== '' ? $asked['note'] : null);
        } catch (ValidationException $e) {
            $this->dispatch('sth-form-invalid', form: self::FORM);

            throw $e;
        }

        $this->note = '';
        $this->fillFrom($version->toWeightTable());
        $this->dispatch('sth-toast', message: 'Bobot tersimpan. Poin sedang dihitung ulang.', tone: 'success');
    }

    public function resetForm(): void
    {
        $this->asked = null;
        $this->resetErrorBag();
        $this->fillFrom(WeightVersion::active()?->toWeightTable() ?? WeightTable::defaults());
    }

    public function render(PointCalculator $calculator): View
    {
        return view('livewire.admin.pengaturan.bobot', [
            'rows' => [
                'level' => ['title' => 'Tingkat', 'column' => 'Tingkat lomba', 'items' => $this->labels(Level::cases())],
                'result' => ['title' => 'Capaian', 'column' => 'Hasil lomba', 'items' => $this->resultLabels()],
                'organizer' => ['title' => 'Kategori penyelenggara', 'column' => 'Kategori', 'items' => $this->categoryLabels()],
            ],
            'example' => $this->example($calculator),
            'versions' => WeightVersion::query()->with('creator:id,name')->orderByDesc('effective_at')->orderByDesc('id')
                ->limit(5)->get(['id', 'effective_at', 'note', 'created_by']),
            'activeId' => WeightVersion::active()?->getKey(),
            // The dialog is only seen after askSave(), which stored who is told with the question.
            'dialogText' => self::DIALOG_TEXT.(isset($this->asked['told']) ? ' '.$this->asked['told'] : ''),
        ]);
    }

    /**
     * What PublishWeightVersion::handle() would refuse, all at once (no rounds of errors): its field checks (its own
     * validated()), a note longer than NOTE_MAX after trimming, and nothing changed against the version in force. The
     * Action checks the note and the change again, the change under its lock.
     *
     * @throws ValidationException
     */
    private function assertSavable(PublishWeightVersion $publish): void
    {
        $errors = [];
        $table = null;

        try {
            $table = $publish->validated($this->weights);
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }

        if (mb_strlen(trim($this->note)) > self::NOTE_MAX) {
            $errors['note'] = [self::NOTE_TOO_LONG];
        }

        if ($table !== null && $this->unchanged($table)) {
            $errors['weights'] = [self::UNCHANGED];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The rule of PublishWeightVersion, read without its lock: unchanged when a version is in force and every weight of
     * the form equals it at two decimals (no version yet: the first save is a change).
     */
    private function unchanged(WeightTable $table): bool
    {
        $current = WeightVersion::active()?->toWeightTable();

        if ($current === null) {
            return false;
        }

        foreach (['levels', 'results', 'organizers'] as $group) {
            $before = $current->{$group}();

            foreach ($table->{$group}() as $key => $value) {
                if (! isset($before[$key]) || bccomp($before[$key], $value, 2) !== 0) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Who else hears of the save. PublishWeightVersion sends WeightVersionPublished to every Kepala Sekolah and Admin
     * who can log in (AdminRecipients::principalsAndAdmins()), the acting Admin included, who needs no sentence for it.
     * D-109: a role is named only when an account of it, other than the actor, really gets the notice.
     */
    private function toldSentence(AdminRecipients $recipients, User $actor): string
    {
        $others = fn (Collection $users): bool => $users->contains(fn (User $user): bool => ! $user->is($actor));

        $roles = array_values(array_filter([
            $others($recipients->principals()) ? 'Kepala Sekolah' : null,
            $others($recipients->admins()) ? 'Admin lain' : null,
        ]));

        return $roles === [] ? self::TOLD_NOBODY : implode(' dan ', $roles).' diberi tahu.';
    }

    /** @return array{level: string, result: string, organizer: string, total: ?string} */
    private function example(PointCalculator $calculator): array
    {
        // Dot decimals for <x-sth.rumus-poin> (it formats with a comma); invalid input shows "–".
        $raw = array_map(
            fn (string $v): string => trim(str_replace(',', '.', $v)),
            [
                'level' => (string) ($this->weights['level'][Level::Nasional->value] ?? ''),
                'result' => (string) ($this->weights['result'][ResultType::Juara2->value] ?? ''),
                'organizer' => (string) ($this->weights['organizer'][WeightCategory::A->value] ?? ''),
            ],
        );

        try {
            $total = $calculator->compute($raw['level'], $raw['result'], $raw['organizer']);
        } catch (Throwable) {
            $total = null;
        }

        return [...$raw, 'total' => $total];
    }

    /**
     * Field values with a decimal comma and no thousands dot (QA10): PointFormatter groups thousands ("1.000"), which
     * PublishWeightVersion read back as 1 with three decimals, so a tingkat weight of 1000 blocked every later save.
     */
    private function fillFrom(WeightTable $table): void
    {
        $this->weights = [
            'level' => array_map(fn (string $v): string => self::fieldValue($v, trimZeros: true), $table->levels()),
            'result' => array_map(fn (string $v): string => self::fieldValue($v), $table->results()),
            'organizer' => array_map(fn (string $v): string => self::fieldValue($v), $table->organizers()),
        ];
    }

    /** "1000.00" → "1000", "62.50" → "62,5" (tingkat); "0.85" → "0,85", "1.00" → "1,00" (factors). */
    private static function fieldValue(string $value, bool $trimZeros = false): string
    {
        $value = Decimal::roundHalfUp($value, 2);

        if ($trimZeros && str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return str_replace('.', ',', $value);
    }

    /**
     * Highest first (Internasional … Sekolah).
     *
     * @param  list<Level>  $levels
     * @return array<string, array{label: string, hint: ?string}>
     */
    private function labels(array $levels): array
    {
        usort($levels, fn (Level $a, Level $b): int => $b->rank() <=> $a->rank());

        return collect($levels)->mapWithKeys(fn (Level $level) => [$level->value => ['label' => $level->label(), 'hint' => null]])->all();
    }

    /** @return array<string, array{label: string, hint: ?string}> */
    private function resultLabels(): array
    {
        return collect(ResultType::cases())->mapWithKeys(fn (ResultType $r) => [$r->value => ['label' => $r->label(), 'hint' => null]])->all();
    }

    /** @return array<string, array{label: string, hint: ?string}> */
    private function categoryLabels(): array
    {
        return collect(WeightCategory::cases())
            ->mapWithKeys(fn (WeightCategory $c) => [$c->value => ['label' => 'Kategori '.$c->value, 'hint' => $c->hint()]])
            ->all();
    }

    protected function returnUrl(): string
    {
        return route('admin.pengaturan', ['tab' => 'bobot']);
    }
}
