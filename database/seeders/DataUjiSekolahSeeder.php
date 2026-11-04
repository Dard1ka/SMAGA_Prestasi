<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\StaffProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Data awal untuk server sekolah (salinan smaga_server; lead 21 dan 27 Okt 2026). Hanya untuk database KOSONG:
 * data referensi (peran, kelas, talenta, bobot, pengaturan, contoh tanda tangan di luar production), lalu
 * 6 akun staf asli dari sekolah (email mereka, nama = nama peran, aktif) dan 2 guru uji (alamat uji
 * <kotak surat>+<peran>@...). Tidak ada siswa (lead, 4 Nov 2026: sekolah menguji dari nol lewat Import siswa; sembilan
 * siswa uji yang dulu dibuat di sini sudah dihapus), lomba, pendaftaran atau prestasi. Daftar akun: AKUN-UJI.md (file
 * lokal).
 *
 * Email asli staf, kata sandi akun uji, dan kotak surat alamat uji ada di database/seeders/data/akun-sekolah.php: file
 * lokal yang tidak pernah masuk git (.gitignore). Tanpa file itu seeder memakai contoh fiktif akun-sekolah.example.php.
 *
 *   php artisan migrate --force
 *   php artisan db:seed --class=DataUjiSekolahSeeder --force
 *
 * Saklar email staf (lead, 27 Okt 2026): dengan STH_SEED_STAF_UJI=1 di environment perintah, keenam staf sekolah
 * memakai alamat uji <kotak surat>+<username>@..., sehingga uji coba tidak mengirim email ke staf sungguhan;
 * tanpa saklar mereka memakai alamat aslinya. EmailStafSeeder mengganti alamat itu kemudian tanpa menghapus data:
 *
 *   docker compose exec -e STH_SEED_STAF_UJI=1 app php artisan db:seed --class=DataUjiSekolahSeeder --force
 *   docker compose exec -e STH_SEED_STAF_UJI=1 app php artisan db:seed --class=EmailStafSeeder --force   (ke uji)
 *   docker compose exec app php artisan db:seed --class=EmailStafSeeder --force                          (ke asli)
 *
 * Seeder ini hanya ada di smaga_server (tidak di Smaga4). Alamat email staf sungguhan dan akun pengirim sekolah tidak
 * boleh masuk repo (CLAUDE.md aturan 2), jadi semuanya dibaca dari file data lokal di atas.
 */
class DataUjiSekolahSeeder extends Seeder
{
    use WithoutModelEvents;

    /** The school's account data (local, never in git) and the fictional example used when it is missing. */
    public const DATA_FILE = 'seeders/data/akun-sekolah.php';

    public const DATA_EXAMPLE = 'seeders/data/akun-sekolah.example.php';

    /**
     * username => [nama, peran, sapaan, jabatan]. The six staff accounts are the school's real staff (their emails come
     * from the data file, staff_emails; names = the role name until the real names are known); the two guru are trial
     * accounts without a real address. Tata Usaha has two accounts: every TU notice goes to both (ApproveRegistration).
     */
    public const STAFF = [
        'admin' => ['Admin', RoleName::Admin, null, 'Operator STH'],
        'kepsek' => ['Kepala Sekolah', RoleName::KepalaSekolah, null, 'Kepala Sekolah'],
        'humas' => ['Humas', RoleName::Humas, null, 'Humas'],
        'kesiswaan' => ['Kesiswaan', RoleName::Kesiswaan, null, 'Wakil Kepala Sekolah Bidang Kesiswaan'],
        'tu1' => ['Tata Usaha 1', RoleName::TataUsaha, null, 'Staf Tata Usaha'],
        'tu2' => ['Tata Usaha 2', RoleName::TataUsaha, null, 'Staf Tata Usaha'],
        'guru1' => ['Test guru 1', RoleName::Guru, 'Bu', 'Guru pembimbing'],
        'guru2' => ['Test guru 2', RoleName::Guru, 'Pak', 'Guru pembimbing'],
    ];

