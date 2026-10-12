<?php

declare(strict_types=1);

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\CompleteActivation;
use App\Actions\Accounts\GenerateTemporaryPassword;
use App\Actions\Accounts\ImportStudents;
use App\Actions\Accounts\RecordFailedLogin;
use App\Actions\Accounts\ReopenActivationWindow;
use App\Actions\Accounts\ResetStudentAccount;
use App\Actions\Accounts\SendEmailBindingNotice;
use App\Actions\Accounts\UnfreezeAccount;
use App\Actions\Accounts\UpdateActivationWindow;
use App\Actions\Fortify\AuthenticateUser;
use App\Domain\Accounts\InitialPassword;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Livewire\Akun\BukanSaya;
use App\Models\Classroom;
use App\Models\OtpCode;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Accounts\AccountAlert;
use App\Notifications\Accounts\EmailBindingNotice;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| B1 — account controls of docs/SECURITY.md §3a: K1 activation window, K2 reopen, K3 freeze after 10 failed
| logins, K8 "Bukan saya?" notice, K10 random temporary password + reset-volume alert. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();

    $this->admin = A::admin();
    $this->kesiswaan = A::staff(RoleName::Kesiswaan);
});

function b1Login(string $identifier, string $password = H::PASSWORD): TestResponse
{
    return test()->from('/login')->post('/login', ['identifier' => $identifier, 'password' => $password]);
}

function b1User(User|Student $who): User
{
    return User::query()->findOrFail($who instanceof Student ? $who->user_id : $who->getKey());
}

function b1Expire(User $user): void
{
    $user->forceFill(['initial_password_expires_at' => now()->subMinute()])->saveQuietly();
}

// K1 ---------------------------------------------------------------------------------------------

it('gives imported students the activation window from the setting (default 14 days)', function (): void {
    Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);

    $path = tempnam(sys_get_temp_dir(), 'sth-b1-');
    $handle = fopen($path, 'wb');
    fputcsv($handle, ['Nama lengkap', 'NIS', 'NISN', 'Tanggal lahir', 'Kelas saat pendaftaran', 'No HP'], ',', '"', '');
    fputcsv($handle, ['Nadia Putri Anindya', H::NIS, H::NISN, '02-03-2010', 'X-1', ''], ',', '"', '');
    fclose($handle);

    try {
        app(ImportStudents::class)->handle($this->kesiswaan, $path, 'csv', '2026/2027', false);
    } finally {
        @unlink($path);
    }

    $user = b1User(Student::query()->where('nis', H::NIS)->sole());
    $expires = AccountControls::date($user, 'initial_password_expires_at');

    expect($expires)->not->toBeNull()
        ->and(abs($expires->diffInMinutes(now()->addDays(14))))->toBeLessThan(2);
});

it('reads the window length from the setting and falls back to 14 outside 1–30', function (): void {
    expect(app(AccountControls::class)->windowDays())->toBe(14);

    Setting::put(SettingKey::ActivationWindowDays, 21);
    expect(app(AccountControls::class)->windowDays())->toBe(21);

    Setting::put(SettingKey::ActivationWindowDays, 45);
    expect(app(AccountControls::class)->windowDays())->toBe(14);
});

it('refuses an expired initial password with the generic error, like a wrong one', function (): void {
    $student = H::pendingStudent();
    b1Expire($student->user);

    b1Login(H::NISN)->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    $this->assertGuest();

    b1Login(H::NISN, 'salah-sekali-123')->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    $this->assertGuest();
});

it('accepts the initial password inside the window', function (): void {
    $student = H::pendingStudent();
    $student->user->forceFill(['initial_password_expires_at' => now()->addDay()])->saveQuietly();

    b1Login(H::NISN)->assertRedirect(route('aktivasi'));
    $this->assertAuthenticatedAs($student->user);
});

it('ignores the window once the student chose an own password', function (): void {
    $active = H::activeStudent();
    b1Expire(b1User($active));

    b1Login(H::NISN)->assertRedirect(route('home'));
    $this->assertAuthenticatedAs(b1User($active));
});

