<?php

declare(strict_types=1);

use App\Actions\Archive\ChangeArchiveDate;
use App\Actions\Archive\UnarchiveStudent;
use App\Actions\Users\ArchiveDueStudentAccounts;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Livewire\Admin\ArsipOtomatis;
use App\Models\Classroom;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    Notification::fake();
    $this->withoutVite();
    $this->travelTo('2029-12-01 09:00:00');
    $this->admin = A::admin();
    $this->class = Classroom::factory()->level(12)->create(['name' => 'XII-2']);
});

function b5ArsipStudent(string $archiveAt, bool $archived = false, array $user = []): Student
{
    $factory = $archived ? Student::factory()->archived() : Student::factory()->activated();
    $student = $factory->create([
        'classroom_id' => Classroom::query()->where('name', 'XII-2')->value('id'),
        'grade12_start_date' => '2028-07-01',
        'archive_at' => $archiveAt,
    ]);

    if ($user !== []) {
        $student->user->forceFill($user)->save();
    }

    return $student->refresh();
}

// Screen ---------------------------------------------------------------------------------------------

it('opens the archive overview for an Admin and lists students due in the window, without NIS', function (): void {
    $soon = b5ArsipStudent('2029-12-20', user: ['name' => 'Raka Aditya Pratama']);
    $later = b5ArsipStudent('2030-01-25', user: ['name' => 'Bima Sakti Nugraha']);

    $this->actingAs($this->admin)->get(route('admin.arsip.index'))
        ->assertOk()
        ->assertSee('Raka Aditya Pratama')
        ->assertDontSee('Bima Sakti Nugraha')
        ->assertDontSee($soon->nis);

    $this->actingAs($this->admin)->get(route('admin.arsip.index', ['hari' => 60]))
        ->assertOk()
        ->assertSee('Bima Sakti Nugraha');
});

it('lists archived students on the second tab and the history on the third', function (): void {
    b5ArsipStudent('2029-07-01', archived: true, user: ['name' => 'Putri Ayu Lestari']);
    $due = b5ArsipStudent('2029-12-01', user: ['name' => 'Bagas Wicaksono']);
    app(ArchiveDueStudentAccounts::class)->handle();

    $this->actingAs($this->admin)->get(route('admin.arsip.index', ['tab' => 'arsip']))
        ->assertOk()
        ->assertSee('Putri Ayu Lestari')
        ->assertSee('Bagas Wicaksono');

    $this->actingAs($this->admin)->get(route('admin.arsip.index', ['tab' => 'riwayat']))
        ->assertOk()
        ->assertSee('1 akun');
    expect($due->user->fresh()->status)->toBe(UserStatus::Archived);
});

it('denies the archive overview to other roles and sends guests to login', function (RoleName $role): void {
    $this->actingAs(A::staff($role))->get(route('admin.arsip.index'))->assertForbidden();
})->with([RoleName::Kesiswaan, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas, RoleName::Guru]);

it('sends guests to the login page', function (): void {
    $this->get(route('admin.arsip.index'))->assertRedirect(route('login'));
});

// ChangeArchiveDate ----------------------------------------------------------------------------------

it('moves the archive date and the kelas XII start, audited with the reason', function (): void {
    $student = b5ArsipStudent('2030-01-01');

    app(ChangeArchiveDate::class)->handle($this->admin, $student, '2031-01-01', 'Tinggal kelas XII.');

    $student->refresh();
    expect($student->archive_at->toDateString())->toBe('2031-01-01')
        ->and($student->grade12_start_date->toDateString())->toBe('2029-07-01');

    $log = Activity::query()->where('event', 'student.archive_date_changed')->sole();
    expect($log->causer_id)->toBe($this->admin->id)
        ->and($log->properties['reason'])->toBe('Tinggal kelas XII.')
        ->and($log->properties['from']['archive_at'])->toBe('2030-01-01')
        ->and($log->properties['to']['archive_at'])->toBe('2031-01-01');
});

it('refuses archive dates that are not after today, a missing reason and archived students', function (): void {
    $student = b5ArsipStudent('2030-01-01');
    $alumni = b5ArsipStudent('2029-07-01', archived: true);

    expect(fn () => app(ChangeArchiveDate::class)->handle($this->admin, $student, '2029-12-01', 'Hari ini.'))->toThrow(ValidationException::class)
        ->and(fn () => app(ChangeArchiveDate::class)->handle($this->admin, $student, '2029-11-01', 'Lampau.'))->toThrow(ValidationException::class)
        ->and(fn () => app(ChangeArchiveDate::class)->handle($this->admin, $student, '2045-01-01', 'Terlalu jauh.'))->toThrow(ValidationException::class)
        ->and(fn () => app(ChangeArchiveDate::class)->handle($this->admin, $student, '2031-01-01', ' '))->toThrow(ValidationException::class)
        ->and(fn () => app(ChangeArchiveDate::class)->handle($this->admin, $alumni, '2031-01-01', 'Alumni.'))->toThrow(AuthorizationException::class)
        ->and($student->fresh()->archive_at->toDateString())->toBe('2030-01-01');
});