    /** The env switch: 1 / true / yes / on = the staff use the trial mailbox. Read per command, never cached. */
    public const TRIAL_SWITCH = 'STH_SEED_STAF_UJI';

    public static function trialStaffMail(): bool
    {
        return filter_var(env(self::TRIAL_SWITCH, false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The local data file, or the fictional example when it is missing (a fresh clone).
     *
     * @return array{trial_mailbox: string, trial_domain: string, staff_password: string, guru_password?: string, staff_emails: array<string, string>}
     */
    public static function data(): array
    {
        $file = database_path(self::DATA_FILE);

        return require is_file($file) ? $file : database_path(self::DATA_EXAMPLE);
    }

    /** A trial address: <trial_mailbox>+<tag>@<trial_domain>, e.g. the guru1 inbox. */
    public static function trialAddress(string $tag): string
    {
        $data = self::data();

        return "{$data['trial_mailbox']}+{$tag}@{$data['trial_domain']}";
    }

    /** The address a staff account gets: the real one, or the trial mailbox while the switch is on (guru: always trial). */
    public static function staffEmail(string $username, ?string $realEmail): string
    {
        return self::trialStaffMail() || $realEmail === null ? self::trialAddress($username) : $realEmail;
    }

    /** The six real staff (the STAFF usernames the data file gives an email). @return array<string, string> username => real email */
    public static function realStaff(): array
    {
        return array_intersect_key(self::data()['staff_emails'], self::STAFF);
    }

    /** The password of the six real staff accounts. */
    public static function staffPassword(): string
    {
        return self::data()['staff_password'];
    }

    /** The password of the trial guru accounts (SV3: the staff got their own; a data file without it gives both one). */
    public static function guruPassword(): string
    {
        $data = self::data();

        return $data['guru_password'] ?? $data['staff_password'];
    }

    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            ClassroomSeeder::class,
            TalentSeeder::class,
            WeightSeeder::class,
            SettingSeeder::class,
            SignatureSeeder::class,
        ]);

        if (User::query()->exists()) {
            throw new RuntimeException('Data uji hanya untuk database kosong: sudah ada akun. Jalankan di database baru.');
        }

        DB::transaction(function (): void {
            $this->seedStaff();
        });

        // The signer name of the letters stays the placeholder "[Nama Kepala Sekolah]": the Admin types the real name
        // in Pengaturan (the account name is only the role name).

        $this->command?->info(sprintf('Data uji: %d akun staf dan guru, tanpa siswa (lihat AKUN-UJI.md).', count(self::STAFF)));
        $this->command?->info(self::trialStaffMail()
            ? 'Email staf: UJI ('.self::trialAddress('<username>').'). Ganti ke asli: db:seed --class=EmailStafSeeder tanpa saklar.'
            : 'Email staf: ASLI (alamat dari sekolah).');
    }

    private function seedStaff(): void
    {
        $now = CarbonImmutable::now();
        $realStaff = self::realStaff();
        $staffPassword = self::staffPassword();
        $guruPassword = self::guruPassword();

        foreach (self::STAFF as $username => [$name, $role, $honorific, $position]) {
            $user = new User;
            $user->forceFill([
                'name' => $name,
                'username' => $username,
                'email' => self::staffEmail($username, $realStaff[$username] ?? null),
                'email_verified_at' => $now,
                'password' => array_key_exists($username, $realStaff) ? $staffPassword : $guruPassword,
                'status' => UserStatus::Active,
                'must_change_password' => false,
                'password_changed_at' => $now,
                'activated_at' => $now,
            ])->save();
            $user->assignRole($role->value);

            StaffProfile::query()->create([
                'user_id' => $user->getKey(),
                'honorific' => $honorific,
                'nip' => null,
                'rank' => null,
                'position' => $position,
            ]);
        }
    }
}