// M1-B: the email comes first now, so an account can have a bound email while its password step is still open.
it('keeps the window and the failed-login count running while only the email is bound', function (): void {
    $student = H::pendingStudent();
    $student->user->forceFill(['email' => 'nadia.anindya@example.test', 'email_verified_at' => now()])->saveQuietly();

    b1Login(H::NISN, 'tebakan-salah-1')->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    expect(AccountControls::failedAttempts(b1User($student)))->toBe(1);

    b1Expire($student->user);

    b1Login(H::NISN)->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    $this->assertGuest();
});

it('ends the window once the student kept the password at the last activation step', function (): void {
    $student = H::pendingStudent();
    $user = b1User($student);
    $user->forceFill([
        'email' => 'nadia.anindya@example.test',
        'email_verified_at' => now(),
        'initial_password_expires_at' => now()->addDay(),
    ])->saveQuietly();

    app(CompleteActivation::class)->keepPassword($user);

    $this->travel(30)->days();

    b1Login(H::NISN)->assertRedirect(route('home'));
    $this->assertAuthenticatedAs(b1User($student));
});

it('lets only Admin change the window length (1–30 days), audited', function (): void {
    app(UpdateActivationWindow::class)->handle($this->admin, 10);

    expect(app(AccountControls::class)->windowDays())->toBe(10)
        ->and(Activity::query()->where('event', 'setting.activation_window')->sole()->properties['to'])->toBe(10)
        ->and(fn () => app(UpdateActivationWindow::class)->handle($this->admin, 31))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateActivationWindow::class)->handle($this->kesiswaan, 7))->toThrow(AuthorizationException::class);
});

// K2 ---------------------------------------------------------------------------------------------

it('reopens an expired window for 72 hours (Kesiswaan), audited, and the student can log in again', function (): void {
    $student = H::pendingStudent();
    b1Expire($student->user);

    $until = app(ReopenActivationWindow::class)->handle($this->kesiswaan, $student->user, 'Siswa sakit saat aktivasi kelas.');

    expect(abs($until->diffInMinutes(now()->addHours(72))))->toBeLessThan(2)
        ->and(AccountControls::initialPasswordExpired(b1User($student)))->toBeFalse();

    $log = Activity::query()->where('event', 'account.activation_reopened')->sole();
    expect($log->properties['reason'])->toBe('Siswa sakit saat aktivasi kelas.')
        ->and($log->causer_id)->toBe($this->kesiswaan->getKey());

    b1Login(H::NISN)->assertRedirect(route('aktivasi'));
});

it('refuses reopen without a reason, for active accounts, staff, or roles without student.import', function (): void {
    $pending = H::pendingStudent();
    $tu = A::staff(RoleName::TataUsaha);
    $reason = 'Siswa sakit saat aktivasi kelas.';

    expect(fn () => app(ReopenActivationWindow::class)->handle($this->kesiswaan, $pending->user, '  '))->toThrow(ValidationException::class, 'Isi alasannya.')
        ->and(fn () => app(ReopenActivationWindow::class)->handle($tu, $pending->user, $reason))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReopenActivationWindow::class)->handle($this->kesiswaan, A::staff(RoleName::Guru), $reason))->toThrow(ValidationException::class, 'Akun ini bukan akun siswa.');

    $pending->user->forceFill(['status' => UserStatus::Active, 'must_change_password' => false])->saveQuietly();

    expect(fn () => app(ReopenActivationWindow::class)->handle($this->kesiswaan, $pending->user, $reason))->toThrow(ValidationException::class, 'Akun ini sudah aktif, tidak perlu dibuka ulang.');
});

// M2-B (QA 2026-10-05, Q2-09): a reopen never shortens a deadline, and "ab" is not a reason.

it('needs a reason of at least 10 characters to reopen', function (string $reason): void {
    $student = H::pendingStudent();
    b1Expire($student->user);

    expect(fn () => app(ReopenActivationWindow::class)->handle($this->kesiswaan, $student->user, $reason))
        ->toThrow(ValidationException::class, 'Alasan minimal 10 karakter.');

    expect(AccountControls::initialPasswordExpired(b1User($student)))->toBeTrue()
        ->and(Activity::query()->where('event', 'account.activation_reopened')->exists())->toBeFalse();
})->with([
    'two letters' => ['ab'],
    'nine letters' => ['sembilan9'],
    'padded with spaces' => ["   ab       \n  "],
]);

