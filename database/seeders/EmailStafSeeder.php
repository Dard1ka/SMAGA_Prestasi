<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Saklar email staf (smaga_server, lead 27 Okt 2026): mengganti alamat email keenam staf sekolah di database yang sudah
 * berisi, tanpa menghapus data lain. Dengan STH_SEED_STAF_UJI=1 → alamat uji <kotak surat>+<username>@...; tanpa
 * saklar → alamat asli dari sekolah. Keduanya dari file lokal database/seeders/data/akun-sekolah.php
 * (DataUjiSekolahSeeder::data()). Guru dan siswa uji tidak disentuh.
 *
 *   docker compose exec -e STH_SEED_STAF_UJI=1 app php artisan db:seed --class=EmailStafSeeder --force   (ke uji)
 *   docker compose exec app php artisan db:seed --class=EmailStafSeeder --force                          (ke asli)
 *
 * Alamat yang diganti dianggap terverifikasi (dipasang oleh pengelola, bukan diketik penyerang), jadi akunnya langsung
 * menerima email notifikasi. Perubahan tercatat di log audit (model User mencatat perubahan email). Alamat yang diubah
 * sendiri oleh staf di Keamanan akun ikut tertimpa: jalankan hanya selama masa uji.
 */
class EmailStafSeeder extends Seeder
{
    public function run(): void
    {
        $trial = DataUjiSekolahSeeder::trialStaffMail();
        $now = CarbonImmutable::now();

        $this->command?->info($trial ? 'Ganti email staf ke UJI ('.DataUjiSekolahSeeder::trialAddress('<username>').'):' : 'Ganti email staf ke ASLI:');

        foreach (DataUjiSekolahSeeder::realStaff() as $username => $realEmail) {
            $user = User::query()->where('username', $username)->first();

            if ($user === null) {
                $this->command?->warn("  {$username}: akun tidak ada, dilewati.");

                continue;
            }

            $email = DataUjiSekolahSeeder::staffEmail($username, $realEmail);

            if ($user->email === $email) {
                $this->command?->line("  {$username}: sudah {$email}");

                continue;
            }

            $user->forceFill(['email' => $email, 'email_verified_at' => $now])->save();
            $this->command?->line("  {$username}: {$email}");
        }
    }
}