// UnarchiveStudent -----------------------------------------------------------------------------------

it('restores an archived, activated student as active with a new archive date; the job leaves it alone', function (): void {
    $alumni = b5ArsipStudent('2029-11-01', archived: true);

    $user = app(UnarchiveStudent::class)->handle($this->admin, $alumni->user, '2030-07-01', 'Tanggal impor salah.');

    expect($user->status)->toBe(UserStatus::Active)
        ->and($alumni->fresh()->archive_at->toDateString())->toBe('2030-07-01')
        ->and(app(ArchiveDueStudentAccounts::class)->handle())->toBe(0)
        ->and($alumni->user->fresh()->status)->toBe(UserStatus::Active);

    $log = Activity::query()->where('event', 'account.unarchived')->sole();
    expect($log->properties['reason'])->toBe('Tanggal impor salah.')
        ->and($log->properties['to'])->toBe(UserStatus::Active->value)
        ->and($log->causer_id)->toBe($this->admin->id);
});

it('restores a student who never finished activation as pending activation', function (): void {
    $alumni = b5ArsipStudent('2029-11-01', archived: true, user: ['must_change_password' => true, 'email' => null, 'email_verified_at' => null]);

    expect(app(UnarchiveStudent::class)->handle($this->admin, $alumni->user, '2030-07-01', 'Masih sekolah.')->status)
        ->toBe(UserStatus::PendingActivation);
});

it('refuses to restore without a future date or for accounts that are not archived', function (): void {
    $alumni = b5ArsipStudent('2029-11-01', archived: true);
    $active = b5ArsipStudent('2030-01-01');

    expect(fn () => app(UnarchiveStudent::class)->handle($this->admin, $alumni->user, '2029-12-01', 'Hari ini.'))->toThrow(ValidationException::class)
        ->and(fn () => app(UnarchiveStudent::class)->handle($this->admin, $active->user, '2030-07-01', 'Aktif.'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UnarchiveStudent::class)->handle($this->admin, A::staff(RoleName::Guru), '2030-07-01', 'Staf.'))->toThrow(AuthorizationException::class)
        ->and($alumni->user->fresh()->status)->toBe(UserStatus::Archived);
});

it('lets only user.archive change archive data', function (RoleName $role): void {
    $actor = A::staff($role);
    $alumni = b5ArsipStudent('2029-11-01', archived: true);
    $student = b5ArsipStudent('2030-01-01');

    expect(fn () => app(UnarchiveStudent::class)->handle($actor, $alumni->user, '2030-07-01', 'x'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ChangeArchiveDate::class)->handle($actor, $student, '2031-01-01', 'x'))->toThrow(AuthorizationException::class);
})->with([RoleName::Kesiswaan, RoleName::KepalaSekolah, RoleName::TataUsaha]);

// Livewire -------------------------------------------------------------------------------------------

it('restores from the screen after a password confirmation', function (): void {
    $alumni = b5ArsipStudent('2029-11-01', archived: true);
    $this->actingAs($this->admin);

    Livewire::withQueryParams(['tab' => 'arsip'])->test(ArsipOtomatis::class)
        ->call('openRestore', $alumni->id)
        ->assertDispatched('sth-modal-open', id: 'arsip-pulihkan')
        ->set('archiveDate', '2030-07-01')
        ->set('archiveReason', 'Masih aktif.')
        ->call('restore')
        ->assertRedirect(route('password.confirm'));

    session(A::passwordConfirmed());

    Livewire::withQueryParams(['tab' => 'arsip'])->test(ArsipOtomatis::class)
        ->call('openRestore', $alumni->id)
        ->set('archiveDate', '2029-11-15')
        ->set('archiveReason', 'Masih aktif.')
        ->call('restore')
        ->assertHasErrors('archiveDate')
        ->set('archiveDate', '2030-07-01')
        ->call('restore')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast');

    expect($alumni->user->fresh()->status)->toBe(UserStatus::Active);
});

it('changes the archive date from the screen and keeps the target id locked', function (): void {
    $student = b5ArsipStudent('2029-12-20');
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(ArsipOtomatis::class)
        ->call('openDate', $student->id)
        ->assertSet('archiveDate', '2029-12-20')
        ->set('archiveDate', '2030-12-20')
        ->call('saveDate')
        ->assertHasErrors('archiveReason')
        ->set('archiveReason', 'Tinggal kelas.')
        ->call('saveDate')
        ->assertHasNoErrors();

    expect($student->fresh()->archive_at->toDateString())->toBe('2030-12-20');

    expect(fn () => Livewire::test(ArsipOtomatis::class)->set('studentId', $student->id))->toThrow(Exception::class);
});

// QA8: explore round after PR 17 (2026-10-08) -------------------------------------------------------

it('names the archive months the Admin set in the page subtitle (QA8)', function (): void {
    Setting::put(SettingKey::ArchiveMonthsAfterGrade12, 24);

    $this->actingAs($this->admin)->get(route('admin.arsip.index'))
        ->assertOk()
        ->assertSee('Akun siswa diarsipkan 24 bulan setelah mulai kelas XII.')
        ->assertDontSee('18 bulan');
});
