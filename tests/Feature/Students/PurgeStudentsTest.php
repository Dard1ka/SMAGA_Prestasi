<?php

declare(strict_types=1);

use App\Actions\Students\PurgeStudents;
use App\Enums\FilePurpose;
use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Enums\ReportFileKind;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Livewire\Publik\Data\PublicAchievements;
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
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\TalentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
 * smaga_server, the lead's decision of 5 Nov 2026 (trash "Sampah"): PurgeStudents deletes a SET of students for good
 * (called by `sth:sampah:hapus-permanen` for the students whose 7 days in the trash are over). Everything only theirs
 * goes, a registration whose every member is purged goes, a mixed team stays with a new ketua, a lomba they proposed
 * moves to the Admin, files something kept still points at stay. Fictional people only (factories).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
    $this->travelTo(now()->setDate(2026, 11, 5)->setTime(9, 0));
    $this->classroom = Classroom::factory()->create(['name' => 'X-1', 'level' => 10])->getKey();
});

/** @return array<string, User> a disabled Admin first (lower id), then an active one, and the other staff roles */
function pgsStaff(bool $withAdmin = true): array
{
    return array_filter([
        'admin_disabled' => $withAdmin ? User::factory()->disabled()->staff(RoleName::Admin)->create() : null,
        'admin' => $withAdmin ? User::factory()->staff(RoleName::Admin)->create() : null,
        'kesiswaan' => User::factory()->staff(RoleName::Kesiswaan)->create(),
        'kepsek' => User::factory()->staff(RoleName::KepalaSekolah)->create(),
        'tu' => User::factory()->staff(RoleName::TataUsaha)->create(),
        'humas' => User::factory()->staff(RoleName::Humas)->create(),
        'guru' => User::factory()->staff(RoleName::Guru)->create(),
    ]);
}

function pgsStudent(): Student
{
    return Student::factory()->activated()->create(['classroom_id' => test()->classroom]);
}

function pgsUser(Student $student): User
{
    return User::withTrashed()->findOrFail($student->user_id);
}

/** Puts students in the trash as TrashStudents does: `students` and `users` rows soft-deleted. */
function pgsTrash(Student ...$students): void
{
    foreach ($students as $student) {
        DB::table('students')->where('id', $student->getKey())->update(['deleted_at' => now()]);
        DB::table('users')->where('id', $student->user_id)->update(['deleted_at' => now()]);
    }
}

/** A file row with bytes on the test's own private disk. */
function pgsFile(FilePurpose $purpose, ?User $owner = null): File
{
    $file = File::factory()->purpose($purpose)->create(['owner_user_id' => $owner?->getKey()]);
    Storage::disk('local')->put($file->path, 'bytes of '.$purpose->value);

    return $file;
}

/** @param list<Student> $students */
function pgsPurge(array $students): array
{
    return app(PurgeStudents::class)->handle(array_map(fn (Student $s): string => (string) $s->getKey(), $students));
}

/** A session, a bell row, an OTP code, an email notice and a password-reset row of the account. */
function pgsAccountRows(User $user, string $tag): void
{
    DB::table('sessions')->insert([
        'id' => 'pgs-session-'.$tag,
        'user_id' => $user->getKey(),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => base64_encode('x'),
        'last_activity' => now()->getTimestamp(),
    ]);
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\Example',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => json_encode(['message' => 'Contoh']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    OtpCode::factory()->create(['user_id' => $user->getKey()]);
    DB::table('email_notices')->insert([
        'id' => (string) Str::ulid(),
        'user_id' => $user->getKey(),
        'kind' => 'bound',
        'sent_to_masked' => 'n***@example.test',
        'expires_at' => now()->addDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'pgs-'.$tag, 'created_at' => now()]);
}

/**
 * A finished team registration of $lomba led by $leader with anggota rows ([Student, 'confirmed'|'pending'|'declined',
 * created_at]), a verified report the leader submitted with a certificate the leader uploaded, and an achievement for
 * the leader and every confirmed anggota.
 *
 * @param  list<array{0: Student, 1: string, 2: string}>  $members
 * @param  array<string, User>  $staff
 * @return array{registration: Registration, report: Report, certificate: File, achievements: array<string, Achievement>}
 */
