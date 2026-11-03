<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Domain\Letters\LetterNumber;
use App\Enums\SettingKey;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Admin › Pengaturan › Surat & tanda tangan › Contoh nomor surat (`setting.manage`). Templates are checked with
 * LetterNumber::assertValidTemplate() ({seq} exactly once, only {seq} {roman_month} {year}). Audited.
 * M1-A (lead, 2026-10-04): Tata Usaha types the complete number of every letter, so these two formats no longer
 * build a number; they only produce the example under TU's number field (IssueLetters::example()). Issued
 * letters keep their stored full_number (immutable).
 */
final class UpdateLetterFormats
{
    public function __construct(private readonly LetterNumber $letterNumber) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, string $siswa, string $guru): void
    {
        Gate::forUser($actor)->authorize('update', Setting::class);

        $formats = ['formatSiswa' => trim($siswa), 'formatGuru' => trim($guru)];
        $errors = [];

        foreach ($formats as $field => $template) {
            try {
                $this->letterNumber->assertValidTemplate($template);
            } catch (InvalidArgumentException $e) {
                $errors[$field] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($actor, $formats): void {
            $map = ['formatSiswa' => SettingKey::LetterFormatSiswa, 'formatGuru' => SettingKey::LetterFormatGuru];
            Setting::query()->whereIn('key', array_map(fn (SettingKey $k) => $k->value, $map))->lockForUpdate()->get();
            $changes = [];

            foreach ($map as $field => $key) {
                $from = (string) Setting::get($key);

                if ($from !== $formats[$field]) {
                    Setting::put($key, $formats[$field]);
                    $changes[$key->value] = ['from' => $from, 'to' => $formats[$field]];
                }
            }

            if ($changes !== []) {
                activity('setting')
                    ->causedBy($actor)
                    ->event('settings.letter_formats_updated')
                    ->withProperties($changes)
                    ->log('settings.letter_formats_updated');
            }
        });
    }
}
