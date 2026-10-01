<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Actions\Users\AdminRecipients;
use App\Domain\Points\WeightTable;
use App\Domain\Support\Decimal;
use App\Enums\WeightGroup;
use App\Jobs\RecomputePoints;
use App\Models\User;
use App\Models\WeightValue;
use App\Models\WeightVersion;
use App\Notifications\Admin\WeightVersionPublished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Admin › Pengaturan › Bobot poin (D-08, UI-MAP #24). Weights are versioned: saving creates a NEW
 * WeightVersion effective now (old versions stay as the audit trail), then a queued RecomputePoints job
 * recalculates every achievement with the new version. Audited with the per-key diff; the Kepala Sekolah
 * and Admins are notified.
 *
 * $weights = ['level' => ['nasional' => '80', …], 'result' => ['juara_2' => '0,85', …], 'organizer' => ['A' => '1', …]]
 * Every key of WeightGroup::defaults() is required. Decimal comma or point, max 2 decimals.
 */
final class PublishWeightVersion
{
    /** Allowed range per group (inclusive): tingkat is a base value, capaian/penyelenggara are factors. */
    public const RANGES = [
        'level' => ['0.01', '1000'],
        'result' => ['0', '1'],
        'organizer' => ['0', '1'],
    ];

    public function __construct(private readonly AdminRecipients $recipients) {}

    /**
     * @param  array<string, array<string, string|int|float|null>>  $weights
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $weights, ?string $note = null): WeightVersion
    {
        Gate::forUser($actor)->authorize('create', WeightVersion::class);

        $table = $this->validated($weights);
        $note = $note !== null ? trim($note) : null;

        if ($note !== null && mb_strlen($note) > 255) {
            throw ValidationException::withMessages(['note' => 'Catatan maksimal 255 karakter.']);
        }

        $version = DB::transaction(function () use ($actor, $table, $note): WeightVersion {
            // Serialise concurrent publishes on the current version row.
            $current = WeightVersion::query()->effective()->lockForUpdate()->first();
            $before = $current?->toWeightTable() ?? WeightTable::defaults();
            $changes = $this->diff($before, $table);

            if ($current !== null && $changes === []) {
                throw ValidationException::withMessages(['weights' => 'Tidak ada bobot yang berubah.']);
            }

            $version = new WeightVersion;
            $version->forceFill([
                'effective_at' => now(),
                'note' => $note !== '' ? $note : null,
                'created_by' => $actor->getKey(),
            ])->save();

            foreach ($table->toRows() as $row) {
                WeightValue::query()->create([
                    'weight_version_id' => $version->getKey(),
                    'group' => $row['group'],
                    'key' => $row['key'],
                    'value' => $row['value'],
                ]);
            }

            activity('weight')
                ->performedOn($version)
                ->causedBy($actor)
                ->event('weight.published')
                ->withProperties(['previous_version' => $current?->getKey(), 'changes' => $changes, 'note' => $version->note])
                ->log('weight.published');

            return $version;
        });

        RecomputePoints::dispatch((string) $version->getKey(), (int) $actor->getKey());

        Notification::send(
            $this->recipients->principalsAndAdmins(),
            new WeightVersionPublished((string) $actor->name, $version->effective_at->translatedFormat('j F Y H:i')),
        );

        return $version;
    }

    /**
     * @param  array<string, array<string, string|int|float|null>>  $weights
     *
     * @throws ValidationException
     */
    public function validated(array $weights): WeightTable
    {
        $groups = [];
        $errors = [];

        foreach (WeightGroup::cases() as $group) {
            [$min, $max] = self::RANGES[$group->value];

            foreach (array_keys($group->defaults()) as $key) {
                $field = "weights.{$group->value}.{$key}";
                $raw = trim(str_replace(',', '.', (string) ($weights[$group->value][$key] ?? '')));

                if (! preg_match('/^\d{1,4}(\.\d{1,2})?$/', $raw)) {
                    $errors[$field] = 'Isi angka, maks. 2 desimal.';

                    continue;
                }

                $value = Decimal::roundHalfUp($raw, 2);

                if (bccomp($value, $min, 2) < 0 || bccomp($value, $max, 2) > 0) {
                    $errors[$field] = $group === WeightGroup::Level
                        ? 'Bobot tingkat harus antara 0,01 dan 1000.'
                        : 'Bobot harus antara 0 dan 1.';

                    continue;
                }

                $groups[$group->value][$key] = $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new WeightTable($groups['level'], $groups['result'], $groups['organizer']);
    }

    /** @return array<string, array{from: string, to: string}> e.g. ['result.juara_2' => ['from' => '0.85', 'to' => '0.80']] */
    private function diff(WeightTable $before, WeightTable $after): array
    {
        $changes = [];

        foreach (['level' => 'levels', 'result' => 'results', 'organizer' => 'organizers'] as $group => $method) {
            $old = $before->{$method}();

            foreach ($after->{$method}() as $key => $value) {
                $from = $old[$key] ?? null;

                if ($from === null || bccomp($from, $value, 2) !== 0) {
                    $changes["{$group}.{$key}"] = ['from' => (string) $from, 'to' => $value];
                }
            }
        }

        return $changes;
    }
}
