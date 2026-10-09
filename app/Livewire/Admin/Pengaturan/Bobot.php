<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Pengaturan;

use App\Actions\Settings\PublishWeightVersion;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

/**
 * Tab "Bobot poin": 17 weights (tingkat, capaian, kategori penyelenggara) with a live example
 * (Nasional × Juara II × A). "Simpan bobot" → PublishWeightVersion (new version + queued recalculation).
 * Values use a decimal comma in the inputs ("0,85"); the example is computed server-side for display only.
 */
final class Bobot extends Component
{
    use RequiresRecentPassword;

    /** @var array<string, array<string, string>> group => key => "0,85" */
    public array $weights = [];

    public string $note = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', WeightVersion::class);
        $this->fillFrom(WeightVersion::active()?->toWeightTable() ?? WeightTable::defaults());
    }

    public function save(PublishWeightVersion $publish): void
    {
        Gate::authorize('create', WeightVersion::class);

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        /** @var User $user */
        $user = auth()->user();

        try {
            $version = $publish->handle($user, $this->weights, $this->note !== '' ? $this->note : null);
        } catch (ValidationException $e) {
            $this->dispatch('sth-modal-close', id: 'bobot-konfirmasi');

            throw $e;
        }

        $this->note = '';
        $this->fillFrom($version->toWeightTable());
        $this->dispatch('sth-modal-close', id: 'bobot-konfirmasi');
        $this->dispatch('sth-toast', message: 'Bobot tersimpan. Poin sedang dihitung ulang.', tone: 'success');
    }

    public function resetForm(): void
    {
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
        ]);
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
