<?php

declare(strict_types=1);

namespace Tests\Feature\KepalaSekolah;

use App\Enums\FilePurpose;
use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Models\Classroom;
use App\Models\Competition;
use App\Models\File;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Setting;
use App\Models\StaffProfile;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\SignatureSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Auth\AuthTestHelpers;

/**
 * Shared fixtures for tests/Feature/{KepalaSekolah,TataUsaha} (W2-C). Fictional data only
 * (DESIGN-SPEC-v2 §5 names). Staff get a confirmed TOTP so `staff.2fa` lets them through like in production.
 */
final class W2cFixtures
{
    public static function staff(RoleName $role, array $attributes = []): User
    {
        $user = AuthTestHelpers::staff($role, array_merge(['email' => 'w2c.'.$role->value.'.'.uniqid().'@example.test'], $attributes));
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user->fresh();
    }

    public static function mentor(string $name = 'Hendra Kusuma, S.Pd., M.Si.'): User
    {
        $guru = self::staff(RoleName::Guru, ['name' => $name]);

        StaffProfile::query()->create([
            'user_id' => $guru->getKey(),
            'honorific' => 'Pak',
            'nip' => '198001012005011001',
            'rank' => 'Penata, III/c',
            'position' => 'Guru',
        ]);

        return $guru->fresh();
    }

    public static function student(string $name, string $class = 'XI-4', ?string $nis = null): Student
    {
        $classroom = Classroom::query()->where('name', $class)->first()
            ?? Classroom::factory()->level((int) (str_starts_with($class, 'XII') ? 12 : (str_starts_with($class, 'XI') ? 11 : 10)))->create(['name' => $class]);

        $student = Student::factory()->create(array_filter([
            'classroom_id' => $classroom->getKey(),
            'nis' => $nis,
        ]));
        $student->user->forceFill(['name' => $name])->save();

        return $student->fresh();
    }

    /** Student who finished the first-login activation (so `activated` lets them through). */
    public static function activeStudent(string $name, string $class = 'XI-4'): Student
    {
        $student = self::student($name, $class);
        $student->user->forceFill([
            'email' => 'siswa.'.uniqid().'@example.test',
            'email_verified_at' => now(),
            'status' => UserStatus::Active,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        return $student->fresh();
    }

    /**
     * A registration in $status. First name = ketua; everyone confirmed. Mentor optional.
     *
     * @param  list<string>  $names
     * @param  array<string, mixed>  $competition
     */
    public static function registration(
        RegistrationStatus $status = RegistrationStatus::MenungguKepsek,
        array $names = ['Nadia Putri Anindya'],
        ?User $mentor = null,
        array $competition = [],
    ): Registration {
        $students = array_map(fn (string $name): Student => self::student($name), $names);
        $isTeam = count($students) > 1;

        $lomba = Competition::factory()->published()->state($isTeam ? ['participation' => 'tim', 'max_team_size' => 5] : [])->create(array_merge([
            'name' => 'National Science and Invention Fair (NSIF) 2026',
            'organizer_name' => 'Penyelenggara swasta',
            'bidang' => 'riset',
            'level' => 'nasional',
            'mode' => 'luring',
            'location' => 'Kota Semarang',
            'starts_on' => now()->addDays(20)->format('Y-m-d'),
            'final_on' => now()->addDays(20)->format('Y-m-d'),
        ], $competition));

        $registration = Registration::factory()->create([
            'competition_id' => $lomba->getKey(),
            'leader_student_id' => $students[0]->getKey(),
            'type' => $isTeam ? RegistrationType::Tim : RegistrationType::Individu,
            'mentor_id' => $mentor?->getKey(),
            'status' => $status,
        ]);

        foreach (array_slice($students, 1) as $student) {
            $member = new RegistrationMember([
                'registration_id' => $registration->getKey(),
                'student_id' => $student->getKey(),
                'invited_at' => now(),
            ]);
            $member->forceFill(['role' => MemberRole::Anggota, 'confirmed_at' => now()])->save();
        }

        return $registration->fresh();
    }

    /** The dummy signature + stamp PNG on the (faked) private disk, referenced by settings. */
    public static function signature(): File
    {
        $bytes = SignatureSeeder::generatePng();
        Storage::disk('local')->put('ttd/ttd-uji.png', $bytes);

        $file = new File;
        $file->forceFill([
            'disk' => 'local',
            'path' => 'ttd/ttd-uji.png',
            'original_name' => 'ttd-stempel-contoh.png',
            'mime' => 'image/png',
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'purpose' => FilePurpose::Signature,
        ])->save();

        Setting::put(SettingKey::SignatureFileId, $file->getKey());

        return $file;
    }
}