it('refuses to reopen a window that still runs longer than 72 hours and leaves the deadline alone', function (): void {
    $this->travelTo(now()->startOfSecond());
    $student = H::pendingStudent();
    $deadline = now()->addDays(10);
    $student->user->forceFill(['initial_password_expires_at' => $deadline])->saveQuietly();

    expect(fn () => app(ReopenActivationWindow::class)->handle($this->kesiswaan, $student->user, 'Siswa minta waktu tambahan.'))
        ->toThrow(ValidationException::class, 'Belum perlu dibuka ulang.');

    expect(AccountControls::date(b1User($student), 'initial_password_expires_at')->equalTo($deadline))->toBeTrue()
        ->and(Activity::query()->where('event', 'account.activation_reopened')->exists())->toBeFalse()
        ->and(ReopenActivationWindow::notNeededMessage(b1User($student)))->toContain('Batas aktivasi siswa ini masih sampai');
});

it('moves a deadline with less than 72 hours left to now + 72 hours, never earlier', function (): void {
    $this->travelTo(now()->startOfSecond());
    $student = H::pendingStudent();
    $deadline = now()->addHours(5);
    $student->user->forceFill(['initial_password_expires_at' => $deadline])->saveQuietly();

    $until = app(ReopenActivationWindow::class)->handle($this->kesiswaan, $student->user, 'Siswa izin sampai Kamis.');

    $log = Activity::query()->where('event', 'account.activation_reopened')->sole();

    expect($until->equalTo(now()->addHours(72)))->toBeTrue()
        ->and(AccountControls::date(b1User($student), 'initial_password_expires_at')->equalTo($until))->toBeTrue()
        ->and($until->greaterThan($deadline))->toBeTrue()
        ->and(CarbonImmutable::parse($log->properties['previous_expires_at'])->equalTo($deadline))->toBeTrue()
        ->and(CarbonImmutable::parse($log->properties['expires_at'])->equalTo($until))->toBeTrue();
});

it('lifts a failed-login freeze without shortening a deadline that runs longer than 72 hours', function (): void {
    $this->travelTo(now()->startOfSecond());
    $student = H::pendingStudent();
    $deadline = now()->addDays(9);
    $student->user->forceFill([
        'initial_password_expires_at' => $deadline,
        'frozen_at' => now(), 'frozen_reason' => AccountControls::FROZEN_FAILED_ATTEMPTS, 'failed_activation_attempts' => 10,
    ])->saveQuietly();

    $until = app(ReopenActivationWindow::class)->handle($this->kesiswaan, $student->user, 'Sudah dicek wali kelas.');

    $user = b1User($student);
    expect($until->equalTo($deadline))->toBeTrue()
        ->and(AccountControls::isFrozen($user))->toBeFalse()
        ->and(AccountControls::failedAttempts($user))->toBe(0)
        ->and(AccountControls::date($user, 'initial_password_expires_at')->equalTo($deadline))->toBeTrue()
        ->and(Activity::query()->where('event', 'account.activation_reopened')->sole()->properties['unfrozen'])->toBe(AccountControls::FROZEN_FAILED_ATTEMPTS);
});

it('leaves a "Bukan saya" freeze of a pending student to the Admin, whatever the deadline is', function (): void {
    $student = H::pendingStudent();
    $student->user->forceFill([
        'initial_password_expires_at' => now()->subDay(),
        'frozen_at' => now(), 'frozen_reason' => AccountControls::FROZEN_NOT_ME,
    ])->saveQuietly();

    expect(fn () => app(ReopenActivationWindow::class)->handle($this->kesiswaan, $student->user, 'Siswa datang ke ruang Kesiswaan.'))
        ->toThrow(ValidationException::class, 'Hanya Admin yang bisa membukanya.');

    expect(AccountControls::isFrozen(b1User($student)))->toBeTrue()
        ->and(Activity::query()->where('event', 'account.activation_reopened')->exists())->toBeFalse();
});

