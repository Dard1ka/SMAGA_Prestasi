<?php

declare(strict_types=1);

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\EnableAccount;
use App\Actions\Archive\ArchiveDates;
use App\Actions\Fortify\LoginLockout;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 * SV5 (the lead, 2026-11-04): "yang awalnya nonaktif bisa diaktifkan kembali". EnableAccount is the undo of
 * DisableAccount for a student account. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    $this->admin = A::admin();
    $this->classroom = Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
});

/** A disabled student account; $user overrides the account, $student the student row. */
function disabledStudent(array $user = [], array $student = []): User
{
    $row = Student::factory()->create(array_merge(['classroom_id' => test()->classroom->getKey()], $student));
    $row->user->forceFill(array_merge(['name' => 'Raka Pratama', 'status' => UserStatus::Disabled], $user))->saveQuietly();

    return $row->user->fresh();
}

/** The errors of a refused call, key => first message. @return array<string, string> */
function enableErrors(callable $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return collect($e->errors())->map(fn (array $messages): string => (string) $messages[0])->all();
    }

    return [];
}

function tomorrowInJakarta(): string
{
    return CarbonImmutable::now('Asia/Jakarta')->addDay()->toDateString();
}

it('makes an activated account active again, audited without names', function (): void {
    $user = disabledStudent(['must_change_password' => false, 'email' => 'raka.pratama@example.test', 'email_verified_at' => now()]);
    $archiveAt = $user->student->archive_at->toDateString();

    $result = app(EnableAccount::class)->handle($this->admin, $user, '  Salah menonaktifkan akun.  ');

    $fresh = $user->fresh();
    $row = Activity::query()->where('event', 'account.enabled')->sole();

    expect($result->status)->toBe(UserStatus::Active)
        ->and($fresh->status)->toBe(UserStatus::Active)
        ->and($fresh->canLogIn())->toBeTrue()
        ->and($fresh->student->archive_at->toDateString())->toBe($archiveAt)
        ->and((int) $row->subject_id)->toBe((int) $user->getKey())
        ->and((int) $row->causer_id)->toBe((int) $this->admin->getKey())
        ->and($row->properties['reason'])->toBe('Salah menonaktifkan akun.')
        ->and($row->properties['from'])->toBe(UserStatus::Disabled->value)
        ->and($row->properties['to'])->toBe(UserStatus::Active->value)
        ->and($row->properties['window'])->toBeNull()
        ->and($row->properties['unfrozen'])->toBeNull()
        ->and($row->properties['dates'])->toBeNull()
        ->and(json_encode($row->properties))->not->toContain('Raka');
});

it('makes an account that never finished activation pending, with at least 72 more hours', function (): void {
    $user = disabledStudent(['must_change_password' => true, 'email' => null, 'email_verified_at' => null, 'initial_password_expires_at' => now()->subDays(3)]);

    app(EnableAccount::class)->handle($this->admin, $user, 'Siswa kembali bersekolah.');

    $fresh = $user->fresh();
    $until = AccountControls::date($fresh, 'initial_password_expires_at');
    $row = Activity::query()->where('event', 'account.enabled')->sole();

    expect($fresh->status)->toBe(UserStatus::PendingActivation)
        ->and(AccountControls::state($fresh))->toBe(AccountControls::STATE_PENDING)
        ->and($until->greaterThan(now()->addHours(71)))->toBeTrue()
        ->and($until->lessThanOrEqualTo(now()->addHours(72)->addMinute()))->toBeTrue()
        ->and($row->properties['to'])->toBe(UserStatus::PendingActivation->value)
        ->and($row->properties['window']['to'])->toBe($until->toIso8601String());
});

it('keeps a later activation deadline of a pending account', function (): void {
    $later = now()->addDays(10)->startOfMinute();
    $user = disabledStudent(['must_change_password' => true, 'email' => null, 'email_verified_at' => null, 'initial_password_expires_at' => $later]);

    app(EnableAccount::class)->handle($this->admin, $user, 'Siswa kembali bersekolah.');

    expect(AccountControls::date($user->fresh(), 'initial_password_expires_at')->equalTo($later))->toBeTrue()
        ->and(Activity::query()->where('event', 'account.enabled')->sole()->properties['window'])->toBeNull();
});

it('requires a new archive date when the automatic one passed, and writes it with the kelas XII start', function (string $passed): void {
    $user = disabledStudent(student: ['archive_at' => $passed]);
    $before = $user->student->only(['archive_at', 'grade12_start_date']);

    expect(enableErrors(fn () => app(EnableAccount::class)->handle($this->admin, $user, 'Siswa kembali bersekolah.')))
        ->toBe(['archiveDate' => EnableAccount::MSG_DATE_PASSED])
        ->and(enableErrors(fn () => app(EnableAccount::class)->handle($this->admin, $user, 'Siswa kembali bersekolah.', CarbonImmutable::now('Asia/Jakarta')->toDateString())))
        ->toBe(['archiveDate' => 'Pilih tanggal setelah hari ini.'])
        ->and($user->fresh()->status)->toBe(UserStatus::Disabled)
        ->and(Activity::query()->where('event', 'account.enabled')->exists())->toBeFalse();

    $date = tomorrowInJakarta();
    app(EnableAccount::class)->handle($this->admin, $user, 'Siswa kembali bersekolah.', $date);

    $student = $user->student->fresh();
    $grade12 = ArchiveDates::grade12StartFor(CarbonImmutable::parse($date, 'Asia/Jakarta'))->toDateString();
    $row = Activity::query()->where('event', 'account.enabled')->sole();

    expect($user->fresh()->canLogIn())->toBeTrue()
        ->and($student->archive_at->toDateString())->toBe($date)
        ->and($student->grade12_start_date->toDateString())->toBe($grade12)
        ->and($row->properties['dates'])->toBe([
            'from' => ['archive_at' => $before['archive_at']->toDateString(), 'grade12_start_date' => $before['grade12_start_date']->toDateString()],
            'to' => ['archive_at' => $date, 'grade12_start_date' => $grade12],
        ])
        ->and(Student::query()->dueForArchive(CarbonImmutable::now('Asia/Jakarta')->startOfDay())->whereKey($student->getKey())->exists())->toBeFalse();
})->with([
    'today' => fn () => CarbonImmutable::now('Asia/Jakarta')->toDateString(),
    'last year' => fn () => CarbonImmutable::now('Asia/Jakarta')->subYear()->toDateString(),
]);

