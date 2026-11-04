<?php

declare(strict_types=1);

use App\Actions\Reports\VerifyReport;
use App\Actions\Trash\TrashStudents;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Models\Achievement;
use App\Models\Publication;
use App\Models\Report;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Verification\AchievementReadyForPublication;
use App\Notifications\Verification\BinaanReportDecided;
use App\Notifications\Verification\ReportVerified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
 * smaga_server, the trash of 5 Nov 2026: a student in the trash ("Sampah": its `students` and `users` rows
 * soft-deleted, status unchanged) earns nothing from a verified report and hears nothing of it. RecordAchievements
 * gives a row only to confirmed members whose student is not trashed (none left = the old refusal), VerifyReport
 * tells only the kept members, and the guru's notice names only them. Fictional people only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->humas = F::staff(RoleName::Humas, 'Mas Galih Prasetyo');
    $this->admin = F::staff(RoleName::Admin, 'Operator STH');
    $this->guru = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
});

/** Puts these students in the trash through the Admin's own Action. */
function tvTrash(Student ...$students): void
{
    app(TrashStudents::class)->handle(test()->admin, array_map(fn (Student $s): int => (int) $s->user_id, $students), 'Data ganda dari import.');
}

function tvAccount(Student $student): User
{
    return User::withTrashed()->findOrFail($student->user_id);
}

/** The body of the one verified notice the guru got. */
function tvGuruBody(): string
{
    $bodies = [];

    Notification::assertSentTo(test()->guru, BinaanReportDecided::class, function (BinaanReportDecided $n) use (&$bodies): bool {
        $bodies[] = $n->body;

        return $n->kind === 'report.binaan_verified';
    });

    expect($bodies)->toHaveCount(1);

    return $bodies[0];
}

it('gives a trashed team member no achievement, no points and no notice', function (): void {
    $nadia = F::student('Nadia Putri Anindya', 'XI-4');
    $dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $keisha = F::student('Keisha Larasati', 'XI-6');
    $report = F::pendingReport($nadia, [$dimas, $keisha], ResultType::Juara3, $this->guru);
    tvTrash($dimas);

    $achievements = app(VerifyReport::class)->handle($this->kesiswaan, $report, ResultType::Juara3, F::allChecks());

    expect($achievements->pluck('student_id')->sort()->values()->all())->toBe(collect([$nadia->getKey(), $keisha->getKey()])->sort()->values()->all())
        ->and(Achievement::query()->where('student_id', $dimas->getKey())->exists())->toBeFalse()
        ->and(Publication::query()->count())->toBe(2)
        ->and(Activity::query()->where('event', 'report.verified')->sole()->properties['members'])->toBe(2)
        ->and($report->fresh()->status)->toBe(ReportStatus::Diverifikasi);

    Notification::assertSentTo([tvAccount($nadia), tvAccount($keisha)], ReportVerified::class);
    Notification::assertNotSentTo(tvAccount($dimas), ReportVerified::class);
    Notification::assertSentTo($this->humas, AchievementReadyForPublication::class);

    expect(tvGuruBody())->toBe('Nadia Putri Anindya dan 1 anggota timnya meraih Juara III. Setiap anggota mendapat 33,6 poin.');
});

it('names the first kept member to the guru when the ketua is in the trash', function (): void {
    $nadia = F::student('Nadia Putri Anindya', 'XI-4');
    $dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $report = F::pendingReport($nadia, [$dimas], ResultType::Juara3, $this->guru);
    tvTrash($nadia);

    $achievements = app(VerifyReport::class)->handle($this->kesiswaan, $report, ResultType::Juara3, F::allChecks());

    expect($achievements)->toHaveCount(1)
        ->and($achievements->first()->student_id)->toBe($dimas->getKey());

    Notification::assertNotSentTo(tvAccount($nadia), ReportVerified::class);
    Notification::assertSentTo(tvAccount($dimas), ReportVerified::class);

    $body = tvGuruBody();
    expect($body)->toBe('Dimas Arya Nugroho meraih Juara III. Poinnya 33,6 poin.')
        ->and($body)->not->toContain('Nadia');
});

it('keeps the old refusal when every confirmed member is in the trash', function (): void {
    $nadia = F::student('Nadia Putri Anindya', 'XI-4');
    $dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $report = F::pendingReport($nadia, [$dimas], ResultType::Juara3, $this->guru);
    tvTrash($nadia, $dimas);

    try {
        app(VerifyReport::class)->handle($this->kesiswaan, $report, ResultType::Juara3, F::allChecks());
        $this->fail('The verification went through without a member who is not in the trash.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['final_result' => ['Tidak ada anggota terkonfirmasi di pendaftaran ini.']]);
    }

    expect(Achievement::query()->count())->toBe(0)
        ->and(Publication::query()->count())->toBe(0)
        ->and($report->fresh()->status)->toBe(ReportStatus::Dikirim)
        ->and(Activity::query()->where('event', 'report.verified')->exists())->toBeFalse();

    Notification::assertNothingSent();
});

it('tells no member and names none to the guru who went into the trash after the achievements were written', function (): void {
    $nadia = F::student('Nadia Putri Anindya', 'XI-4');
    $keisha = F::student('Keisha Larasati', 'XI-6');
    $report = F::pendingReport($nadia, [$keisha], ResultType::Juara3, $this->guru);

    // Another tab trashes Keisha between the achievement rows and the notices (no lock is shared with TrashStudents).
    $done = false;
    Event::listen('eloquent.saved: '.Report::class, function () use ($keisha, &$done): void {
        if ($done) {
            return;
        }

        $done = true;
        DB::table('students')->where('id', $keisha->getKey())->update(['deleted_at' => now()]);
        DB::table('users')->where('id', $keisha->user_id)->update(['deleted_at' => now()]);
    });

    app(VerifyReport::class)->handle($this->kesiswaan, $report, ResultType::Juara3, F::allChecks());

    expect($done)->toBeTrue();

    Notification::assertSentTo(tvAccount($nadia), ReportVerified::class);
    Notification::assertNotSentTo(tvAccount($keisha), ReportVerified::class);

    $body = tvGuruBody();
    expect($body)->toBe('Nadia Putri Anindya meraih Juara III. Poinnya 33,6 poin.')
        ->and($body)->not->toContain('Keisha');
});

it('leaves trashed students out of the guru notice it is given', function (): void {
    $nadia = F::student('Nadia Putri Anindya', 'XI-4');
    $dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $keisha = F::student('Keisha Larasati', 'XI-6');
    $report = F::pendingReport($nadia, [$dimas, $keisha], ResultType::Juara3, $this->guru);
    app(VerifyReport::class)->handle($this->kesiswaan, $report, ResultType::Juara3, F::allChecks());
    tvTrash($nadia);

    $load = fn () => Achievement::query()->with(['student.user', 'competition'])->where('report_id', $report->getKey())->orderBy('created_at')->get();
    $achievements = $load();
    $notice = BinaanReportDecided::verified($report->fresh(), $achievements);

    expect($achievements)->toHaveCount(3)
        ->and($notice->body)->not->toContain('Nadia')
        ->and($notice->body)->toEndWith('dan 1 anggota timnya meraih Juara III. Setiap anggota mendapat 33,6 poin.');

    tvTrash($dimas, $keisha);
    $none = BinaanReportDecided::verified($report->fresh(), $load());

    expect($none->body)->toBe('Siswa binaan meraih Juara III. Poinnya 33,6 poin.')
        ->and($none->title)->toBe('Laporan binaan diverifikasi: Olimpiade Kimia Nasional Universitas Diponegoro 2026');
});
