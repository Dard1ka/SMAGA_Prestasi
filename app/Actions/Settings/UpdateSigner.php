<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Enums\SettingKey;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Admin › Pengaturan › Surat & tanda tangan › Penandatangan: name, pangkat/golongan and NIP printed under the
 * signature on every surat tugas (`signature.manage`). Audited with from/to.
 */
final class UpdateSigner
{
    /**
     * @throws ValidationException
     */
    public function handle(User $actor, string $name, string $rank, string $nip): void
    {
        Gate::forUser($actor)->authorize('manageSignature', Setting::class);

        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $rank = trim(preg_replace('/\s+/u', ' ', $rank) ?? '');
        $nip = trim(preg_replace('/\s+/u', ' ', $nip) ?? '');
        $errors = [];

        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) {
            $errors['signerName'] = 'Isi nama 3–150 karakter.';
        }

        if (mb_strlen($rank) > 100) {
            $errors['signerRank'] = 'Pangkat/golongan maksimal 100 karakter.';
        }

        if ($nip !== '' && ! preg_match('/^[0-9 ]{8,30}$/', $nip)) {
            $errors['signerNip'] = 'NIP hanya angka (boleh dengan spasi).';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($actor, $name, $rank, $nip): void {
            $keys = [SettingKey::SignerName, SettingKey::SignerRank, SettingKey::SignerNip];
            Setting::query()->whereIn('key', array_map(fn (SettingKey $k) => $k->value, $keys))->lockForUpdate()->get();

            $new = [SettingKey::SignerName->value => $name, SettingKey::SignerRank->value => $rank, SettingKey::SignerNip->value => $nip];
            $changes = [];

            foreach ($keys as $key) {
                $from = (string) Setting::get($key);

                if ($from !== $new[$key->value]) {
                    Setting::put($key, $new[$key->value]);
                    $changes[$key->value] = ['from' => $from, 'to' => $new[$key->value]];
                }
            }

            if ($changes !== []) {
                activity('setting')
                    ->causedBy($actor)
                    ->event('settings.signer_updated')
                    ->withProperties($changes)
                    ->log('settings.signer_updated');
            }
        });
    }
}