it('computes the reopened deadline as the later one and knows when a reopen changes nothing', function (): void {
    $now = CarbonImmutable::parse('2026-10-05 08:15:00');
    $reopened = $now->addHours(72);

    expect(ReopenActivationWindow::deadlineAfter(null, $now)->equalTo($reopened))->toBeTrue()
        ->and(ReopenActivationWindow::deadlineAfter($now->subDay(), $now)->equalTo($reopened))->toBeTrue()
        ->and(ReopenActivationWindow::deadlineAfter($now->addHours(71), $now)->equalTo($reopened))->toBeTrue()
        // The QA case: a deadline on 15 Oct stays 15 Oct, it does not become 8 Oct.
        ->and(ReopenActivationWindow::deadlineAfter($now->addDays(10), $now)->equalTo($now->addDays(10)))->toBeTrue()
        ->and(ReopenActivationWindow::wouldChange($now->subDay(), false, $now))->toBeTrue()
        ->and(ReopenActivationWindow::wouldChange($now->addHours(71), false, $now))->toBeTrue()
        ->and(ReopenActivationWindow::wouldChange($now->addHours(72), false, $now))->toBeFalse()
        ->and(ReopenActivationWindow::wouldChange($now->addDays(10), false, $now))->toBeFalse()
        ->and(ReopenActivationWindow::wouldChange($now->addDays(10), true, $now))->toBeTrue()
        ->and(ReopenActivationWindow::wouldChange(null, false, $now))->toBeFalse();
});

// K3 ---------------------------------------------------------------------------------------------

it('counts wrong passwords on a pending student and freezes the account at 10, alerting Admin and Kesiswaan', function (): void {
    $student = H::pendingStudent();

    b1Login(H::NISN, 'tebakan-salah-1')->assertSessionHasErrors('identifier');
    expect(AccountControls::failedAttempts(b1User($student)))->toBe(1);

    H::fakeSessions(b1User($student), 1);

    foreach (range(2, 10) as $attempt) {
        app(RecordFailedLogin::class)->handle(b1User($student));
    }

    $user = b1User($student);
    expect(AccountControls::isFrozen($user))->toBeTrue()
        ->and(H::sessionCount($user))->toBe(0)
        ->and(AccountControls::frozenReason($user))->toBe(AccountControls::FROZEN_FAILED_ATTEMPTS)
        ->and(Activity::query()->where('event', 'account.frozen')->sole()->properties['attempts'])->toBe(10);

    Notification::assertSentTo([$this->admin, $this->kesiswaan], AccountAlert::class, fn (AccountAlert $n) => $n->kind === 'account.frozen');

    // The right password no longer works, and the message is the generic one.
    b1Login(H::NISN)->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    $this->assertGuest();
});

it('never freezes an activated account and ignores unknown identifiers', function (): void {
    $student = H::activeStudent();

    foreach (range(1, 12) as $attempt) {
        app(RecordFailedLogin::class)->handle(b1User($student));
    }

    b1Login('999999999', 'apa-saja-123')->assertSessionHasErrors('identifier');

    expect(AccountControls::isFrozen(b1User($student)))->toBeFalse()
        ->and(AccountControls::failedAttempts(b1User($student)))->toBe(0);
    Notification::assertNothingSentTo($this->admin);
});

it('lifts a failed-login freeze through reopen (Kesiswaan) and resets the counter', function (): void {
    $student = H::pendingStudent();
    $student->user->forceFill(['frozen_at' => now(), 'frozen_reason' => AccountControls::FROZEN_FAILED_ATTEMPTS, 'failed_activation_attempts' => 10])->saveQuietly();

    app(ReopenActivationWindow::class)->handle($this->kesiswaan, $student->user, 'Sudah dicek wali kelas.');

    $user = b1User($student);
    expect(AccountControls::isFrozen($user))->toBeFalse()
        ->and(AccountControls::failedAttempts($user))->toBe(0);
    b1Login(H::NISN)->assertRedirect(route('aktivasi'));
});