it('does not ask for a date when the archive date lies ahead, and ignores one given', function (): void {
    $ahead = CarbonImmutable::now('Asia/Jakarta')->addYear()->toDateString();
    $user = disabledStudent(student: ['archive_at' => $ahead]);

    app(EnableAccount::class)->handle($this->admin, $user, 'Siswa kembali bersekolah.', tomorrowInJakarta());

    expect($user->fresh()->canLogIn())->toBeTrue()
        ->and($user->student->fresh()->archive_at->toDateString())->toBe($ahead)
        ->and(Activity::query()->where('event', 'account.enabled')->sole()->properties['dates'])->toBeNull()
        ->and(EnableAccount::archiveDatePassed($user->student->fresh()))->toBeFalse();
});

it('lifts a freeze with its counter and clears the login locks', function (string $reason): void {
    $user = disabledStudent([
        'must_change_password' => true, 'email' => null, 'email_verified_at' => null, 'initial_password_expires_at' => now()->addDays(5),
        'frozen_at' => now(), 'frozen_reason' => $reason, 'failed_activation_attempts' => 10,
    ]);

    app(EnableAccount::class)->handle($this->admin, $user, 'Pemilik akun sudah dicek.');

    $fresh = $user->fresh();

    expect(AccountControls::isFrozen($fresh))->toBeFalse()
        ->and(AccountControls::frozenReason($fresh))->toBeNull()
        ->and(AccountControls::failedAttempts($fresh))->toBe(0)
        ->and(AccountControls::state($fresh))->toBe(AccountControls::STATE_PENDING)
        ->and(Activity::query()->where('event', 'account.enabled')->sole()->properties['unfrozen'])->toBe($reason)
        ->and(app(LoginLockout::class)->clearedAt('u:'.$user->getKey()))->not->toBeNull();
})->with([AccountControls::FROZEN_FAILED_ATTEMPTS, AccountControls::FROZEN_NOT_ME, AccountControls::FROZEN_ADMIN]);

it('refuses an account that is not disabled, a staff account, the own account and a short or long reason', function (): void {
    $active = disabledStudent(['status' => UserStatus::Active]);
    $archived = disabledStudent(['status' => UserStatus::Archived]);
    $staff = A::staff(RoleName::Guru);
    $staff->forceFill(['status' => UserStatus::Disabled])->saveQuietly();
    $disabled = disabledStudent();
    $enable = app(EnableAccount::class);

    expect(enableErrors(fn () => $enable->handle($this->admin, $active, 'Siswa kembali bersekolah.')))->toBe(['user' => EnableAccount::MSG_NOT_DISABLED])
        ->and(enableErrors(fn () => $enable->handle($this->admin, $archived, 'Siswa kembali bersekolah.')))->toBe(['user' => EnableAccount::MSG_NOT_DISABLED])
        ->and(enableErrors(fn () => $enable->handle($this->admin, $staff, 'Siswa kembali bersekolah.')))->toBe(['user' => EnableAccount::MSG_NOT_STUDENT])
        ->and(enableErrors(fn () => $enable->handle($this->admin, $this->admin, 'Siswa kembali bersekolah.')))->toBe(['user' => EnableAccount::MSG_SELF])
        ->and(enableErrors(fn () => $enable->handle($this->admin, $disabled, '   ')))->toBe(['reason' => 'Isi alasannya.'])
        ->and(enableErrors(fn () => $enable->handle($this->admin, $disabled, 'Kembali.')))->toBe(['reason' => 'Alasan minimal 10 karakter.'])
        ->and(enableErrors(fn () => $enable->handle($this->admin, $disabled, str_repeat('a', 501))))->toBe(['reason' => 'Alasan maksimal 500 karakter.']);

    expect($active->fresh()->status)->toBe(UserStatus::Active)
        ->and($archived->fresh()->status)->toBe(UserStatus::Archived)
        ->and($staff->fresh()->status)->toBe(UserStatus::Disabled)
        ->and($disabled->fresh()->status)->toBe(UserStatus::Disabled)
        ->and(Activity::query()->where('event', 'account.enabled')->exists())->toBeFalse();
});

it('is Admin only', function (RoleName $role): void {
    $user = disabledStudent();

    expect(fn () => app(EnableAccount::class)->handle(A::staff($role), $user, 'Siswa kembali bersekolah.'))
        ->toThrow(AuthorizationException::class);

    expect($user->fresh()->status)->toBe(UserStatus::Disabled);
})->with([RoleName::Kesiswaan, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas, RoleName::Guru]);
