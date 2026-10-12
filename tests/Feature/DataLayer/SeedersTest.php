<?php

declare(strict_types=1);

use App\Domain\Accounts\InitialPassword;
use App\Enums\Bidang;
use App\Enums\LetterType;
use App\Enums\Permission as PermissionName;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Enums\TalentTypeCode;
use App\Models\Achievement;
use App\Models\Classroom;
use App\Models\Competition;
use App\Models\File;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\Report;
use App\Models\Setting;
use App\Models\Student;
use App\Models\TalentBranch;
use App\Models\TalentType;
use App\Models\User;
use App\Models\WeightValue;
use App\Models\WeightVersion;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SignatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('seeds reference data idempotently and without demo data outside local', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Classroom::query()->count())->toBe(36)
        ->and(TalentType::query()->count())->toBe(6)
        ->and(TalentBranch::query()->count())->toBe(35)
        ->and(WeightVersion::query()->count())->toBe(1)
        ->and(WeightValue::query()->count())->toBe(20)
        ->and(Role::query()->count())->toBe(7)
        ->and(Permission::query()->count())->toBe(count(PermissionName::cases()))
        ->and(User::query()->count())->toBe(0);
});

it('seeds the settings defaults and never the initial-password pattern', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Setting::get(SettingKey::ReportDeadlineDays))->toBe(45)
        ->and(Setting::get(SettingKey::ArchiveMonthsAfterGrade12))->toBe(18)
        ->and(Setting::get(SettingKey::ActivationWindowDays))->toBe(14)
        ->and(Setting::query()->whereKey(SettingKey::LetterTemplate->value)->exists())->toBeTrue()
        ->and(Setting::query()->whereKey('otp_test_mode')->exists())->toBeFalse() // env flag only (D-15), never a setting
        ->and(Setting::get(SettingKey::LetterFormatSiswa))->toBe('400.3.8/{seq}/MRD/{roman_month}/{year}')
        ->and(Setting::query()->find(SettingKey::PublicIndexing->value)?->value)->toBeFalse()
        ->and(Setting::query()->find(SettingKey::PublicCertificates->value)?->value)->toBeFalse()
        ->and(Setting::query()->pluck('value')->implode(' '))->not->toContain(InitialPassword::LEGACY_PREFIX);
});

it('registers the dummy signature image on the private disk', function () {
    $this->seed(DatabaseSeeder::class);

    $file = File::query()->where('path', SignatureSeeder::STORAGE_PATH)->firstOrFail();

    Storage::disk('local')->assertExists(SignatureSeeder::STORAGE_PATH);
    expect(Setting::get(SettingKey::SignatureFileId))->toBe($file->getKey())
        ->and($file->mime)->toBe('image/png');
});

it('maps talent types to bidang (D-22)', function () {
    $this->seed(DatabaseSeeder::class);

    $bidang = TalentType::query()->get()->mapWithKeys(fn (TalentType $t) => [$t->code->value => $t->bidang]);

    expect($bidang[TalentTypeCode::Osn->value])->toBe(Bidang::Sains)
        ->and($bidang[TalentTypeCode::Olpain->value])->toBe(Bidang::Sains)
        ->and($bidang[TalentTypeCode::Riset->value])->toBe(Bidang::Riset)
        ->and($bidang[TalentTypeCode::Fls3n->value])->toBe(Bidang::SeniBudaya)
        ->and($bidang[TalentTypeCode::Debat->value])->toBe(Bidang::SeniBudaya)
        ->and($bidang[TalentTypeCode::O2sn->value])->toBe(Bidang::Olahraga)
        ->and(TalentBranch::query()->where('name', 'Kreativitas Musik Tradisional')->value('team_size'))->toBe(5);
});

it('gives Admin every permission except approving registrations (D-01)', function () {
    $this->seed(DatabaseSeeder::class);

    $admin = Role::findByName(RoleName::Admin->value, 'web');
    $kepsek = Role::findByName(RoleName::KepalaSekolah->value, 'web');

    expect($admin->hasPermissionTo(PermissionName::StudentImport->value))->toBeTrue()
        ->and($admin->hasPermissionTo(PermissionName::RegistrationApprove->value))->toBeFalse()
        ->and($kepsek->hasPermissionTo(PermissionName::RegistrationApprove->value))->toBeTrue()
        ->and(Role::findByName(RoleName::Kesiswaan->value, 'web')->hasPermissionTo(PermissionName::StudentImport->value))->toBeTrue();
});

it('builds the local demo data set with the spec leaderboard and queues', function () {
    app()['env'] = 'local';
    $this->seed(DatabaseSeeder::class);
    app()['env'] = 'testing';

    $totals = Achievement::query()->selectRaw('student_id, SUM(points) as total')->groupBy('student_id')->pluck('total', 'student_id');
    $students = Student::query()->with('user')->get()->keyBy(fn (Student $s) => $s->user->name);
    $points = fn (string $name) => round((float) $totals[$students[$name]->getKey()], 2);

    expect($points('Raka Pradipta'))->toBe(312.0)
        ->and($points('Salsabila Kirana'))->toBe(268.0)
        ->and($points('Bima Satria Wicaksono'))->toBe(240.0)
        ->and($points('Aurelia Maharani'))->toBe(196.0)
        ->and($points('Nadia Putri Anindya'))->toBe(104.0)
        ->and($points('Ardiansyah Nur Hakim'))->toBe(236.0);

    $activeRanking = $totals->only(Student::active()->pluck('id')->all())->sortDesc()->keys()->values();
    $nadia = $students['Nadia Putri Anindya'];

    expect($activeRanking->search($nadia->getKey()) + 1)->toBe(14)
        ->and(Registration::awaitingPrincipal()->count())->toBe(5)
        ->and(Registration::awaitingLetters()->count())->toBe(3)
        ->and(Report::awaitingVerification()->count())->toBe(4)
        ->and(Competition::pendingVerification()->count())->toBe(3)
        ->and(Competition::published()->count())->toBe(8)
        ->and(Letter::lastSequence(LetterType::Siswa, 2026))->toBe(312)
        ->and(Letter::lastSequence(LetterType::Guru, 2026))->toBe(1680)
        ->and($nadia->nis)->toBe('225226201')
        ->and($nadia->user->must_change_password)->toBeTrue()
        ->and(Hash::check(app(InitialPassword::class)->make(), $nadia->user->password))->toBeTrue()
        ->and(User::query()->where('username', 'operator')->first()->hasRole(RoleName::Admin->value))->toBeTrue();

    // D-125: a demo organizer of the type "Lainnya" carries the typed kind; every other type has none.
    expect(Competition::query()->where('organizer_type', 'lainnya')->pluck('organizer_type_other')->unique()->all())->toBe(['Sanggar seni'])
        ->and(Competition::query()->where('organizer_type', '!=', 'lainnya')->whereNotNull('organizer_type_other')->count())->toBe(0);
});
