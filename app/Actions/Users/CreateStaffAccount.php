<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\StaffProfile;
use App\Models\User;
use App\Notifications\Admin\StaffAccountChanged;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Admin › Pengguna & peran › "Tambah pengguna": a staff account (never `siswa`, never `kepala_sekolah` —
 * that role moves only through RequestPrincipalReplacement). The Admin types a temporary password (never
 * shown again, never logged); the account starts `pending_activation` + must_change_password, so the owner
 * changes it, binds an email and sets up 2FA on first login. Audited; Admins are notified.
 *
 * Since 21 Okt 2026 (the lead: "kalo sdh dibuat ya sudah tidak perlu persetujuan admin lainnya") the chosen role is
 * given at once for every role of StaffRoles::assignable() (Guru, Kesiswaan, Tata Usaha, Humas, Admin): no
 * RoleChangeRequest, no second Admin, no 24 h delay (replaces the W4 rule of D-61). An Admin account still respects the
 * 3-Admin limit, pending Admin requests made before that day included (StaffRoles::assertAdminRoom).
 *
 * @phpstan-type StaffData array{name: string, username: string, email?: ?string, role: string, password: string, honorific?: ?string, nip?: ?string, rank?: ?string, position?: ?string}
 */
final class CreateStaffAccount
{
    public function __construct(private readonly AdminRecipients $recipients) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $data): User
    {
        Gate::forUser($actor)->authorize('create', User::class);

        $valid = self::validator($data)->validate();

        $role = RoleName::from($valid['role']);

        $user = DB::transaction(function () use ($actor, $valid, $role): User {
            $user = new User;
            $user->forceFill([
                'name' => trim((string) $valid['name']),
                'username' => $valid['username'],
                'email' => $valid['email'] ?? null,
                'email_verified_at' => null,
                'password' => Hash::make((string) $valid['password']),
                'status' => UserStatus::PendingActivation,
                'must_change_password' => true,
                'password_changed_at' => null,
            ])->save();

            if ($role === RoleName::Admin) {
                StaffRoles::assertAdminRoom($user);
            }

            $user->assignRole($role->value);

            StaffProfile::query()->create([
                'user_id' => $user->getKey(),
                'honorific' => $valid['honorific'] ?? null,
                'nip' => $valid['nip'] ?? null,
                'rank' => $valid['rank'] ?? null,
                'position' => $valid['position'] ?? null,
            ]);

            activity('account')
                ->performedOn($user)
                ->causedBy($actor)
                ->event('account.staff_created')
                ->withProperties([
                    'role' => $role->value,
                    'username' => $user->username,
                ])
                ->log('account.staff_created');

            return $user;
        });

        Notification::send(
            $this->recipients->admins(),
            new StaffAccountChanged(StaffAccountChanged::CREATED, (string) $actor->name, (string) $user->name, $role->label()),
        );

        return $user;
    }

    /**
     * The checks of the form, without saving anything, so a screen can show them together with its own (QA5 #65:
     * "Tambah pengguna" showed the password error first and the other fields only on the next try).
     *
     * @param  array<string, mixed>  $data
     */
    public static function validator(array $data): ValidatorContract
    {
        $data['username'] = mb_strtolower(trim((string) ($data['username'] ?? '')));
        $data['email'] = filled($data['email'] ?? null) ? mb_strtolower(trim((string) $data['email'])) : null;

        return Validator::make($data, [
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'username' => ['required', 'string', 'regex:'.StaffRoles::USERNAME_PATTERN, Rule::unique('users', 'username')],
            'email' => ['nullable', 'email:rfc', 'max:190', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(array_map(fn (RoleName $r) => $r->value, StaffRoles::assignable()))],
            'password' => ['required', 'string', Password::defaults()],
            'honorific' => ['nullable', 'string', 'max:10'],
            'nip' => ['nullable', 'regex:/^[0-9 ]{8,30}$/'],
            'rank' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:150'],
        ], [
            'username.regex' => 'Nama pengguna 3–50 karakter: huruf kecil, angka, titik, garis bawah atau strip, diawali huruf.',
            'nip.regex' => 'NIP hanya angka (boleh dengan spasi).',
        ], [
            'name' => 'nama', 'username' => 'nama pengguna', 'role' => 'peran', 'password' => 'kata sandi sementara',
        ]);
    }
}
