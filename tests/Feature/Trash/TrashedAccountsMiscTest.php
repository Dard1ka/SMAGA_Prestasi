<?php

declare(strict_types=1);

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\ActivateStudentAccount;
use App\Actions\Accounts\ChangeEmail;
use App\Actions\Accounts\CompleteActivation;
use App\Actions\Accounts\DetermineActivationStep;
use App\Actions\Accounts\SetNewPassword;
use App\Actions\Settings\UpdateLimits;
use App\Actions\Trash\RestoreFromTrash;
use App\Actions\Trash\TrashStudents;
use App\Enums\UserStatus;
use App\Models\Classroom;
use App\Models\OtpCode;
use App\Models\Student;
use App\Models\TrashEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 | Trashed students must not leak into account rules (the trash of 2026-11-05: a trashed student = its `students` row and
 | its `users` row soft-deleted, status unchanged), plus the activation date of a student the Admin made "Aktif":
 | - the "email taken" check of the activation and of "Ganti email" sees a trashed account's address too, so the normal
 |   message shows instead of a unique-index error (users.email is unique over every row);
 | - a student made "Aktif" by the Admin (ActivateStudentAccount) gets `activated_at` once the email and password steps
 |   are done, so Status aktivasi shows the date; no audit row is added;
 | - a new archive-months setting also recalculates trashed students, so a restored student follows the new rule.
 | Fictional data only.
 */

uses(RefreshDatabase::class);

const TAM_PASSWORD = 'Pelangi-senja-2026';

const TAM_REASON = 'Siswa sudah dicek di TU.';

beforeEach(function (): void {
    A::seedRoles();
    Mail::fake();
    Notification::fake();
    config(['fortify.sth.otp_test_mode' => false]);
    $this->admin = A::admin();
    $this->classroom = Classroom::factory()->level(11)->create(['name' => 'XI-4']);
});

/** A student account (pending by default) in the test class; fictional name. */
function tamStudent(array $user = [], array $student = []): User
{
    $row = Student::factory()->create(array_merge(['classroom_id' => test()->classroom->getKey()], $student));
    $row->user->forceFill(array_merge(['name' => 'Raka Pratama'], $user))->saveQuietly();

    return $row->user->fresh();
}

/** Sends these accounts to the trash the way Admin › Kelas does. @return list<TrashEntry> */
function tamTrash(User ...$users): array
{
    return app(TrashStudents::class)->handle(
        test()->admin,
        array_map(fn (User $user): int => (int) $user->getKey(), $users),
        'Siswa pindah ke sekolah lain.',
    );
}

/** A verified `verify_email` code of this account for that address (the step before bindEmail / confirm). */
function tamOtp(User $user, string $email): OtpCode
{
    return OtpCode::factory()->create(['user_id' => $user->getKey(), 'email' => $email, 'used_at' => now()]);
}

/** The errors of a refused call, key => first message. @return array<string, string> */
function tamErrors(callable $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return collect($e->errors())->map(fn (array $messages): string => (string) $messages[0])->all();
    }

    return [];
}

// --- "Email taken" sees a trashed account -------------------------------------------------------------------------

it('refuses to bind an address a trashed student holds, with the normal message', function (): void {
    $trashed = tamStudent(['status' => UserStatus::Active, 'email' => 'raka.pratama@example.test', 'email_verified_at' => now(), 'must_change_password' => false]);
    tamTrash($trashed);
    $pending = tamStudent(['name' => 'Nadia Putri Anindya']);
    $otp = tamOtp($pending, 'Raka.Pratama@example.test');

    expect(User::query()->where('email', 'raka.pratama@example.test')->exists())->toBeFalse();

    $errors = tamErrors(fn () => app(CompleteActivation::class)->bindEmail($pending, $otp));

    expect($errors)->toBe(['email' => 'Email ini sudah dipakai akun lain.'])
        ->and($pending->fresh()->email)->toBeNull()
        ->and(User::withTrashed()->find($trashed->getKey())->email)->toBe('raka.pratama@example.test')
        ->and(Activity::query()->where('event', CompleteActivation::EVENT_EMAIL_BOUND)->exists())->toBeFalse();
});

