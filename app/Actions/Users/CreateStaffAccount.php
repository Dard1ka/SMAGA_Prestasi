<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Roles\PrivilegedRoles;
use App\Actions\Roles\RequestRoleChange;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\RoleChangeRequest;
use App\Models\StaffProfile;
use App\Models\User;
use App\Notifications\Admin\StaffAccountChanged;
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
 * Lead decision (W4, same rule as role changes, backlog #13/#29): a PRIVILEGED role (admin, tata_usaha, kesiswaan)
 * is never given at creation. The account is created WITHOUT a role plus a pending RoleChangeRequest in the same
 * transaction (RequestRoleChange::open): a second Admin approves it, or — when the creator is the only Admin — it
 * applies after 24 h unless cancelled. Until then the account can only activate and use its account pages.
 * Non-privileged roles (guru, humas) are given at once.
 *
 * @phpstan-type StaffData array{name: string, username: string, email?: ?string, role: string, password: string, honorific?: ?string, nip?: ?string, rank?: ?string, position?: ?string}
 */
final class CreateStaffAccount
{
    /** Reason stored on the role request of a new privileged account (shown to the approving Admin). */
    public const NEW_ACCOUNT_REASON = 'Peran awal akun staf baru.';

    public function __construct(
        private readonly AdminRecipients $recipients,
        private readonly RequestRoleChange $roleRequests,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $data): User
    {
        Gate::forUser($actor)->authorize('create', User::class);

        $data['username'] = mb_strtolower(trim((string) ($data['username'] ?? '')));
        $data['email'] = filled($data['email'] ?? null) ? mb_strtolower(trim((string) $data['email'])) : null;

        $valid = Validator::make($data, [
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
        ])->validate();

        $role = RoleName::from($valid['role']);
        $privileged = PrivilegedRoles::isPrivileged($role);

        /** @var array{0: User, 1: ?RoleChangeRequest} $result */
        $result = DB::transaction(function () use ($actor, $valid, $role, $privileged): array {
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

            if (! $privileged) {
                $user->assignRole($role->value);
            }

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
                    'role' => $privileged ? null : $role->value,
                    'requested_role' => $privileged ? $role->value : null,
                    'username' => $user->username,
                ])
                ->log('account.staff_created');

            // Same checks as "Ubah peran": one pending request, 3-Admin limit incl. pending, delay when alone.
            $request = $privileged
                ? $this->roleRequests->open($actor, $user, $role, self::NEW_ACCOUNT_REASON)
                : null;

            return [$user, $request];
        });

        [$user, $request] = $result;

        if ($request !== null) {
            $this->roleRequests->notifyRequested($actor, $user, $request, newAccount: true);

            return $user;
        }

        Notification::send(
            $this->recipients->admins(),
            new StaffAccountChanged(StaffAccountChanged::CREATED, (string) $actor->name, (string) $user->name, $role->label()),
        );

        return $user;
    }
}
