<?php

declare(strict_types=1);

use App\Enums\CompetitionStatus;
use App\Enums\Level;
use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Enums\ResultType;
use App\Enums\WeightCategory;
use App\Models\Achievement;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Models\Classroom;
use App\Models\Competition;
use App\Models\File;
use App\Models\Letter;
use App\Models\OtpCode;
use App\Models\Publication;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\Setting;
use App\Models\StaffProfile;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\TalentBranch;
use App\Models\TalentProfile;
use App\Models\TalentType;
use App\Models\User;
use App\Models\WeightValue;
use App\Models\WeightVersion;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('creates every model through its factory', function (string $model) {
    $instance = $model::factory()->create();

    expect($instance->exists)->toBeTrue()
        ->and($model::query()->whereKey($instance->getKey())->exists())->toBeTrue();
})->with([
    User::class, Classroom::class, File::class, Student::class, StaffProfile::class, Competition::class,
    Registration::class, RegistrationMember::class, Letter::class, Report::class, ReportFile::class,
    Achievement::class, WeightVersion::class, WeightValue::class, Setting::class, TalentType::class,
    TalentBranch::class, TalentApplication::class, TalentProfile::class, Publication::class,
    AppreciationEvent::class, Appreciation::class, OtpCode::class,
]);

it('uses ULID keys for domain tables and bigint ids for users', function () {
    $student = Student::factory()->create();

    expect($student->getKey())->toBeString()->toHaveLength(26)
        ->and($student->user_id)->toBeInt();
});

it('gives new students the siswa role and computed archive dates', function () {
    $student = Student::factory()->create(['classroom_id' => Classroom::factory()->level(10), 'import_school_year' => '2026/2027']);

    expect($student->user()->first()->hasRole('siswa'))->toBeTrue()
        ->and($student->grade12_start_date->format('Y-m-d'))->toBe('2028-07-01')
        ->and($student->archive_at->format('Y-m-d'))->toBe('2030-01-01');
});

it('encrypts the phone number at rest and hides it from serialisation', function () {
    $student = Student::factory()->create(['phone' => '080000000001']);

    $raw = DB::table('students')->where('id', $student->getKey())->value('phone');

    expect($raw)->not->toContain('080000000001')
        ->and($student->fresh()->phone)->toBe('080000000001')
        ->and($student->toArray())->not->toHaveKeys(['phone', 'birth_date']);
});

it('adds the leader as a confirmed ketua member', function () {
    $registration = Registration::factory()->team()->withMembers(2, confirmed: false)->create();

    $leader = $registration->members()->where('student_id', $registration->leader_student_id)->first();

    expect($leader->role)->toBe(MemberRole::Ketua)
        ->and($leader->isConfirmed())->toBeTrue()
        ->and($registration->members()->pending()->count())->toBe(2)
        ->and($registration->status)->toBe(RegistrationStatus::MenungguKonfirmasi);
});

it('snapshots default weights on scored achievements', function () {
    $achievement = Achievement::factory()->scored(Level::Nasional, ResultType::Juara2, WeightCategory::A)->create();

    expect($achievement->points)->toBe('68.00')
        ->and($achievement->w_level)->toBe('80.00')
        ->and($achievement->w_result)->toBe('0.85');
});

it('resolves the active weight version as a domain weight table', function () {
    WeightVersion::factory()->withDefaultValues()->create(['effective_at' => now()->subYear()]);
    $newer = WeightVersion::factory()->withDefaultValues()->create(['effective_at' => now()->subDay()]);
    WeightVersion::factory()->create(['effective_at' => now()->addMonth()]);

    expect(WeightVersion::active()?->getKey())->toBe($newer->getKey())
        ->and($newer->toWeightTable()->level(Level::Provinsi))->toBe('60.00');
});

it('fills a random verification code when a letter is created', function () {
    $letter = Letter::factory()->create(['verification_code' => null]);

    expect($letter->verification_code)->toMatch('/^[0-9a-f]{32}$/');
});

it('starts new competitions in the verification queue', function () {
    $competition = new Competition(['name' => 'Lomba usulan siswa', 'organizer_name' => 'Panitia contoh']);
    $competition->submitted_by = User::factory()->create()->getKey();
    $competition->save();

    expect($competition->fresh()->status)->toBe(CompetitionStatus::MenungguVerifikasi)
        ->and(Competition::pendingVerification()->count())->toBe(1)
        ->and(Competition::published()->count())->toBe(0);
});

it('refuses mass assignment of state and verification columns', function (Closure $create) {
    expect($create)->toThrow(MassAssignmentException::class);
})->with([
    'competition status' => [fn () => Competition::query()->create(['name' => 'Lomba X', 'status' => 'dipublikasikan'])],
    // Weights are set by the Actions only (CLAUDE §3.6, D-146): a student's lomba gets them from OrganizerWeight.
    'competition weight category' => [fn () => Competition::query()->create(['name' => 'Lomba X', 'weight_category' => 'A'])],
    'competition event status' => [fn () => Competition::query()->create(['name' => 'Lomba X', 'event_status' => 'puspresnas'])],
    'user status' => [fn () => User::query()->create(['name' => 'A', 'password' => 'x', 'status' => 'active'])],
    'achievement points' => [fn () => Achievement::query()->create(['points' => '999.00'])],
]);