it('refuses "Ganti email" to an address a trashed student holds, with the normal message', function (): void {
    $trashed = tamStudent(['status' => UserStatus::Active, 'email' => 'raka.pratama@example.test', 'email_verified_at' => now(), 'must_change_password' => false]);
    tamTrash($trashed);
    $user = tamStudent(['name' => 'Nadia Putri Anindya', 'status' => UserStatus::Active, 'email' => 'nadia.anindya@example.test', 'email_verified_at' => now(), 'must_change_password' => false]);
    $otp = tamOtp($user, 'raka.pratama@example.test');

    $errors = tamErrors(fn () => app(ChangeEmail::class)->confirm($user, $otp));

    expect($errors)->toBe(['email' => 'Email ini sudah dipakai akun lain.'])
        ->and($user->fresh()->email)->toBe('nadia.anindya@example.test')
        ->and(Activity::query()->where('event', 'account.email_changed')->exists())->toBeFalse();

    Notification::assertNothingSent();
});

it('still binds and changes to a free address', function (): void {
    $pending = tamStudent();
    app(CompleteActivation::class)->bindEmail($pending, tamOtp($pending, 'raka.pratama@example.test'));

    $active = tamStudent(['name' => 'Nadia Putri Anindya', 'status' => UserStatus::Active, 'email' => 'nadia.anindya@example.test', 'email_verified_at' => now(), 'must_change_password' => false]);
    $new = app(ChangeEmail::class)->confirm($active, tamOtp($active, 'nadia.baru@example.test'));

    expect($pending->fresh()->email)->toBe('raka.pratama@example.test')
        ->and($new)->toBe('nadia.baru@example.test')
        ->and($active->fresh()->email)->toBe('nadia.baru@example.test');
});

// --- Activation date of a student the Admin made "Aktif" ----------------------------------------------------------

it('stamps activated_at when a student the Admin made active finishes the email and password steps, without a new audit row', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 08:00:00'));
    $user = tamStudent(['initial_password_expires_at' => now()->addDays(5)]);
    app(ActivateStudentAccount::class)->handle($this->admin, $user, TAM_REASON);
    $user = $user->fresh();

    $this->travelTo(CarbonImmutable::parse('2026-11-06 09:30:00'));
    $activation = app(CompleteActivation::class);
    $activation->bindEmail($user, tamOtp($user, 'raka.pratama@example.test'));

    // Email done, password step still open: not finished, no date yet.
    expect(AccountControls::date($user->fresh(), 'activated_at'))->toBeNull()
        ->and(app(DetermineActivationStep::class)($user->fresh()))->toBe(DetermineActivationStep::PASSWORD);

    $activation->changePassword($user, TAM_PASSWORD, TAM_PASSWORD);
    $fresh = $user->fresh();

    expect($fresh->status)->toBe(UserStatus::Active)
        ->and(app(DetermineActivationStep::class)($fresh))->toBeNull()
        ->and(AccountControls::date($fresh, 'activated_at')?->toIso8601String())->toBe(CarbonImmutable::parse('2026-11-06 09:30:00')->toIso8601String())
        ->and(AccountControls::date($user, 'activated_at'))->not->toBeNull()
        ->and(AccountControls::state($fresh))->toBe(AccountControls::STATE_ACTIVE)
        ->and(Activity::query()->where('event', CompleteActivation::EVENT_ACTIVATED)->exists())->toBeFalse()
        ->and(Activity::query()->where('event', ActivateStudentAccount::EVENT)->count())->toBe(1);
});

it('stamps activated_at when that student closes the password step through "Lupa kata sandi", once', function (): void {
    $user = tamStudent(['initial_password_expires_at' => now()->addDays(5)]);
    app(ActivateStudentAccount::class)->handle($this->admin, $user, TAM_REASON);
    $user = $user->fresh();
    app(CompleteActivation::class)->bindEmail($user, tamOtp($user, 'raka.pratama@example.test'));

    $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00:00'));
    app(SetNewPassword::class)->handle($user, TAM_PASSWORD, null, 'account.password_reset');
    $activated = app(CompleteActivation::class)->whenPasswordAlreadyChosen($user);

    expect($activated)->toBeFalse()
        ->and(AccountControls::date($user->fresh(), 'activated_at')?->toIso8601String())->toBe(CarbonImmutable::parse('2026-11-07 10:00:00')->toIso8601String())
        ->and(Activity::query()->where('event', CompleteActivation::EVENT_ACTIVATED)->exists())->toBeFalse();

    // A later reset keeps the first date.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00'));
    app(SetNewPassword::class)->handle($user->fresh(), 'Kapal-layar-biru-77', null, 'account.password_reset');
    app(CompleteActivation::class)->whenPasswordAlreadyChosen($user->fresh());

    expect(AccountControls::date($user->fresh(), 'activated_at')?->toIso8601String())->toBe(CarbonImmutable::parse('2026-11-07 10:00:00')->toIso8601String());
});

