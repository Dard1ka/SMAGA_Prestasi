<?php

declare(strict_types=1);

use App\Actions\Students\DeleteAllStudents;
use App\Enums\FilePurpose;
use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Enums\ReportFileKind;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Livewire\Admin\AuditLog;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
 * smaga_server, the lead's request of 4 Nov 2026 ("data siswa hapus semua, ingat cuman siswa"):
 * `php artisan sth:siswa:hapus-semua` deletes every student account and everything that hangs on a student, and keeps
 * staff, classes, lomba (a student's lomba moves to the first active Admin), appreciation events, the signature and the
 * audit log. Fictional people only (factories); names, NISN and emails are only compared, never printed.
 */

uses(RefreshDatabase::class);

const DAS_COMMAND = 'sth:siswa:hapus-semua';

/** The tables whose rows all belong to students: empty after a run. */
const DAS_EMPTIED = [
    'students', 'registrations', 'registration_members', 'registration_reminders', 'letters', 'reports',
    'report_files', 'achievements', 'publications', 'appreciations', 'talent_applications', 'talent_profiles',
    'talent_profile_files',
];

beforeEach(function (): void {
    H::seedRoles();
    $this->travelTo(now()->setDate(2026, 11, 4)->setTime(9, 0));

    $this->classes = [
        'X-1' => Classroom::factory()->create(['name' => 'X-1', 'level' => 10])->getKey(),
        'XI-4' => Classroom::factory()->level(11)->create(['name' => 'XI-4'])->getKey(),
    ];
});

/** A file row with bytes on the test's own private disk. */
function dasFile(FilePurpose $purpose, ?User $owner = null): File
{
    $file = File::factory()->purpose($purpose)->create(['owner_user_id' => $owner?->getKey()]);
    Storage::disk('local')->put($file->path, 'bytes of '.$purpose->value);

    return $file;
}

function dasRun(array $options = []): array
{
    $code = Artisan::call(DAS_COMMAND, $options);

    return [$code, Artisan::output()];
}

/** Row count of every table a run may touch, to prove that nothing was written. */
function dasSnapshot(): array
{
    $tables = [...DAS_EMPTIED, 'users', 'files', 'competitions', 'classrooms', 'appreciation_events', 'sessions',
        'notifications', 'model_has_roles', 'otp_codes', 'settings', 'activity_log'];

    $snapshot = [];

    foreach ($tables as $table) {
        $snapshot[$table] = DB::table($table)->count();
    }

    $snapshot['competition_submitters'] = DB::table('competitions')->orderBy('id')->pluck('submitted_by')->all();
    $snapshot['file_owners'] = DB::table('files')->orderBy('id')->pluck('owner_user_id')->all();

    return $snapshot;
}

/**
 * Six student accounts of every kind, their registrations, letters, report, achievements, publication, appreciation
 * and talent rows with files, a lomba a student proposed, the signature, staff and guru accounts, bell rows and
 * sessions of both sides.
 *
 * @return array<string, mixed>
 */
function dasPopulation(bool $withAdmin = true): array
{
    $t = test();

    // A disabled Admin first (lower id): the lomba must move to the first ACTIVE Admin.
    $staff = [
        'admin_disabled' => $withAdmin ? User::factory()->disabled()->staff(RoleName::Admin)->create() : null,
        'admin' => $withAdmin ? User::factory()->staff(RoleName::Admin)->create() : null,
        'kesiswaan' => User::factory()->staff(RoleName::Kesiswaan)->create(),
        'kepsek' => User::factory()->staff(RoleName::KepalaSekolah)->create(),
        'tu' => User::factory()->staff(RoleName::TataUsaha)->create(),
        'humas' => User::factory()->staff(RoleName::Humas)->create(),
        'guru' => User::factory()->staff(RoleName::Guru)->create(),
    ];

    $students = [
        'active' => Student::factory()->activated()->create(['classroom_id' => $t->classes['X-1']]),
        'pending' => Student::factory()->create(['classroom_id' => $t->classes['X-1']]),
        'archived' => Student::factory()->archived()->create(['classroom_id' => $t->classes['XI-4']]),
        'disabled' => Student::factory()->state(['user_id' => User::factory()->disabled()])->create(['classroom_id' => $t->classes['XI-4']]),
        'soft_deleted' => Student::factory()->create(['classroom_id' => $t->classes['XI-4']]),
    ];
    $students['soft_deleted']->delete();

    // A siswa account without a students row, itself soft-deleted.
    $roleOnly = User::factory()->withRole(RoleName::Siswa)->create();
    $roleOnly->delete();

    $activeUser = $students['active']->user;

    // The signature setting and an older signature image a revoked letter was signed with: both always stay.
    $signature = dasFile(FilePurpose::Signature, $staff['admin'] ?? $staff['kesiswaan']);
    $oldSignature = dasFile(FilePurpose::Signature, $staff['admin'] ?? $staff['kesiswaan']);
    Setting::put(SettingKey::SignatureFileId, $signature->getKey());

    // A lomba a student proposed (stays, moves to the Admin) and a published team lomba of Kesiswaan.
    $proposalJuknis = dasFile(FilePurpose::Juknis, $activeUser);
    $proposal = Competition::factory()->create([
        'submitted_by' => $activeUser->getKey(),
        'juknis_file_id' => $proposalJuknis->getKey(),
    ]);
    $lombaJuknis = dasFile(FilePurpose::Juknis, $staff['kesiswaan']);
    $lombaCover = dasFile(FilePurpose::Cover, $staff['kesiswaan']);
    $lomba = Competition::factory()->published()->team()->create([
        'submitted_by' => $staff['kesiswaan']->getKey(),
        'verified_by' => $staff['kesiswaan']->getKey(),
        'default_mentor_id' => $staff['guru']->getKey(),
        'juknis_file_id' => $lombaJuknis->getKey(),
        'cover_file_id' => $lombaCover->getKey(),
    ]);

    // A team registration (ketua + one member) with letters, a report, achievements, a publication, an appreciation.
    $team = Registration::factory()->team()->status(RegistrationStatus::Selesai)->create([
        'competition_id' => $lomba->getKey(),
        'leader_student_id' => $students['active']->getKey(),
        'mentor_id' => $staff['guru']->getKey(),
        'principal_decision_by' => $staff['kepsek']->getKey(),
    ]);
    RegistrationMember::factory()->confirmed()->create([
        'registration_id' => $team->getKey(),
        'student_id' => $students['pending']->getKey(),
        'role' => MemberRole::Anggota,
    ]);
    DB::table('registration_reminders')->insert(['registration_id' => $team->getKey(), 'kind' => 'report_after_3', 'sent_at' => now()]);

    // An individual registration of the disabled student.
    Registration::factory()->create([
        'competition_id' => $lomba->getKey(),
        'leader_student_id' => $students['disabled']->getKey(),
    ]);

    $letterPdfs = [dasFile(FilePurpose::Letter, $staff['tu']), dasFile(FilePurpose::Letter, $staff['tu']), dasFile(FilePurpose::Letter, $staff['tu'])];
    $revoked = Letter::factory()->create([
        'registration_id' => $team->getKey(),
        'version' => 1,
        'file_id' => $letterPdfs[0]->getKey(),
        'signature_file_id' => $oldSignature->getKey(),
        'generated_by' => $staff['tu']->getKey(),
        'revoked_at' => now(),
        'revoked_by' => $staff['tu']->getKey(),
        'revoke_reason' => 'Nomor salah ketik.',
    ]);
    Letter::factory()->create([
        'registration_id' => $team->getKey(),
        'version' => 2,
        'replaces_letter_id' => $revoked->getKey(),
        'file_id' => $letterPdfs[1]->getKey(),
        'signature_file_id' => $signature->getKey(),
        'generated_by' => $staff['tu']->getKey(),
        'sent_at' => now(),
    ]);
    Letter::factory()->guru()->create([
        'registration_id' => $team->getKey(),
        'file_id' => $letterPdfs[2]->getKey(),
        'signature_file_id' => $signature->getKey(),
        'generated_by' => $staff['tu']->getKey(),
    ]);

    $report = Report::factory()->verified()->create([
        'registration_id' => $team->getKey(),
        'submitted_by' => $activeUser->getKey(),
        'verified_by' => $staff['kesiswaan']->getKey(),
    ]);
    $certificate = dasFile(FilePurpose::ReportEvidence, $activeUser);
    $photo = dasFile(FilePurpose::ReportEvidence, $activeUser);
    ReportFile::factory()->create(['report_id' => $report->getKey(), 'kind' => ReportFileKind::Sertifikat, 'file_id' => $certificate->getKey()]);
    ReportFile::factory()->create(['report_id' => $report->getKey(), 'kind' => ReportFileKind::Foto, 'file_id' => $photo->getKey()]);
    // The announcement points at the lomba's own juknis: that file stays, the lomba still needs it.
    ReportFile::factory()->create(['report_id' => $report->getKey(), 'kind' => ReportFileKind::Pengumuman, 'file_id' => $lombaJuknis->getKey()]);

    // Evidence left behind by an earlier revision: owned by the student, nothing points at it (goes); and one of staff (stays).
    $orphanOfStudent = dasFile(FilePurpose::ReportEvidence, $activeUser);
    $orphanOfStaff = dasFile(FilePurpose::ReportEvidence, $staff['kesiswaan']);

    $achievements = [
        Achievement::factory()->create(['report_id' => $report->getKey(), 'student_id' => $students['active']->getKey(), 'competition_id' => $lomba->getKey()]),
        Achievement::factory()->create(['report_id' => $report->getKey(), 'student_id' => $students['pending']->getKey(), 'competition_id' => $lomba->getKey()]),
    ];
    $flyer = dasFile(FilePurpose::Flyer, $staff['humas']);
    Publication::factory()->create([
        'achievement_id' => $achievements[0]->getKey(),
        'created_by' => $staff['humas']->getKey(),
        'flyer_file_id' => $flyer->getKey(),
    ]);
    $event = AppreciationEvent::factory()->create();
    Appreciation::factory()->create(['achievement_id' => $achievements[1]->getKey(), 'appreciation_event_id' => $event->getKey()]);

    // Talent: an application with evidence, a soft-deleted one, a profile with a file.
    $talentEvidence = dasFile(FilePurpose::TalentEvidence, $activeUser);
    TalentApplication::factory()->create([
        'student_id' => $students['active']->getKey(),
        'evidence_file_id' => $talentEvidence->getKey(),
        'mentor_id' => $staff['guru']->getKey(),
    ]);
    TalentApplication::factory()->create(['student_id' => $students['pending']->getKey()])->delete();
    $profile = TalentProfile::factory()->create(['student_id' => $students['archived']->getKey()]);
    $profileFile = dasFile(FilePurpose::TalentEvidence, $students['archived']->user);
    DB::table('talent_profile_files')->insert([
        'id' => (string) Str::ulid(),
        'talent_profile_id' => $profile->getKey(),
        'file_id' => $profileFile->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Bell rows, sessions and an OTP code of both sides.
    foreach ([$activeUser, $staff['kesiswaan']] as $index => $user) {
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Example',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
            'data' => json_encode(['message' => 'Contoh']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'das-session-'.$index,
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Pest',
            'payload' => base64_encode('x'),
            'last_activity' => now()->getTimestamp(),
        ]);
    }
    OtpCode::factory()->create(['user_id' => $students['pending']->user_id]);

    $deletedFiles = [...$letterPdfs, $certificate, $photo, $orphanOfStudent, $flyer, $talentEvidence, $profileFile];
    $keptFiles = [$signature, $oldSignature, $proposalJuknis, $lombaJuknis, $lombaCover, $orphanOfStaff];

    return [
        'staff' => array_filter($staff),
        'students' => $students,
        'student_user_ids' => [...array_map(fn (Student $s): int => (int) $s->user_id, array_values($students)), (int) $roleOnly->getKey()],
        'student_users' => [...array_map(fn (Student $s): User => User::withTrashed()->findOrFail($s->user_id), array_values($students)), $roleOnly],
        'proposal' => $proposal,
        'lomba' => $lomba,
        'event' => $event,
        'signature' => $signature,
        'deleted_files' => $deletedFiles,
        'kept_files' => $keptFiles,
    ];
}

/** Names, NISN, NIS and emails of the student accounts: none of them may be printed or logged. */
function dasSecrets(array $population): array
{
    $secrets = [];

    foreach ($population['student_users'] as $user) {
        $secrets[] = (string) $user->name;

        if ($user->email !== null) {
            $secrets[] = (string) $user->email;
        }
    }

    foreach ($population['students'] as $student) {
        $secrets[] = (string) $student->nisn;
        $secrets[] = (string) $student->nis;
    }

    return $secrets;
}

it('previews per kind what it would delete and what stays, and writes nothing (dry run)', function (): void {
    $population = dasPopulation();
    $before = dasSnapshot();

    [$code, $output] = dasRun();

    expect($code)->toBe(0)
        ->and($output)->toContain('Pratinjau, tidak ada yang dihapus. Yang akan dihapus permanen:')
        ->and($output)->toContain('siswa: 6 akun (2 belum aktivasi, 2 aktif, 1 nonaktif, 1 diarsipkan)')
        ->and($output)->toContain('pendaftaran: 2 (dengan 3 baris anggota)')
        ->and($output)->toContain('surat tugas: 3')
        ->and($output)->toContain('laporan hasil: 1')
        ->and($output)->toContain('prestasi: 2')
        ->and($output)->toContain('publikasi: 1')
        ->and($output)->toContain('apresiasi (prestasi di acara; acaranya tetap): 1')
        ->and($output)->toContain('pilihan talenta: 2')
        ->and($output)->toContain('profil talenta: 1')
        ->and($output)->toContain('file: 9')
        ->and($output)->toContain('lomba yang dipindah ke Admin: 1')
        ->and($output)->toContain('Tetap ada: 7 akun staf dan guru, 2 kelas, 2 lomba, 1 acara apresiasi')
        ->and($output)->toContain('buat cadangan database dulu. Jalankan lagi dengan --terapkan')
        ->and(dasSnapshot())->toBe($before);

    foreach ($population['deleted_files'] as $file) {
        expect(Storage::disk('local')->exists($file->path))->toBeTrue();
    }

    foreach (dasSecrets($population) as $secret) {
        expect(str_contains($output, $secret))->toBeFalse();
    }
});

it('deletes every student and everything on them, keeps staff, classes, lomba, events and the signature (--terapkan)', function (): void {
    $population = dasPopulation();
    $staffIds = array_map(fn (User $u): int => (int) $u->getKey(), $population['staff']);
    $logged = Activity::query()->count();

    [$code, $output] = dasRun(['--terapkan' => true, '--force' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Semua data siswa dihapus permanen:')
        ->and($output)->toContain('siswa: 6 akun (2 belum aktivasi, 2 aktif, 1 nonaktif, 1 diarsipkan)')
        ->and($output)->toContain('file: 9')
        ->and($output)->not->toContain('Pratinjau');

    // Students and everything that hung on them are gone (soft-deleted rows included).
    foreach (DAS_EMPTIED as $table) {
        expect(DB::table($table)->count())->toBe(0, $table);
    }

    expect(DB::table('users')->whereIn('id', $population['student_user_ids'])->count())->toBe(0)
        ->and(DB::table('model_has_roles')->whereIn('model_id', $population['student_user_ids'])->count())->toBe(0)
        ->and(DB::table('notifications')->whereIn('notifiable_id', $population['student_user_ids'])->count())->toBe(0)
        ->and(DB::table('sessions')->whereIn('user_id', $population['student_user_ids'])->count())->toBe(0)
        ->and(DB::table('otp_codes')->count())->toBe(0);

    // Staff, guru, classes, both lomba, the event and the staff's own rows stay.
    expect(User::query()->whereIn('id', $staffIds)->count())->toBe(count($staffIds))
        ->and(User::query()->role(RoleName::Guru->value)->count())->toBe(1)
        ->and(DB::table('classrooms')->count())->toBe(2)
        ->and(AppreciationEvent::query()->whereKey($population['event']->getKey())->exists())->toBeTrue()
        ->and(DB::table('notifications')->where('notifiable_id', $population['staff']['kesiswaan']->getKey())->count())->toBe(1)
        ->and(DB::table('sessions')->where('user_id', $population['staff']['kesiswaan']->getKey())->count())->toBe(1);

    $proposal = $population['proposal']->refresh();
    $lomba = $population['lomba']->refresh();

    expect($proposal->submitted_by)->toBe($population['staff']['admin']->getKey())
        ->and($proposal->juknis_file_id)->not->toBeNull()
        ->and($lomba->submitted_by)->toBe($population['staff']['kesiswaan']->getKey())
        ->and($lomba->verified_by)->toBe($population['staff']['kesiswaan']->getKey())
        ->and($lomba->default_mentor_id)->toBe($population['staff']['guru']->getKey())
        ->and(Setting::get(SettingKey::SignatureFileId))->toBe($population['signature']->getKey());

    // The deleted rows' files: row and bytes gone. The kept ones: row and bytes still there.
    foreach ($population['deleted_files'] as $file) {
        expect(File::query()->whereKey($file->getKey())->exists())->toBeFalse()
            ->and(Storage::disk('local')->exists($file->path))->toBeFalse();
    }

    foreach ($population['kept_files'] as $file) {
        expect(File::query()->whereKey($file->getKey())->exists())->toBeTrue()
            ->and(Storage::disk('local')->exists($file->path))->toBeTrue();
    }

    // The proposal's juknis was uploaded by the student: it stays, without an owner.
    expect(File::query()->whereKey($proposal->juknis_file_id)->value('owner_user_id'))->toBeNull();

    // One audit row with counts only.
    $rows = Activity::query()->where('id', '>', 0)->orderBy('id')->get()->slice($logged)->values();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->log_name)->toBe('account')
        ->and($rows[0]->event)->toBe(DeleteAllStudents::EVENT)
        ->and($rows[0]->causer_id)->toBeNull()
        ->and($rows[0]->properties['siswa'])->toBe(6)
        ->and($rows[0]->properties['pendaftaran'])->toBe(2)
        ->and($rows[0]->properties['surat'])->toBe(3)
        ->and($rows[0]->properties['file'])->toBe(9)
        ->and($rows[0]->properties['lomba_ke_admin'])->toBe(1)
        ->and(AuditLog::EVENT_LABELS[DeleteAllStudents::EVENT])->toBe('Semua data siswa dihapus');

    $logText = json_encode($rows[0]->toArray());

    foreach (dasSecrets($population) as $secret) {
        expect(str_contains($output, $secret))->toBeFalse()
            ->and(str_contains((string) $logText, $secret))->toBeFalse();
    }
});

it('frees the classes and the NISN again: the same student can be created after a run', function (): void {
    $population = dasPopulation();
    $nisn = $population['students']['active']->nisn;

    dasRun(['--terapkan' => true, '--force' => true]);

    $again = Student::factory()->create(['nisn' => $nisn, 'classroom_id' => test()->classes['X-1']]);

    expect($again->exists)->toBeTrue()
        ->and(Student::query()->where('classroom_id', test()->classes['X-1'])->count())->toBe(1);
});

it('finds nothing to delete on a second run and writes nothing', function (): void {
    dasPopulation();
    dasRun(['--terapkan' => true, '--force' => true]);
    $before = dasSnapshot();

    [$code, $output] = dasRun(['--terapkan' => true, '--force' => true]);
    [$previewCode, $preview] = dasRun();

    expect($code)->toBe(0)
        ->and($output)->toContain('Tidak ada data siswa untuk dihapus. Tidak ada yang diubah.')
        ->and($previewCode)->toBe(0)
        ->and($preview)->toContain('Tidak ada data siswa untuk dihapus.')
        ->and(dasSnapshot())->toBe($before);
});

it('refuses --terapkan outside APP_ENV=local without --force and writes nothing', function (): void {
    dasPopulation();
    $before = dasSnapshot();

    [$code, $output] = dasRun(['--terapkan' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('Di luar APP_ENV=local, --terapkan hanya berjalan bersama --force. Tidak ada yang dihapus.')
        ->and(dasSnapshot())->toBe($before);
});

it('deletes with --terapkan alone in APP_ENV=local (the school server today)', function (): void {
    $population = dasPopulation();
    $this->app['env'] = 'local';

    [$code, $output] = dasRun(['--terapkan' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('Semua data siswa dihapus permanen:')
        ->and(DB::table('students')->count())->toBe(0)
        ->and(DB::table('users')->whereIn('id', $population['student_user_ids'])->count())->toBe(0);
});

it('refuses and writes nothing when no active Admin can take over a student\'s lomba', function (): void {
    dasPopulation(withAdmin: false);
    // An Admin that is disabled does not count.
    User::factory()->disabled()->staff(RoleName::Admin)->create();
    $before = dasSnapshot();

    [$previewCode, $preview] = dasRun();
    [$code, $output] = dasRun(['--terapkan' => true, '--force' => true]);

    expect($previewCode)->toBe(0)
        ->and($preview)->toContain('--terapkan akan ditolak: Tidak ada akun Admin aktif yang bisa menjadi pengusul 1 lomba usulan siswa.')
        ->and($code)->toBe(1)
        ->and($output)->toContain('Ditolak: Tidak ada akun Admin aktif yang bisa menjadi pengusul 1 lomba usulan siswa. Tidak ada yang dihapus.')
        ->and(dasSnapshot())->toBe($before);

    foreach (DAS_EMPTIED as $table) {
        expect(DB::table($table)->count())->toBeGreaterThan(0, $table);
    }
});

it('refuses and writes nothing when a kept history row needs a student account', function (): void {
    $population = dasPopulation();
    DB::table('role_change_requests')->insert([
        'id' => (string) Str::ulid(),
        'user_id' => $population['student_user_ids'][0],
        'from_roles' => json_encode(['siswa']),
        'to_role' => RoleName::Guru->value,
        'requested_by' => $population['staff']['admin']->getKey(),
        'reason' => 'Contoh perubahan peran.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $before = dasSnapshot();

    [$code, $output] = dasRun(['--terapkan' => true, '--force' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('Ditolak: 1 baris riwayat penggantian Kepala Sekolah atau perubahan peran menunjuk akun siswa.')
        ->and(dasSnapshot())->toBe($before);
});

it('handles every foreign key of the schema that points at users, files or a student row', function (): void {
    $handledUsers = [
        ...DeleteAllStudents::USER_REFERENCES_NULLED,
        ...DeleteAllStudents::USER_REFERENCES_MOVED,
        ...DeleteAllStudents::USER_ROWS_DELETED,
        ...DeleteAllStudents::USER_REFERENCES_BLOCKING,
    ];
    $handledFiles = [
        ...DeleteAllStudents::DELETED_FILE_REFERENCES,
        ...DeleteAllStudents::KEPT_FILES_OF_DELETED_ROWS,
        ...DeleteAllStudents::KEPT_FILE_REFERENCES,
    ];
    $key = fn (array $pair): string => $pair[0].'.'.$pair[1];
    $handledUsers = array_map($key, $handledUsers);
    $handledFiles = array_map($key, $handledFiles);
    $studentTables = [...DeleteAllStudents::EMPTIED_TABLES, 'students'];
    $missing = [];

    foreach (Schema::getTables() as $table) {
        $name = (string) $table['name'];

        foreach (Schema::getForeignKeys($name) as $foreignKey) {
            $column = $name.'.'.implode(',', $foreignKey['columns']);
            $target = (string) $foreignKey['foreign_table'];
            // A row of an emptied table goes as a whole: its own foreign keys never block.
            $emptied = in_array($name, DeleteAllStudents::EMPTIED_TABLES, true);

            if ($target === 'users' && ! $emptied && ! in_array($column, $handledUsers, true)) {
                $missing[] = $column.' → users';
            }

            if ($target === 'files' && ! in_array($column, $handledFiles, true)) {
                $missing[] = $column.' → files';
            }

            if (in_array($target, $studentTables, true) && ! $emptied && $name !== 'students') {
                $missing[] = $column.' → '.$target;
            }
        }
    }

    expect($missing)->toBe([]);
});
