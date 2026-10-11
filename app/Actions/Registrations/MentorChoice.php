<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may be the guru pembimbing of a registration, in one place (D-34, D-132): an account with the guru role that
 * can log in (active, or waiting for its activation). The student's form (Siswa › Daftar lomba) and the Kepala
 * Sekolah's "Ganti guru" dialog (Persetujuan) offer the same list, and CreateRegistration / ApproveRegistration check a
 * chosen id against the same query.
 */
final class MentorChoice
{
    /** @return Builder<User> */
    public static function query(): Builder
    {
        return User::query()->gurus()->canLogIn();
    }

    /** The guru with this id, or null when the id is empty, unknown, not a guru or an account that cannot log in. */
    public static function find(int|string|null $id): ?User
    {
        if ($id === null || $id === '' || ! ctype_digit((string) $id)) {
            return null;
        }

        return self::query()->whereKey((int) $id)->first();
    }

    /**
     * The pick list, by name: user id => "Pak Hendra Kusuma, S.Pd., M.Si.", without the accounts in $except (the
     * Kepala Sekolah's dialog leaves out the guru the registration has and the Kepala Sekolah's own account).
     *
     * @return array<int, string>
     */
    public static function options(int ...$except): array
    {
        return self::query()
            ->when($except !== [], fn (Builder $q) => $q->whereKeyNot($except))
            ->with('staffProfile:id,user_id,honorific')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (User $guru): array => [(int) $guru->getKey() => (string) self::name($guru)])
            ->all();
    }

    /** How a guru is named on a screen and in a notice: honorific + name ("Bu Retno Wulandari, S.Pd."); null = nobody. */
    public static function name(?User $guru): ?string
    {
        if ($guru === null) {
            return null;
        }

        $honorific = trim((string) $guru->loadMissing('staffProfile')->staffProfile?->honorific);
        $name = trim((string) $guru->name);

        return $name === '' ? null : trim($honorific.' '.$name);
    }

    /**
     * A name at the end of a sentence: a name that ends with a degree ("…, S.Pd.") already carries the full stop.
     */
    public static function closing(string $name): string
    {
        return str_ends_with($name, '.') ? $name : $name.'.';
    }
}