it('leaves the date of an active account the Admin never activated as it is', function (): void {
    // Re-verification of an active account without a date (an old account): not an activation the Admin skipped.
    $legacy = tamStudent(['status' => UserStatus::Active, 'must_change_password' => false, 'password_changed_at' => now()]);
    app(CompleteActivation::class)->bindEmail($legacy, tamOtp($legacy, 'raka.pratama@example.test'));

    // An active account with a date that changes its password at the password step keeps that date.
    $dated = tamStudent(
        ['name' => 'Nadia Putri Anindya', 'status' => UserStatus::Active, 'email' => 'nadia.anindya@example.test', 'email_verified_at' => now(), 'must_change_password' => true, 'activated_at' => '2026-07-01 08:00:00'],
    );
    app(CompleteActivation::class)->changePassword($dated, TAM_PASSWORD, TAM_PASSWORD);

    expect($legacy->fresh()->email)->toBe('raka.pratama@example.test')
        ->and(AccountControls::date($legacy->fresh(), 'activated_at'))->toBeNull()
        ->and(AccountControls::date($dated->fresh(), 'activated_at')?->toDateTimeString())->toBe('2026-07-01 08:00:00')
        ->and(Activity::query()->where('event', CompleteActivation::EVENT_ACTIVATED)->exists())->toBeFalse();
});

it('still activates a pending student the normal way, with the date and the audit row', function (): void {
    $user = tamStudent([], ['profile_confirmed_at' => now(), 'phone' => '081234567890']);
    $activation = app(CompleteActivation::class);
    $activation->bindEmail($user, tamOtp($user, 'raka.pratama@example.test'));
    $activation->changePassword($user, TAM_PASSWORD, TAM_PASSWORD);

    expect($user->fresh()->status)->toBe(UserStatus::Active)
        ->and(AccountControls::date($user->fresh(), 'activated_at'))->not->toBeNull()
        ->and(Activity::query()->where('event', CompleteActivation::EVENT_ACTIVATED)->count())->toBe(1);
});

// --- Archive months include trashed students ----------------------------------------------------------------------

it('recalculates the archive date of a trashed student, so it follows the new rule after a restore', function (): void {
    $class = Classroom::factory()->level(12)->create(['name' => 'XII-2']);
    $trashed = tamStudent([], ['classroom_id' => $class->getKey(), 'grade12_start_date' => '2026-07-01', 'archive_at' => '2028-01-01']);
    $archived = tamStudent(['name' => 'Nadia Putri Anindya', 'status' => UserStatus::Archived], ['classroom_id' => $class->getKey(), 'grade12_start_date' => '2023-07-01', 'archive_at' => '2025-01-01']);
    [$entry] = tamTrash($trashed);
    tamTrash($archived);

    $result = app(UpdateLimits::class)->handle($this->admin, 24);

    $row = Student::withTrashed()->where('user_id', $trashed->getKey())->sole();
    expect($result['recalculated'])->toBe(1)
        ->and($row->trashed())->toBeTrue()
        ->and($row->archive_at->toDateString())->toBe('2028-07-01')
        ->and(Student::withTrashed()->where('user_id', $archived->getKey())->sole()->archive_at->toDateString())->toBe('2025-01-01')
        ->and(Activity::query()->where('event', 'settings.limits_updated')->sole()->properties['archive_dates_recalculated'])->toBe(1);

    app(RestoreFromTrash::class)->handle($this->admin, $entry);

    expect(Student::query()->where('user_id', $trashed->getKey())->sole()->archive_at->toDateString())->toBe('2028-07-01');
});
