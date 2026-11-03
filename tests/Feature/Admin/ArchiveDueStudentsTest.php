<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Models\Achievement;
use App\Models\Classroom;
use App\Models\Student;
use App\Notifications\Admin\ArchiveRunReport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();
    $this->travelTo('2030-01-01 01:00:00');
    $this->class = Classroom::factory()->level(12)->create(['name' => 'XII-B']);
});

function sthStudentWithArchiveDate(string $archiveAt, bool $archived = false): Student
{
    $factory = $archived ? Student::factory()->archived() : Student::factory()->activated();

    return $factory->create([
        'classroom_id' => Classroom::query()->where('name', 'XII-B')->value('id'),
        'grade12_start_date' => '2028-07-01',
        'archive_at' => $archiveAt,
    ]);
}

it('archives students whose archive date has arrived, revokes their sessions and audits it', function (): void {
    $admin = A::admin();
    $due = sthStudentWithArchiveDate('2030-01-01');
    $late = sthStudentWithArchiveDate('2029-12-15');
    $notYet = sthStudentWithArchiveDate('2030-01-02');
    Achievement::factory()->create(['student_id' => $due->id]);
    // The achievement factory creates more (team) students: keep them out of this run.
    Student::query()->whereNotIn('id', [$due->id, $late->id, $notYet->id])->update(['archive_at' => '2099-01-01']);
    H::fakeSessions($due->user, 2);

    $this->artisan('sth:arsip-otomatis')->expectsOutput('2 akun siswa diarsipkan.')->assertSuccessful();

    expect($due->user->fresh()->status)->toBe(UserStatus::Archived)
        ->and($late->user->fresh()->status)->toBe(UserStatus::Archived)
        ->and($notYet->user->fresh()->status)->toBe(UserStatus::Active)
        ->and(H::sessionCount($due->user))->toBe(0)
        ->and(Achievement::query()->where('student_id', $due->id)->count())->toBe(1); // achievements stay (D-29)

    $log = Activity::query()->where('event', 'account.archived')->where('subject_id', (string) $due->user_id)->sole();
    expect($log->properties['automatic'])->toBeTrue()
        ->and($log->causer_id)->toBeNull()
        ->and($log->properties['archive_at'])->toBe('2030-01-01');

    Notification::assertSentTo($admin, ArchiveRunReport::class);
});

it('is idempotent: a second run changes nothing', function (): void {
    sthStudentWithArchiveDate('2029-06-01');
    sthStudentWithArchiveDate('2029-06-01', archived: true);

    $this->artisan('sth:arsip-otomatis')->expectsOutput('1 akun siswa diarsipkan.')->assertSuccessful();
    $this->artisan('sth:arsip-otomatis')->expectsOutput('Tidak ada akun yang perlu diarsipkan.')->assertSuccessful();

    expect(Activity::query()->where('event', 'account.archived')->count())->toBe(1);
});

it('refuses a login of an archived student (D-29)', function (): void {
    $student = H::activeStudent();
    $student->forceFill(['archive_at' => '2029-12-31'])->save();

    $this->artisan('sth:arsip-otomatis')->assertSuccessful();

    $this->post(route('login.store'), ['identifier' => H::NISN, 'password' => H::PASSWORD])->assertSessionHasErrors();
    $this->assertGuest();
});

it('accepts a reference date only in Y-m-d and never in production', function (): void {
    sthStudentWithArchiveDate('2031-01-01');

    $this->artisan('sth:arsip-otomatis', ['--date' => '01-01-2031'])->assertExitCode(2);
    $this->artisan('sth:arsip-otomatis', ['--date' => '2031-01-01'])->expectsOutput('1 akun siswa diarsipkan.');
});

it('is scheduled daily at 01:00 Asia/Jakarta without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'sth:arsip-otomatis'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 1 * * *')
        ->and($event->timezone)->toBe('Asia/Jakarta')
        ->and($event->withoutOverlapping)->toBeTrue();
});