function pgsTeam(Competition $lomba, Student $leader, array $members, array $staff): array
{
    $registration = Registration::factory()->team()->status(RegistrationStatus::Selesai)->create([
        'competition_id' => $lomba->getKey(),
        'leader_student_id' => $leader->getKey(),
        'mentor_id' => $staff['guru']->getKey(),
        'principal_decision_by' => $staff['kepsek']->getKey(),
    ]);
    $achievers = [$leader];

    foreach ($members as [$student, $state, $at]) {
        RegistrationMember::factory()->create([
            'registration_id' => $registration->getKey(),
            'student_id' => $student->getKey(),
            'role' => MemberRole::Anggota,
            'confirmed_at' => $state === 'confirmed' ? now() : null,
            'declined_at' => $state === 'declined' ? now() : null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        if ($state === 'confirmed') {
            $achievers[] = $student;
        }
    }

    $report = Report::factory()->verified()->create([
        'registration_id' => $registration->getKey(),
        'submitted_by' => $leader->user_id,
        'verified_by' => $staff['kesiswaan']->getKey(),
    ]);
    $certificate = pgsFile(FilePurpose::ReportEvidence, pgsUser($leader));
    ReportFile::factory()->create(['report_id' => $report->getKey(), 'kind' => ReportFileKind::Sertifikat, 'file_id' => $certificate->getKey()]);

    $achievements = [];

    foreach ($achievers as $student) {
        $achievements[(string) $student->getKey()] = Achievement::factory()->create([
            'report_id' => $report->getKey(),
            'student_id' => $student->getKey(),
            'competition_id' => $lomba->getKey(),
        ]);
    }

    return ['registration' => $registration, 'report' => $report, 'certificate' => $certificate, 'achievements' => $achievements];
}

/** Every row of one student that a purge of OTHER students must leave as it was. */
function pgsRowsOf(Student $student): array
{
    $user = (int) $student->user_id;
    $rows = fn (string $table, string $column, mixed $value, array $columns): array => DB::table($table)
        ->where($column, $value)->orderBy($columns[0])->get($columns)->map(fn (object $row): array => (array) $row)->all();

    return [
        'student' => $rows('students', 'id', $student->getKey(), ['id', 'user_id', 'classroom_id', 'deleted_at']),
        'user' => $rows('users', 'id', $user, ['id', 'status', 'deleted_at']),
        'sessions' => $rows('sessions', 'user_id', $user, ['id']),
        'bell' => $rows('notifications', 'notifiable_id', $user, ['id']),
        'roles' => $rows('model_has_roles', 'model_id', $user, ['role_id']),
        'members' => $rows('registration_members', 'student_id', $student->getKey(), ['id', 'registration_id', 'role', 'confirmed_at']),
        'leads' => $rows('registrations', 'leader_student_id', $student->getKey(), ['id', 'status']),
        'achievements' => $rows('achievements', 'student_id', $student->getKey(), ['id', 'report_id', 'points']),
        'talent' => $rows('talent_applications', 'student_id', $student->getKey(), ['id', 'evidence_file_id']),
    ];
}

/** Row counts and the moved columns of every table a run may touch, to prove that nothing was written. */
function pgsSnapshot(): array
{
    $tables = ['users', 'students', 'registrations', 'registration_members', 'registration_reminders', 'letters',
        'reports', 'report_files', 'achievements', 'publications', 'appreciations', 'talent_applications',
        'talent_profiles', 'talent_profile_files', 'files', 'competitions', 'sessions', 'notifications',
        'model_has_roles', 'model_has_permissions', 'otp_codes', 'email_notices', 'password_reset_tokens', 'activity_log'];

    $snapshot = [];

    foreach ($tables as $table) {
        $snapshot[$table] = DB::table($table)->count();
    }

    $snapshot['submitters'] = DB::table('competitions')->orderBy('id')->pluck('submitted_by')->all();
    $snapshot['owners'] = DB::table('files')->orderBy('id')->pluck('owner_user_id')->all();
    $snapshot['leaders'] = DB::table('registrations')->orderBy('id')->pluck('leader_student_id')->all();
    $snapshot['roles'] = DB::table('registration_members')->orderBy('id')->pluck('role')->all();
    $snapshot['reporters'] = DB::table('reports')->orderBy('id')->pluck('submitted_by')->all();

    return $snapshot;
}

/** @param list<Student> $students */
function pgsSecrets(array $students): array
{
    $secrets = [];

    foreach ($students as $student) {
        $user = pgsUser($student);
        $secrets = [...$secrets, (string) $user->name, (string) $user->email, (string) $student->nisn, (string) $student->nis];
    }

    return array_values(array_filter($secrets));
}

function pgsCounts(array $expected): array
{
    return array_replace(array_fill_keys(PurgeStudents::COUNT_KEYS, 0), $expected);
}

it('removes a lone trashed student and everything only theirs, and leaves other students alone', function (): void {
    $staff = pgsStaff();
    $lone = pgsStudent();
    $other = pgsStudent();
    $loneUser = pgsUser($lone);
    pgsAccountRows($loneUser, 'lone');
    pgsAccountRows(pgsUser($other), 'other');
    DB::table('model_has_permissions')->insert([
        'permission_id' => DB::table('permissions')->value('id'),
        'model_type' => $loneUser->getMorphClass(),
        'model_id' => $loneUser->getKey(),
    ]);

    // Talent: a choice with evidence, a soft-deleted choice, a profile with a file; evidence left behind by a revision.
    $talentEvidence = pgsFile(FilePurpose::TalentEvidence, $loneUser);
    TalentApplication::factory()->create(['student_id' => $lone->getKey(), 'evidence_file_id' => $talentEvidence->getKey()]);
    TalentApplication::factory()->create(['student_id' => $lone->getKey()])->delete();
    $profile = TalentProfile::factory()->create(['student_id' => $lone->getKey()]);
    $profileFile = pgsFile(FilePurpose::TalentEvidence, $loneUser);
    DB::table('talent_profile_files')->insert([
        'id' => (string) Str::ulid(),
        'talent_profile_id' => $profile->getKey(),
        'file_id' => $profileFile->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $orphan = pgsFile(FilePurpose::ReportEvidence, $loneUser);

    // An individual registration each.
    $lomba = Competition::factory()->published()->create(['submitted_by' => $staff['kesiswaan']->getKey()]);
    $loneRegistration = Registration::factory()->create(['competition_id' => $lomba->getKey(), 'leader_student_id' => $lone->getKey()]);
    Registration::factory()->create(['competition_id' => $lomba->getKey(), 'leader_student_id' => $other->getKey()]);
    TalentApplication::factory()->create(['student_id' => $other->getKey()]);

    pgsTrash($lone);
    $secrets = pgsSecrets([$lone]);
    $otherBefore = pgsRowsOf($other);
    $logged = Activity::query()->count();
    $version = (int) Cache::get(PublicAchievements::VERSION_KEY, 1);

    $counts = pgsPurge([$lone]);

    expect(array_keys($counts))->toBe(PurgeStudents::COUNT_KEYS)
        ->and($counts)->toBe(pgsCounts([
            'students' => 1,
            'users' => 1,
            'registrations_deleted' => 1,
            'members' => 1,
            'talent_applications' => 2,
            'talent_profiles' => 1,
            'files' => 3,
        ]));

    expect(DB::table('students')->where('id', $lone->getKey())->exists())->toBeFalse()
        ->and(DB::table('users')->where('id', $loneUser->getKey())->exists())->toBeFalse()
        ->and(DB::table('registrations')->where('id', $loneRegistration->getKey())->exists())->toBeFalse()
        ->and(DB::table('talent_profile_files')->where('talent_profile_id', $profile->getKey())->exists())->toBeFalse();

    foreach (['sessions' => 'user_id', 'otp_codes' => 'user_id', 'email_notices' => 'user_id', 'notifications' => 'notifiable_id', 'model_has_roles' => 'model_id', 'model_has_permissions' => 'model_id'] as $table => $column) {
        expect(DB::table($table)->where($column, $loneUser->getKey())->count())->toBe(0, $table);
    }

    expect(DB::table('password_reset_tokens')->where('email', $loneUser->email)->exists())->toBeFalse();

    foreach ([$talentEvidence, $profileFile, $orphan] as $file) {
        expect(DB::table('files')->where('id', $file->getKey())->exists())->toBeFalse()
            ->and(Storage::disk('local')->exists($file->path))->toBeFalse();
    }

    // The other student is untouched; no audit row here (the command writes one); the public pages' cache is flushed.
    expect(pgsRowsOf($other))->toBe($otherBefore)
        ->and(DB::table('password_reset_tokens')->where('token', 'pgs-other')->exists())->toBeTrue()
        ->and(Activity::query()->count())->toBe($logged)
        ->and((int) Cache::get(PublicAchievements::VERSION_KEY, 1))->toBe($version + 1);

    expect($secrets)->not->toBe([]);

    foreach ($secrets as $secret) {
        expect(str_contains((string) json_encode($counts), $secret))->toBeFalse();
    }
});

it('deletes a registration whose every member is in the set with its letters, reports and files, bytes after the commit', function (): void {
    $staff = pgsStaff();
    [$a, $b, $c, $outsider] = [pgsStudent(), pgsStudent(), pgsStudent(), pgsStudent()];

    // The signature setting and an older signature image a revoked letter was signed with: both always stay.
    $signature = pgsFile(FilePurpose::Signature, $staff['admin']);
    $oldSignature = pgsFile(FilePurpose::Signature, $staff['admin']);
    Setting::put(SettingKey::SignatureFileId, $signature->getKey());

    $juknis = pgsFile(FilePurpose::Juknis, $staff['kesiswaan']);
    $lomba = Competition::factory()->published()->team()->create(['submitted_by' => $staff['kesiswaan']->getKey(), 'juknis_file_id' => $juknis->getKey()]);

    // B never answered the invitation: any member row counts.
    $team = pgsTeam($lomba, $a, [[$b, 'pending', '2026-11-05 08:00:00'], [$c, 'confirmed', '2026-11-05 08:30:00']], $staff);
    $registration = $team['registration'];
    DB::table('registration_reminders')->insert(['registration_id' => $registration->getKey(), 'kind' => 'report_after_3', 'sent_at' => now()]);

    $pdfs = [pgsFile(FilePurpose::Letter, $staff['tu']), pgsFile(FilePurpose::Letter, $staff['tu']), pgsFile(FilePurpose::Letter, $staff['tu'])];
    $revoked = Letter::factory()->create([
        'registration_id' => $registration->getKey(),
        'version' => 1,
        'file_id' => $pdfs[0]->getKey(),
        'signature_file_id' => $oldSignature->getKey(),
        'generated_by' => $staff['tu']->getKey(),
        'revoked_at' => now(),
        'revoked_by' => $staff['tu']->getKey(),
        'revoke_reason' => 'Nomor salah ketik.',
    ]);
    Letter::factory()->create([
        'registration_id' => $registration->getKey(),
        'version' => 2,
        'replaces_letter_id' => $revoked->getKey(),
        'file_id' => $pdfs[1]->getKey(),
        'signature_file_id' => $signature->getKey(),
        'generated_by' => $staff['tu']->getKey(),
        'sent_at' => now(),
    ]);
    Letter::factory()->guru()->create([
        'registration_id' => $registration->getKey(),
        'file_id' => $pdfs[2]->getKey(),
        'signature_file_id' => $signature->getKey(),
        'generated_by' => $staff['tu']->getKey(),
    ]);

    // A photo of the leader, and the announcement that points at the lomba's own juknis (stays: the lomba needs it).
    $photo = pgsFile(FilePurpose::ReportEvidence, pgsUser($a));
    ReportFile::factory()->create(['report_id' => $team['report']->getKey(), 'kind' => ReportFileKind::Foto, 'file_id' => $photo->getKey()]);
    ReportFile::factory()->create(['report_id' => $team['report']->getKey(), 'kind' => ReportFileKind::Pengumuman, 'file_id' => $juknis->getKey()]);

    $flyer = pgsFile(FilePurpose::Flyer, $staff['humas']);
    Publication::factory()->create([
        'achievement_id' => $team['achievements'][(string) $a->getKey()]->getKey(),
        'created_by' => $staff['humas']->getKey(),
        'flyer_file_id' => $flyer->getKey(),
    ]);
    $event = AppreciationEvent::factory()->create();
    Appreciation::factory()->create(['achievement_id' => $team['achievements'][(string) $c->getKey()]->getKey(), 'appreciation_event_id' => $event->getKey()]);

    // Another student's registration of the same lomba.
    Registration::factory()->create(['competition_id' => $lomba->getKey(), 'leader_student_id' => $outsider->getKey()]);
    pgsTrash($a, $b, $c);
    $outsiderBefore = pgsRowsOf($outsider);

    $counts = pgsPurge([$a, $b, $c]);

    expect($counts)->toBe(pgsCounts([
        'students' => 3,
        'users' => 3,
        'registrations_deleted' => 1,
        'members' => 3,
        'letters' => 3,
        'reports' => 1,
        'achievements' => 2,
        'publications' => 1,
        'appreciations' => 1,
        'files' => 6,
    ]));

    $id = $registration->getKey();

    expect(DB::table('registrations')->where('id', $id)->exists())->toBeFalse()
        ->and(DB::table('registration_members')->where('registration_id', $id)->count())->toBe(0)
        ->and(DB::table('registration_reminders')->where('registration_id', $id)->count())->toBe(0)
        ->and(DB::table('letters')->where('registration_id', $id)->count())->toBe(0)
        ->and(DB::table('reports')->where('registration_id', $id)->count())->toBe(0)
        ->and(DB::table('report_files')->where('report_id', $team['report']->getKey())->count())->toBe(0)
        ->and(DB::table('achievements')->where('report_id', $team['report']->getKey())->count())->toBe(0)
        ->and(DB::table('publications')->count())->toBe(0)
        ->and(DB::table('appreciations')->count())->toBe(0)
        ->and(DB::table('appreciation_events')->where('id', $event->getKey())->exists())->toBeTrue()
        ->and(DB::table('competitions')->where('id', $lomba->getKey())->value('juknis_file_id'))->toBe($juknis->getKey())
        ->and(Setting::get(SettingKey::SignatureFileId))->toBe($signature->getKey());

    foreach ([...$pdfs, $team['certificate'], $photo, $flyer] as $file) {
        expect(DB::table('files')->where('id', $file->getKey())->exists())->toBeFalse()
            ->and(Storage::disk('local')->exists($file->path))->toBeFalse();
    }

    foreach ([$juknis, $signature, $oldSignature] as $file) {
        expect(DB::table('files')->where('id', $file->getKey())->exists())->toBeTrue()
            ->and(Storage::disk('local')->exists($file->path))->toBeTrue();
    }

    expect(pgsRowsOf($outsider))->toBe($outsiderBefore);
});

it('passes the leadership of a mixed team to the first confirmed member, who becomes ketua and takes the report', function (): void {
    $staff = pgsStaff();
    [$a, $b, $c, $d] = [pgsStudent(), pgsStudent(), pgsStudent(), pgsStudent()];
    $lomba = Competition::factory()->published()->team(4)->create(['submitted_by' => $staff['kesiswaan']->getKey()]);

    // C (not answered) and D (declined) were invited before B, but a confirmed member comes first.
    $team = pgsTeam($lomba, $a, [
        [$b, 'confirmed', '2026-11-05 10:00:00'],
        [$c, 'pending', '2026-11-05 08:00:00'],
        [$d, 'declined', '2026-11-05 07:00:00'],
    ], $staff);
    $registration = $team['registration'];
    $letterPdf = pgsFile(FilePurpose::Letter, $staff['tu']);
    $letter = Letter::factory()->create(['registration_id' => $registration->getKey(), 'file_id' => $letterPdf->getKey(), 'generated_by' => $staff['tu']->getKey()]);
    $publication = Publication::factory()->create(['achievement_id' => $team['achievements'][(string) $b->getKey()]->getKey()]);
    $bAchievement = $team['achievements'][(string) $b->getKey()];
    pgsTrash($a);

    $counts = pgsPurge([$a]);

    expect($counts)->toBe(pgsCounts([
        'students' => 1,
        'users' => 1,
        'registrations_kept' => 1,
        'registrations_new_leader' => 1,
        'members' => 1,
        'achievements' => 1,
    ]));

    $roles = DB::table('registration_members')->where('registration_id', $registration->getKey())->pluck('role', 'student_id')->all();

    expect(DB::table('registrations')->where('id', $registration->getKey())->value('leader_student_id'))->toBe($b->getKey())
        ->and($roles)->toEqual([
            $b->getKey() => MemberRole::Ketua->value,
            $c->getKey() => MemberRole::Anggota->value,
            $d->getKey() => MemberRole::Anggota->value,
        ])
        ->and((int) DB::table('reports')->where('id', $team['report']->getKey())->value('submitted_by'))->toBe((int) $b->user_id)
        ->and((int) DB::table('reports')->where('id', $team['report']->getKey())->value('verified_by'))->toBe((int) $staff['kesiswaan']->getKey())
        ->and((int) DB::table('files')->where('id', $team['certificate']->getKey())->value('owner_user_id'))->toBe((int) $b->user_id)
        ->and(Storage::disk('local')->exists($team['certificate']->path))->toBeTrue()
        ->and(DB::table('achievements')->where('student_id', $a->getKey())->exists())->toBeFalse()
        ->and(DB::table('achievements')->where('id', $bAchievement->getKey())->exists())->toBeTrue()
        ->and(DB::table('publications')->where('id', $publication->getKey())->exists())->toBeTrue()
        ->and((int) DB::table('letters')->where('id', $letter->getKey())->value('generated_by'))->toBe((int) $staff['tu']->getKey())
        ->and(Storage::disk('local')->exists($letterPdf->path))->toBeTrue()
        ->and((int) DB::table('registrations')->where('id', $registration->getKey())->value('mentor_id'))->toBe((int) $staff['guru']->getKey());
});

it('removes only the member row and achievement of a member who is not the leader', function (): void {
    $staff = pgsStaff();
    [$leader, $member] = [pgsStudent(), pgsStudent()];
    $lomba = Competition::factory()->published()->team()->create(['submitted_by' => $staff['kesiswaan']->getKey()]);
    $team = pgsTeam($lomba, $leader, [[$member, 'confirmed', '2026-11-05 10:00:00']], $staff);
    $leaderBefore = pgsRowsOf($leader);

    $counts = pgsPurge([$member]);

    expect($counts)->toBe(pgsCounts(['students' => 1, 'users' => 1, 'registrations_kept' => 1, 'members' => 1, 'achievements' => 1]))
        ->and(pgsRowsOf($leader))->toBe($leaderBefore)
        ->and(DB::table('registration_members')->where('student_id', $member->getKey())->exists())->toBeFalse()
        ->and(DB::table('achievements')->where('student_id', $member->getKey())->exists())->toBeFalse()
        ->and((int) DB::table('reports')->where('id', $team['report']->getKey())->value('submitted_by'))->toBe((int) $leader->user_id)
        ->and((int) DB::table('files')->where('id', $team['certificate']->getKey())->value('owner_user_id'))->toBe((int) $leader->user_id);
});

it('moves a lomba a purged student proposed to the first active Admin and keeps its juknis without an owner', function (): void {
    $staff = pgsStaff();
    $student = pgsStudent();
    $juknis = pgsFile(FilePurpose::Juknis, pgsUser($student));
    $proposal = Competition::factory()->create(['submitted_by' => $student->user_id, 'juknis_file_id' => $juknis->getKey()]);

    $counts = pgsPurge([$student]);

    expect($counts)->toBe(pgsCounts(['students' => 1, 'users' => 1, 'competitions_to_admin' => 1]))
        ->and((int) DB::table('competitions')->where('id', $proposal->getKey())->value('submitted_by'))->toBe((int) $staff['admin']->getKey())
        ->and(DB::table('files')->where('id', $juknis->getKey())->value('owner_user_id'))->toBeNull()
        ->and(Storage::disk('local')->exists($juknis->path))->toBeTrue();
});

it('refuses the whole run and writes nothing when no active Admin can take over a proposed lomba', function (): void {
    pgsStaff(withAdmin: false);
    User::factory()->disabled()->staff(RoleName::Admin)->create();
    $student = pgsStudent();
    $orphan = pgsFile(FilePurpose::ReportEvidence, pgsUser($student));
    Competition::factory()->create(['submitted_by' => $student->user_id]);
    $before = pgsSnapshot();

    expect(fn () => pgsPurge([$student]))->toThrow(DomainException::class, 'Tidak ada akun Admin aktif yang bisa menjadi pengusul 1 lomba usulan siswa.');

    expect(pgsSnapshot())->toBe($before)
        ->and(Storage::disk('local')->exists($orphan->path))->toBeTrue();
});

it('refuses the whole run and writes nothing when a kept history row needs a purged account', function (): void {
    $staff = pgsStaff();
    $student = pgsStudent();
    DB::table('role_change_requests')->insert([
        'id' => (string) Str::ulid(),
        'user_id' => $student->user_id,
        'from_roles' => json_encode(['siswa']),
        'to_role' => RoleName::Guru->value,
        'requested_by' => $staff['admin']->getKey(),
        'reason' => 'Contoh perubahan peran.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $before = pgsSnapshot();

    expect(fn () => pgsPurge([$student]))->toThrow(DomainException::class, '1 baris riwayat');

    expect(pgsSnapshot())->toBe($before);
});

it('refuses when a purged leader leaves a registration with nobody to take over', function (): void {
    $staff = pgsStaff();
    [$leader, $outsider] = [pgsStudent(), pgsStudent()];
    $lomba = Competition::factory()->published()->create(['submitted_by' => $staff['kesiswaan']->getKey()]);
    $team = pgsTeam($lomba, $leader, [], $staff);
    // An achievement of a student without a member row keeps the registration, but nobody can lead it.
    Achievement::factory()->create(['report_id' => $team['report']->getKey(), 'student_id' => $outsider->getKey(), 'competition_id' => $lomba->getKey()]);
    $before = pgsSnapshot();

    expect(fn () => pgsPurge([$leader]))->toThrow(DomainException::class, '1 pendaftaran kehilangan ketua');

    expect(pgsSnapshot())->toBe($before);
});

it('keeps files that a row which stays still points at, and never deletes a signature image', function (): void {
    $staff = pgsStaff();
    [$student, $other] = [pgsStudent(), pgsStudent()];
    $studentUser = pgsUser($student);
    $lomba = Competition::factory()->published()->create(['submitted_by' => $staff['kesiswaan']->getKey()]);

    // The student's certificate is also attached to another student's report; the talent evidence to another choice.
    $mine = pgsTeam($lomba, $student, [], $staff);
    $theirs = pgsTeam($lomba, $other, [], $staff);
    ReportFile::factory()->create(['report_id' => $theirs['report']->getKey(), 'kind' => ReportFileKind::Sertifikat, 'file_id' => $mine['certificate']->getKey()]);
    $sharedEvidence = pgsFile(FilePurpose::TalentEvidence, $studentUser);
    TalentApplication::factory()->create(['student_id' => $student->getKey(), 'evidence_file_id' => $sharedEvidence->getKey()]);
    TalentApplication::factory()->create(['student_id' => $other->getKey(), 'evidence_file_id' => $sharedEvidence->getKey()]);
    $signatureImage = pgsFile(FilePurpose::Signature, $studentUser);
    $orphan = pgsFile(FilePurpose::ReportEvidence, $studentUser);

    $counts = pgsPurge([$student]);

    expect($counts['files'])->toBe(1)
        ->and($counts['registrations_deleted'])->toBe(1)
        ->and(DB::table('files')->where('id', $orphan->getKey())->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($orphan->path))->toBeFalse();

    foreach ([$mine['certificate'], $sharedEvidence, $signatureImage] as $file) {
        expect(DB::table('files')->where('id', $file->getKey())->exists())->toBeTrue()
            ->and(DB::table('files')->where('id', $file->getKey())->value('owner_user_id'))->toBeNull()
            ->and(Storage::disk('local')->exists($file->path))->toBeTrue();
    }
});

it('purges students that are not in the trash too, and finds nothing on a second run or for unknown ids', function (): void {
    $staff = pgsStaff();
    [$student, $other] = [pgsStudent(), pgsStudent()];
    $lomba = Competition::factory()->published()->create(['submitted_by' => $staff['kesiswaan']->getKey()]);
    pgsTeam($lomba, $student, [], $staff);
    pgsTeam($lomba, $other, [], $staff);

    $first = pgsPurge([$student]);
    $before = pgsSnapshot();
    $version = (int) Cache::get(PublicAchievements::VERSION_KEY, 1);

    $again = pgsPurge([$student]);
    $unknown = app(PurgeStudents::class)->handle([(string) Str::ulid(), '', 42, null]);

    expect($first['students'])->toBe(1)
        ->and($first['registrations_deleted'])->toBe(1)
        ->and($again)->toBe(pgsCounts([]))
        ->and($unknown)->toBe(pgsCounts([]))
        ->and(app(PurgeStudents::class)->handle([]))->toBe(pgsCounts([]))
        ->and(pgsSnapshot())->toBe($before)
        ->and((int) Cache::get(PublicAchievements::VERSION_KEY, 1))->toBe($version);
});

it('handles every foreign key that points at users, files, students, registrations or a row it deletes', function (): void {
    $key = fn (array $pair): string => $pair[0].'.'.$pair[1];
    $handled = [
        'users' => [
            ...PurgeStudents::USER_REFERENCES_NULLED,
            ...PurgeStudents::USER_REFERENCES_TO_LEADER,
            ...PurgeStudents::USER_REFERENCES_MOVED,
            ...PurgeStudents::USER_ROWS_DELETED,
            ...PurgeStudents::USER_REFERENCES_BLOCKING,
        ],
        'files' => [
            ...PurgeStudents::DELETED_FILE_REFERENCES,
            ...PurgeStudents::KEPT_FILES_OF_DELETED_ROWS,
            ...PurgeStudents::KEPT_FILE_REFERENCES,
        ],
        'students' => PurgeStudents::STUDENT_REFERENCES,
        'registrations' => PurgeStudents::REGISTRATION_ROWS_DELETED,
        'reports' => PurgeStudents::CHILD_ROWS_DELETED,
        'achievements' => PurgeStudents::CHILD_ROWS_DELETED,
        'talent_profiles' => PurgeStudents::CHILD_ROWS_DELETED,
        'letters' => PurgeStudents::LETTER_REFERENCES_NULLED,
    ];

    // Rows of these tables are deleted too and nothing may point at them yet.
    $leaves = ['registration_members', 'registration_reminders', 'report_files', 'publications', 'appreciations',
        'talent_applications', 'talent_profile_files'];

    foreach ([...$leaves, ...array_column(PurgeStudents::USER_ROWS_DELETED, 0)] as $table) {
        $handled[$table] ??= [];
    }

    $handled = array_map(fn (array $pairs): array => array_map($key, $pairs), $handled);
    $missing = [];

    foreach (Schema::getTables() as $table) {
        $name = (string) $table['name'];

        foreach (Schema::getForeignKeys($name) as $foreignKey) {
            $target = (string) $foreignKey['foreign_table'];
            $column = $name.'.'.implode(',', $foreignKey['columns']);

            if (isset($handled[$target]) && ! in_array($column, $handled[$target], true)) {
                $missing[] = $column.' → '.$target;
            }
        }
    }

    expect($missing)->toBe([]);
});