it('logs a frozen account out of a running session', function (): void {
    $student = H::activeStudent();
    $user = b1User($student);
    $this->actingAs($user);
    $user->forceFill(['frozen_at' => now(), 'frozen_reason' => AccountControls::FROZEN_NOT_ME])->saveQuietly();

    $this->get(route('akun.keamanan'))->assertRedirect(route('login'));
    $this->assertGuest();
});

// K8 ---------------------------------------------------------------------------------------------

// M1-B: the email is bound at step 2 of the activation (the notice goes out then); the account becomes active at
// step 3, when the student changes or keeps the password.
it('sends the bound address a notice with a single-use "Bukan saya?" link when the email is bound, before the password step', function (): void {
    $student = H::pendingStudent();
    $user = b1User($student);

    $otp = new OtpCode;
    $otp->forceFill([
        'user_id' => $user->getKey(), 'purpose' => CompleteActivation::PURPOSE, 'email' => 'nadia.anindya@example.test',
        'code_hash' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(5),
    ])->save();

    app(CompleteActivation::class)->bindEmail($user, $otp);

    $user = b1User($student);
    expect($user->status)->toBe(UserStatus::PendingActivation)
        ->and($user->email)->toBe('nadia.anindya@example.test')
        ->and(AccountControls::date($user, 'activated_at'))->toBeNull()
        ->and(DB::table('email_notices')->where('user_id', $user->getKey())->where('kind', 'bound')->count())->toBe(1);

    app(CompleteActivation::class)->keepPassword($user);

    $user = b1User($student);
    expect($user->status)->toBe(UserStatus::Active)
        ->and(AccountControls::date($user, 'activated_at'))->not->toBeNull()
        // One notice for the binding; closing the activation sends no second one.
        ->and(DB::table('email_notices')->where('user_id', $user->getKey())->count())->toBe(1);

    Notification::assertSentOnDemand(EmailBindingNotice::class, function (EmailBindingNotice $n, array $channels, AnonymousNotifiable $to): bool {
        return $n->kind === EmailBindingNotice::BOUND
            && $to->routes['mail'] === 'nadia.anindya@example.test'
            && str_contains($n->notMeUrl, '/akun/bukan-saya/')
            && str_contains($n->notMeUrl, 'signature=')
            && ! str_contains(implode(' ', $n->lines()), H::NIS)
            && ! str_contains(implode(' ', $n->lines()), H::NISN);
    });
});

it('freezes the account from the "Bukan saya?" page: GET changes nothing, the button freezes once', function (): void {
    $student = H::activeStudent();
    $user = b1User($student);
    H::fakeSessions($user, 2);

    app(SendEmailBindingNotice::class)->handle($user, EmailBindingNotice::CHANGED_OLD, 'lama@example.test', 'baru@example.test');

    $url = null;
    Notification::assertSentOnDemand(EmailBindingNotice::class, function (EmailBindingNotice $n) use (&$url): bool {
        $url = $n->notMeUrl;

        return true;
    });

    $this->get($url)->assertOk()->assertSee('Bekukan akun');
    expect(AccountControls::isFrozen(b1User($student)))->toBeFalse();

    // A tampered signature is refused.
    $this->get($url.'x')->assertForbidden();

    $notice = (string) DB::table('email_notices')->value('id');

    Livewire::test(BukanSaya::class, ['notice' => $notice])
        ->assertSet('state', BukanSaya::STATE_ASK)
        ->call('freeze')
        ->assertSet('state', BukanSaya::STATE_DONE);

    $user = b1User($student);
    expect(AccountControls::frozenReason($user))->toBe(AccountControls::FROZEN_NOT_ME)
        ->and(H::sessionCount($user))->toBe(0);
    Notification::assertSentTo($this->admin, AccountAlert::class, fn (AccountAlert $n) => $n->kind === 'account.reported_not_me');
    Notification::assertNotSentTo($this->kesiswaan, AccountAlert::class);

    Livewire::test(BukanSaya::class, ['notice' => $notice])->assertSet('state', BukanSaya::STATE_INVALID);
    b1Login(H::NISN)->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);

    // Kesiswaan cannot lift a "Bukan saya" freeze; Admin can, with a reason.
    expect(fn () => app(ReopenActivationWindow::class)->handle($this->kesiswaan, $user, 'Siswa datang ke ruang Kesiswaan.'))->toThrow(ValidationException::class)
        ->and(fn () => app(UnfreezeAccount::class)->handle($this->kesiswaan, $user, 'Alasan'))->toThrow(AuthorizationException::class);

    app(UnfreezeAccount::class)->handle($this->admin, $user, 'Pemilik datang dengan kartu pelajar.');
    expect(AccountControls::isFrozen(b1User($student)))->toBeFalse()
        ->and(Activity::query()->where('event', 'account.unfrozen')->sole()->properties['was'])->toBe(AccountControls::FROZEN_NOT_ME);
});

