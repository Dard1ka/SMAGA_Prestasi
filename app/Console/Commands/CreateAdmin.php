<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Users\StaffRoles;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * First Admin account for a fresh production database (docs/DEPLOY.md §8). Production seeds no accounts.
 *
 *   docker compose -f docker/compose.prod.yaml exec app php artisan sth:create-admin
 *   … --name="Nama Operator" --username=operator --email=operator@sman3-smg.sch.id   (password is always asked)
 *
 * - Refuses weak passwords: min. 12 characters, upper + lower case, a digit and a symbol (+ the app's
 *   Password::defaults(), which in production also checks known leaked passwords).
 * - While an Admin exists it needs --additional; at most 3 Admin accounts (2 named + 1 break-glass,
 *   SECURITY §3m-9). Never makes a Kepala Sekolah (D-01: separate accounts).
 * - The operator runs this on the server, so the email counts as verified and the account is active; the
 *   Admin still has to set up TOTP 2FA at the first login (staff.2fa). Audited (`account.admin_created`, via cli).
 * - `--password-stdin` reads the password from STDIN for scripted installs (never pass it as an option: shell
 *   history).
 */
final class CreateAdmin extends Command
{
    protected $signature = 'sth:create-admin
        {--name= : Nama lengkap}
        {--username= : Nama pengguna untuk login}
        {--email= : Email (untuk kode verifikasi)}
        {--additional : Izinkan membuat Admin tambahan bila sudah ada Admin}
        {--password-stdin : Baca kata sandi dari STDIN (tanpa konfirmasi)}';

    protected $description = 'Buat akun Admin (pertama) dengan kata sandi kuat.';

    public function handle(): int
    {
        if (Role::query()->where('name', RoleName::Admin->value)->where('guard_name', 'web')->doesntExist()) {
            $this->error('Peran belum ada. Jalankan dulu: php artisan db:seed --class=RoleSeeder --force');

            return self::FAILURE;
        }

        $admins = StaffRoles::adminCount();

        if ($admins > 0 && ! $this->option('additional')) {
            $this->error("Sudah ada {$admins} akun Admin. Pakai --additional untuk menambah Admin (maks. ".StaffRoles::MAX_ADMINS.').');

            return self::FAILURE;
        }

        if ($admins >= StaffRoles::MAX_ADMINS) {
            $this->error('Sudah ada '.StaffRoles::MAX_ADMINS.' akun Admin. Nonaktifkan salah satu dulu.');

            return self::FAILURE;
        }

        $interactive = $this->input->isInteractive();
        $data = [
            'name' => $this->option('name') ?? ($interactive ? text('Nama lengkap', required: true) : null),
            'username' => $this->option('username') ?? ($interactive ? text('Nama pengguna', placeholder: 'operator', required: true) : null),
            'email' => $this->option('email') ?? ($interactive ? text('Email', required: true) : null),
        ];
        $data['username'] = mb_strtolower(trim((string) $data['username']));
        $data['email'] = mb_strtolower(trim((string) $data['email']));

        if ($this->option('password-stdin')) {
            $secret = rtrim((string) stream_get_contents(STDIN), "\r\n");
            $confirmation = $secret;
        } elseif ($interactive) {
            $secret = password('Kata sandi (min. 12, huruf besar + kecil, angka, simbol)', required: true);
            $confirmation = password('Ulangi kata sandi', required: true);
        } else {
            $this->error('Kata sandi wajib: jalankan secara interaktif atau pakai --password-stdin.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            [...$data, 'password' => $secret, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'min:3', 'max:150'],
                'username' => ['required', 'regex:'.StaffRoles::USERNAME_PATTERN, Rule::unique('users', 'username')],
                'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')],
                'password' => [
                    'required', 'string', 'confirmed',
                    Password::min(12)->max(64)->letters()->mixedCase()->numbers()->symbols(),
                    Password::defaults(),
                ],
            ],
            ['username.regex' => 'Nama pengguna 3–50 karakter: huruf kecil, angka, titik, garis bawah atau strip, diawali huruf.'],
            ['name' => 'nama', 'username' => 'nama pengguna', 'password' => 'kata sandi'],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($data, $secret): User {
            $user = new User;
            $user->forceFill([
                'name' => trim((string) $data['name']),
                'username' => $data['username'],
                'email' => $data['email'],
                'email_verified_at' => now(),
                'password' => Hash::make($secret),
                'status' => UserStatus::Active,
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->save();

            $user->assignRole(RoleName::Admin->value);
            StaffProfile::query()->create(['user_id' => $user->getKey(), 'position' => 'Admin STH']);

            activity('account')
                ->performedOn($user)
                ->event('account.admin_created')
                ->withProperties(['via' => 'cli', 'username' => $user->username])
                ->log('account.admin_created');

            return $user;
        });

        $this->info("Akun Admin \"{$user->username}\" dibuat. Saat login pertama, pasang verifikasi dua langkah (TOTP).");

        return self::SUCCESS;
    }
}
