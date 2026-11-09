<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\RoleName;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * SV8, D-197 "Data guru": Kesiswaan (and Admin) correct what the guru letter prints under "Kepada" for ONE guru account:
 * Nama lengkap (with the titles, as printed), Pangkat/golongan, NIP and Jabatan (`users.name`, `staff_profiles.rank`,
 * `.nip`, `.position`; the letter reads the same columns, App\Actions\Letters\LetterContent). Called by the dialog of
 * App\Livewire\Kesiswaan\DataGuru. Permission `guru.update`, UserPolicy::updateGuruData(): guru accounts only, never
 * another role, never an account in the trash.
 *
 * Rules (only a field that changed is checked, an unchanged one is kept as stored, also an older value that would not
 * pass today):
 * - Nama lengkap: required, NAME_MIN–NAME_MAX characters after squeezing the spaces, letters and the marks of titles
 *   (. , ' ’ -), starting with a letter ("Drs. Bambang Hartono, M.Pd.").
 * - NIP: the typed digits without spaces, exactly NIP_LENGTH digits, or empty for a guru without one (the letter then
 *   prints "-"); not held by another staff account (compared without spaces).
 * - Pangkat/golongan at most RANK_MAX, Jabatan at most POSITION_MAX characters, both may be empty.
 *
 * One transaction: the users row and the staff_profiles row locked, compared with the snapshot the dialog showed (changed
 * meanwhile → KEY_GURU / MSG_CHANGED; no guru any more or in the trash → MSG_GONE), only the changed fields written
 * (`forceFill`; the user row with saveQuietly(), so the User model's own activity log never stores the name). A guru
 * without a staff_profiles row gets one. Exactly ONE audit row per save, `account.guru_data_updated` (log `account`, so
 * also a security-log line) with the field names and a boolean only: never a name, NIP, rank or position. Nobody is
 * notified. A letter already issued keeps what it printed (D-108).
 */
final class UpdateGuruData
{
    public const EVENT = 'account.guru_data_updated';

    /** The editable fields, in the order the guru letter prints them (Nama, Pangkat/Gol, NIP, Jabatan). */
    public const FIELDS = ['name', 'rank', 'nip', 'position'];

    public const NAME_MIN = 3;

    public const NAME_MAX = 150;

    public const NIP_LENGTH = 18;

    public const RANK_MAX = 100;

    public const POSITION_MAX = 150;

    /** Error key of a refusal that is about the whole account, not one field. */
    public const KEY_GURU = 'guru';

    public const MSG_CHANGED = 'Data guru ini baru saja diubah. Isian sudah diperbarui.';

    public const MSG_GONE = 'Akun ini sudah bukan akun guru atau sudah dihapus. Daftar sudah diperbarui.';

    public const MSG_NAME_REQUIRED = 'Nama lengkap wajib diisi.';

    public const MSG_NAME_LENGTH = 'Nama lengkap 3–150 karakter.';

    public const MSG_NAME_LETTERS = 'Nama lengkap hanya boleh berisi huruf, spasi, titik, koma, petik, dan strip.';

    public const MSG_NIP_FORMAT = 'NIP harus 18 angka. Kosongkan bila tidak ada NIP.';

    public const MSG_NIP_TAKEN = 'NIP ini sudah dipakai akun staf lain.';

    public const MSG_RANK_MAX = 'Pangkat/golongan maksimal 100 karakter.';

    public const MSG_POSITION_MAX = 'Jabatan maksimal 150 karakter.';

    private const NAME_PATTERN = "/^[\\p{L}][\\p{L}\\p{M} .,'’-]*$/u";

    /**
     * Saves the changed fields of one guru account.
     *
     * @param  array<string, mixed>  $input  name, rank, nip, position
     * @param  array<string, string|null>|null  $shown  snapshot() of what the screen showed; null = no stale check
     * @return array{fields: list<string>, profile_created: bool} fields = the changed FIELDS (empty: nothing written)
     *
     * @throws ValidationException field keys of FIELDS, or KEY_GURU when the account changed or left meanwhile
     */
    public function handle(User $actor, User $guru, array $input, ?array $shown = null): array
    {
        Gate::forUser($actor)->authorize('viewGuruData', User::class);

        if (! self::isGuru($guru)) {
            throw ValidationException::withMessages([self::KEY_GURU => self::MSG_GONE]);
        }

        Gate::forUser($actor)->authorize('updateGuruData', $guru);

        return DB::transaction(function () use ($actor, $guru, $input, $shown): array {
            $locked = User::query()->whereKey($guru->getKey())->lockForUpdate()->first();

            // Deleted, or given another role by an Admin, since the dialog opened.
            if ($locked === null || ! $locked->hasRole(RoleName::Guru->value)) {
                throw ValidationException::withMessages([self::KEY_GURU => self::MSG_GONE]);
            }

            $profile = StaffProfile::query()->where('user_id', $locked->getKey())->lockForUpdate()->first();
            $locked->setRelation('staffProfile', $profile);

            if ($shown !== null && self::snapshot($locked) !== $shown) {
                throw ValidationException::withMessages([self::KEY_GURU => self::MSG_CHANGED]);
            }

            Gate::forUser($actor)->authorize('updateGuruData', $locked);

            $plan = self::plan($locked, $profile, $input);

            if ($plan['errors'] !== []) {
                throw ValidationException::withMessages($plan['errors']);
            }

            if ($plan['fields'] === []) {
                return ['fields' => [], 'profile_created' => false];
            }

            if ($plan['user'] !== []) {
                // Quietly: the User model logs `name` changes with their values; the one row below names the field only.
                $locked->forceFill($plan['user'])->saveQuietly();
            }

            $created = false;

            if ($plan['profile'] !== []) {
                $created = $profile === null;
                $profile ??= (new StaffProfile)->forceFill(['user_id' => $locked->getKey()]);
                $profile->forceFill($plan['profile'])->save();
            }

            activity('account')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event(self::EVENT)
                ->withProperties([
                    'fields' => $plan['fields'],
                    'profile_created' => $created,
                ])
                ->log(self::EVENT);

            return ['fields' => $plan['fields'], 'profile_created' => $created];
        });
    }

    /**
     * Every problem of the input at once (field => sentence), without writing; the dialog shows them before it asks
     * anything else. The same check runs again inside handle() on the locked rows.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function errors(User $guru, array $input): array
    {
        return self::plan($guru, $guru->staffProfile()->first(), $input)['errors'];
    }

    /**
     * The changed FIELDS of the input (also the invalid ones), the dialog's "Tidak ada data yang diubah." check.
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public function changedFields(User $guru, array $input): array
    {
        return self::plan($guru, $guru->staffProfile()->first(), $input)['fields'];
    }

    /**
     * The checks of a NEW guru account's letter data ("Tambah guru", CreateStaffAccount::handleGuru()): every field,
     * the same sentences as the dialog.
     *
     * @param  array<string, mixed>  $input  name, rank, nip, position
     * @return array<string, string>
     */
    public static function newGuruErrors(array $input): array
    {
        return array_filter([
            'name' => self::nameError(self::squish((string) ($input['name'] ?? ''))),
            'rank' => self::rankError(self::squish((string) ($input['rank'] ?? ''))),
            'nip' => self::nipError(self::digits($input['nip'] ?? ''), null),
            'position' => self::positionError(self::squish((string) ($input['position'] ?? ''))),
        ]);
    }

    /**
     * What the screen shows of the account, as strings: the stale check compares it with the locked rows. Changes of
     * these values only (a login, a new email, the honorific … leave it alone).
     *
     * @return array<string, string|null>
     */
    public static function snapshot(User $account): array
    {
        $profile = $account->relationLoaded('staffProfile') ? $account->staffProfile : $account->staffProfile()->first();

        return [
            'name' => (string) $account->name,
            'status' => $account->status?->value,
            'rank' => $profile?->rank !== null ? (string) $profile->rank : null,
            'nip' => $profile?->nip !== null ? (string) $profile->nip : null,
            'position' => $profile?->position !== null ? (string) $profile->position : null,
        ];
    }

    /** A guru account that is not in the trash (read from the database, never from cached roles). */
    public static function isGuru(User $account): bool
    {
        return User::query()->whereKey($account->getKey())->role(RoleName::Guru->value)->exists();
    }

    /** A typed text as stored: trimmed, every run of whitespace one space. */
    public static function squish(string $value): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($value));
    }

    /** A typed NIP as stored: without spaces ("19800101 200501 1 001" → 18 digits). */
    public static function digits(mixed $value): string
    {
        return (string) preg_replace('/\s+/u', '', is_scalar($value) ? (string) $value : '');
    }

    /**
     * What the input changes and what is wrong with it. Only a changed field is checked.
     *
     * @param  array<string, mixed>  $input
     * @return array{errors: array<string, string>, fields: list<string>, user: array<string, string>, profile: array<string, string|null>}
     */
    private static function plan(User $account, ?StaffProfile $profile, array $input): array
    {
        $errors = [];
        $fields = [];
        $user = [];
        $row = [];

        $name = self::squish((string) ($input['name'] ?? ''));

        if ($name !== self::squish((string) $account->name)) {
            $fields[] = 'name';
            $error = self::nameError($name);

            if ($error !== null) {
                $errors['name'] = $error;
            } else {
                $user['name'] = $name;
            }
        }

        $nip = self::digits($input['nip'] ?? '');

        if ($nip !== self::digits($profile?->nip ?? '')) {
            $fields[] = 'nip';
            $error = self::nipError($nip, (int) $account->getKey());

            if ($error !== null) {
                $errors['nip'] = $error;
            } else {
                $row['nip'] = $nip !== '' ? $nip : null;
            }
        }

        foreach (['rank', 'position'] as $column) {
            $value = self::squish((string) ($input[$column] ?? ''));

            if ($value === self::squish((string) ($profile?->{$column} ?? ''))) {
                continue;
            }

            $fields[] = $column;
            $error = $column === 'rank' ? self::rankError($value) : self::positionError($value);

            if ($error !== null) {
                $errors[$column] = $error;
            } else {
                $row[$column] = $value !== '' ? $value : null;
            }
        }

        // In the order of FIELDS.
        $fields = array_values(array_intersect(self::FIELDS, $fields));

        return ['errors' => $errors, 'fields' => $fields, 'user' => $user, 'profile' => $row];
    }

    private static function nameError(string $name): ?string
    {
        return match (true) {
            $name === '' => self::MSG_NAME_REQUIRED,
            mb_strlen($name) < self::NAME_MIN || mb_strlen($name) > self::NAME_MAX => self::MSG_NAME_LENGTH,
            preg_match(self::NAME_PATTERN, $name) !== 1 => self::MSG_NAME_LETTERS,
            default => null,
        };
    }

    /** Empty is allowed (a guru without a NIP); else exactly NIP_LENGTH digits that no other staff account holds. */
    private static function nipError(string $nip, ?int $exceptUserId): ?string
    {
        if ($nip === '') {
            return null;
        }

        if (preg_match('/^\d{'.self::NIP_LENGTH.'}$/', $nip) !== 1) {
            return self::MSG_NIP_FORMAT;
        }

        // Older rows may hold the NIP with spaces (Admin › Tambah pengguna allows them): compared without.
        $taken = StaffProfile::query()
            ->when($exceptUserId !== null, fn ($q) => $q->where('user_id', '!=', $exceptUserId))
            ->whereNotNull('nip')
            ->whereRaw("REPLACE(nip, ' ', '') = ?", [$nip])
            ->exists();

        return $taken ? self::MSG_NIP_TAKEN : null;
    }

    private static function rankError(string $rank): ?string
    {
        return mb_strlen($rank) > self::RANK_MAX ? self::MSG_RANK_MAX : null;
    }

    private static function positionError(string $position): ?string
    {
        return mb_strlen($position) > self::POSITION_MAX ? self::MSG_POSITION_MAX : null;
    }
}