it('expires "Bukan saya?" links after 7 days', function (): void {
    $user = b1User(H::activeStudent());
    app(SendEmailBindingNotice::class)->handle($user, EmailBindingNotice::BOUND, 'nadia.anindya@example.test', 'nadia.anindya@example.test');
    $notice = (string) DB::table('email_notices')->value('id');

    $this->travel(8)->days();

    Livewire::test(BukanSaya::class, ['notice' => $notice])->assertSet('state', BukanSaya::STATE_INVALID);
    expect(AccountControls::isFrozen(b1User($user)))->toBeFalse();
});

// K10 --------------------------------------------------------------------------------------------

it('generates 12-character temporary passwords without look-alike characters', function (): void {
    $passwords = collect(range(1, 50))->map(fn () => app(GenerateTemporaryPassword::class)->handle());

    $passwords->each(function (string $pw): void {
        expect(strlen($pw))->toBe(12)
            ->and($pw)->toMatch('/[a-z]/')->toMatch('/[A-Z]/')->toMatch('/[2-9]/')
            ->and($pw)->not->toMatch('/[01OIl]/');
    });

    expect($passwords->unique()->count())->toBe(50);
});

it('resets to a random temporary password valid for 72 hours, never the initial pattern', function (): void {
    $student = H::activeStudent();
    $student->user->forceFill(['frozen_at' => now(), 'frozen_reason' => AccountControls::FROZEN_NOT_ME])->saveQuietly();

    $temporary = app(ResetStudentAccount::class)->handle($this->admin, b1User($student), 'Siswa datang ke TU, akun dibajak.');

    $user = b1User($student);
    expect(Hash::check($temporary, $user->password))->toBeTrue()
        ->and(Hash::check(app(InitialPassword::class)->make('2010-03-02'), $user->password))->toBeFalse()
        ->and($user->must_change_password)->toBeTrue()
        ->and(AccountControls::isFrozen($user))->toBeFalse()
        ->and(abs(AccountControls::date($user, 'initial_password_expires_at')->diffInMinutes(now()->addHours(72))))->toBeLessThan(2);

    $log = Activity::query()->where('event', 'account.reset')->sole();
    expect(json_encode($log->properties))->not->toContain($temporary);

    b1Login(H::NISN, $temporary)->assertRedirect(route('aktivasi'));
    auth()->logout();

    $this->travel(73)->hours();
    b1Login(H::NISN, $temporary)->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    $this->assertGuest();
});

it('alerts every Admin once when one Admin resets more than 10 accounts within an hour', function (): void {
    $second = A::staff(RoleName::Admin, ['username' => 'operator2']);
    $students = Student::factory()->count(11)->create();

    foreach ($students as $student) {
        app(ResetStudentAccount::class)->handle($this->admin, b1User($student), 'Reset massal uji.');
    }

    Notification::assertSentToTimes($this->admin, AccountAlert::class, 1);
    Notification::assertSentTo($second, AccountAlert::class, fn (AccountAlert $n) => $n->kind === 'account.reset_volume');
    expect(Activity::query()->where('event', 'security.reset_volume')->count())->toBe(1);
});

it('does not alert for 10 resets or fewer', function (): void {
    foreach (Student::factory()->count(10)->create() as $student) {
        app(ResetStudentAccount::class)->handle($this->admin, b1User($student), 'Reset uji.');
    }

    Notification::assertNotSentTo($this->admin, AccountAlert::class);
});
