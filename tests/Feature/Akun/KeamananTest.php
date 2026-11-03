<?php

declare(strict_types=1);

use App\Actions\Accounts\ListUserSessions;
use App\Domain\Accounts\PasswordPolicy;
use App\Enums\RoleName;
use App\Livewire\Akun\Keamanan;
use App\Mail\OtpCodeMail;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Accounts\EmailBindingNotice;
use App\Notifications\Accounts\SecurityNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| B1 — Akun › Keamanan (akun.keamanan): password change, email change (password + OTP, K8 notices),
| devices, staff 2FA link; access for every role. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();
    Mail::fake();
});

function b1Student(): User
{
    return User::query()->findOrFail(H::activeStudent()->user_id);
}

// Access -----------------------------------------------------------------------------------------

it('opens for every signed-in role and sends guests to the login', function (): void {
    $this->get(route('akun.keamanan'))->assertRedirect(route('login'));

    $this->actingAs(b1Student())->get(route('akun.keamanan'))
        ->assertOk()->assertSee('Kata sandi')->assertSee('Perangkat yang masuk')->assertDontSee('Verifikasi dua langkah');

    foreach ([RoleName::Admin, RoleName::Kesiswaan, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas, RoleName::Guru] as $role) {
        $this->actingAs(A::staff($role))->get(route('akun.keamanan'))
            ->assertOk()->assertSee('Verifikasi dua langkah')->assertSee(route('two-factor.setup'), false);
    }
});

it('keeps unfinished activations and staff without 2FA out', function (): void {
    $this->actingAs(User::query()->findOrFail(H::pendingStudent()->user_id))
        ->get(route('akun.keamanan'))->assertRedirect(route('aktivasi'));

    $this->actingAs(H::staff(RoleName::Kesiswaan))
        ->get(route('akun.keamanan'))->assertRedirect(route('two-factor.setup'));
});

// Password ---------------------------------------------------------------------------------------

it('changes the password with the current one: other devices out, audited, owner told', function (): void {
    $user = b1Student();
    H::fakeSessions($user, 2);
    $this->actingAs($user);

    Livewire::test(Keamanan::class)
        ->set('currentPassword', 'salah')
        ->set('password', 'Bintang-Pagi-77')
        ->set('password_confirmation', 'Bintang-Pagi-77')
        ->call('changePassword')
        ->assertHasErrors('currentPassword')
        ->set('currentPassword', H::PASSWORD)
        ->call('changePassword')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast');

    $user->refresh();
    expect(Hash::check('Bintang-Pagi-77', $user->password))->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $user->getKey())->where('id', 'like', 'sess-%')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'password.changed')->where('subject_id', (string) $user->getKey())->exists())->toBeTrue();

    Notification::assertSentTo($user, SecurityNotice::class, fn (SecurityNotice $n) => $n->kind === SecurityNotice::PASSWORD_CHANGED);
});

it('applies the student password policy (min 10, no birth date / NIS / name) and refuses the same password', function (string $new, string $error): void {
    $user = b1Student();
    $user->forceFill(['password' => Hash::make('Rahasia-Lama-2026')])->save();
    $this->actingAs($user);

    Livewire::test(Keamanan::class)
        ->set('currentPassword', 'Rahasia-Lama-2026')
        ->set('password', $new)
        ->set('password_confirmation', $new)
        ->call('changePassword')
        ->assertHasErrors('password');

    expect(Hash::check('Rahasia-Lama-2026', $user->fresh()->password))->toBeTrue();
})->with([
    'too short' => ['Abc12345', 'min'],
    'birth date' => ['lahir02032010x', 'policy'],
    'NIS' => ['nis'.H::NIS.'ab', 'policy'],
    'same as now' => ['Rahasia-Lama-2026', 'same'],
    // M2-A (QA Q1-02): a common word stays common with digits or symbols around it.
    'common password' => ['password123', 'policy'],
    'common word with symbols' => ['!!Sekolah-2026!!', 'policy'],
]);

// M2-A (QA 2026-10-05, Q2-05): staff have no NIS; their username, email name and own name are refused instead.
it('refuses a staff password built from the username, the email name or the own name', function (string $new, string $message): void {
    $staff = A::staff(RoleName::Humas, ['name' => 'Rina Kusumawati', 'username' => 'uji.qa2', 'email' => 'humas.dua@example.test']);
    $this->actingAs($staff);

    $component = Livewire::test(Keamanan::class)
        // The hint describes the staff rules, not the student's.
        ->assertSee('Jangan pakai nama, nama pengguna, email, atau kata yang mudah ditebak.')
        ->assertDontSee('NIS/NISN')
        ->set('currentPassword', H::PASSWORD)
        ->set('password', $new)
        ->set('password_confirmation', $new)
        ->call('changePassword')
        ->assertHasErrors('password');

    expect($component->errors()->get('password'))->toBe([$message])
        ->and(Hash::check(H::PASSWORD, $staff->fresh()->password))->toBeTrue();
})->with([
    'the QA finding: username-username' => ['uji.qa2-uji.qa2', PasswordPolicy::MSG_USERNAME],
    'email name' => ['Humas-dua-2026x', PasswordPolicy::MSG_EMAIL],
    'own name' => ['Kusumawati-77-ok', PasswordPolicy::MSG_OWN_NAME],
    'common password' => ['Admin-12345-admin', PasswordPolicy::MSG_TOO_COMMON],
]);

it('accepts a staff password that uses none of those and shows students their own hint', function (): void {
    $staff = A::staff(RoleName::Humas, ['name' => 'Rina Kusumawati', 'username' => 'uji.qa2', 'email' => 'humas.dua@example.test']);
    $this->actingAs($staff);

    Livewire::test(Keamanan::class)
        ->set('currentPassword', H::PASSWORD)
        ->set('password', 'Bintang-Pagi-77')
        ->set('password_confirmation', 'Bintang-Pagi-77')
        ->call('changePassword')
        ->assertHasNoErrors();

    expect(Hash::check('Bintang-Pagi-77', $staff->fresh()->password))->toBeTrue();

    $this->actingAs(b1Student());

    Livewire::test(Keamanan::class)
        ->assertSee('Minimal 10 karakter, ada huruf dan angka. Jangan pakai tanggal lahir, NIS/NISN, nama, atau kata yang mudah ditebak.');
});

// M2-A (QA 2026-10-05, Q2-07): a refused attempt leaves the session alone; a real change renews the id, deletes the
// old row and still lists this device.
it('renews the session only when the password really changed and leaves no ghost device', function (): void {
    $user = b1Student();

    $this->from('/login')->post('/login', ['identifier' => H::NISN, 'password' => H::PASSWORD])->assertRedirect(route('home'));

    $old = session()->getId();
    H::fakeSessions($user, 2);

    expect(H::sessionCount($user))->toBe(3)
        // Control: this is how a live session id answers.
        ->and(H::userBehindSession($old))->toBe($user->getKey());

    $component = Livewire::test(Keamanan::class)
        ->assertSee('Perangkat ini')
        ->set('currentPassword', 'salah')
        ->set('password', 'Bintang-Pagi-77')
        ->set('password_confirmation', 'Bintang-Pagi-77')
        ->call('changePassword')
        ->assertHasErrors('currentPassword')
        ->set('currentPassword', H::PASSWORD)
        ->set('password', 'password123')
        ->set('password_confirmation', 'password123')
        ->call('changePassword')
        ->assertHasErrors('password');

    // Two refused attempts: the same session id, no new row ("device").
    expect(session()->getId())->toBe($old)
        ->and(H::sessionCount($user))->toBe(3);

    $component->set('password', 'Bintang-Pagi-77')
        ->set('password_confirmation', 'Bintang-Pagi-77')
        ->call('changePassword')
        ->assertHasNoErrors()
        // Right after the change, without a reload: this device is still listed.
        ->assertSee('Perangkat ini')
        ->assertDontSee('Tidak ada perangkat lain.')
        ->assertDontSee('Keluarkan semua perangkat lain');

    $new = session()->getId();
    expect($new)->not->toBe($old)
        ->and(DB::table('sessions')->where('id', $old)->exists())->toBeFalse()
        ->and(H::sessionCount($user))->toBe(0); // the other devices and the old row; the new row comes when the request ends

    session()->save(); // what StartSession does when the request ends (Livewire tests skip the middleware)

    expect(H::sessionCount($user))->toBe(1)
        ->and(H::userBehindSession($new))->toBe($user->getKey())
        ->and(H::userBehindSession($old))->toBeNull();
});

it('lists the current device even when its session row is not written yet', function (): void {
    $user = b1Student();
    H::fakeSessions($user, 1);

    $sessions = app(ListUserSessions::class)->handle(
        $user,
        'belum-ada-barisnya',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/129.0 Safari/537.36',
        '10.0.0.7',
    );

    expect($sessions)->toHaveCount(2)
        ->and($sessions[0]['current'])->toBeTrue()
        ->and($sessions[0]['device'])->toBe('Chrome di Windows')
        ->and($sessions[0]['ip'])->toBe('10.0.0.7')
        ->and($sessions[0]['key'])->toBe(ListUserSessions::key('belum-ada-barisnya'))
        ->and($sessions[1]['current'])->toBeFalse()
        // A row that exists is never doubled.
        ->and(app(ListUserSessions::class)->handle($user, 'sess-'.$user->getKey().'-0'))->toHaveCount(1)
        // Without a current session id nothing is made up.
        ->and(app(ListUserSessions::class)->handle($user, null))->toHaveCount(1);
});

it('rate-limits wrong current passwords', function (): void {
    $this->actingAs(b1Student());
    $component = Livewire::test(Keamanan::class)->set('password', 'Bintang-Pagi-77')->set('password_confirmation', 'Bintang-Pagi-77');

    foreach (range(1, 5) as $i) {
        $component->set('currentPassword', 'salah-'.$i)->call('changePassword');
    }

    $component->set('currentPassword', H::PASSWORD)->call('changePassword')->assertHasErrors('currentPassword');
});

// Email ------------------------------------------------------------------------------------------

it('changes the email with password + code to the new address; both addresses get a "Bukan saya?" notice', function (): void {
    $user = b1Student();
    $this->actingAs($user);

    $component = Livewire::test(Keamanan::class)
        ->set('newEmail', 'nadia.baru@example.test')
        ->set('emailPassword', 'salah')
        ->call('sendEmailCode')
        ->assertHasErrors('emailPassword')
        ->set('emailPassword', H::PASSWORD)
        ->call('sendEmailCode')
        ->assertHasNoErrors()
        ->assertSet('codeSentTo', 'n•••@example.test');

    $code = null;
    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code): bool {
        $code = $mail->code;

        return $mail->hasTo('nadia.baru@example.test');
    });

    $component->set('emailCode', $code === '000000' ? '111111' : '000000')->call('confirmEmail')->assertHasErrors('emailCode');
    expect($user->fresh()->email)->toBe('nadia.anindya@example.test');

    // A wrong guess spends an attempt but not the code.
    $component->set('emailCode', $code)->call('confirmEmail')->assertHasNoErrors()->assertSet('codeSentTo', null);

    $user->refresh();
    expect($user->email)->toBe('nadia.baru@example.test')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'account.email_changed')->sole()->properties['to'])->toBe('n•••@example.test');

    Notification::assertSentOnDemand(EmailBindingNotice::class, fn (EmailBindingNotice $n, array $c, AnonymousNotifiable $to) => $n->kind === EmailBindingNotice::CHANGED_OLD && $to->routes['mail'] === 'nadia.anindya@example.test');
    Notification::assertSentOnDemand(EmailBindingNotice::class, fn (EmailBindingNotice $n, array $c, AnonymousNotifiable $to) => $n->kind === EmailBindingNotice::CHANGED_NEW && $to->routes['mail'] === 'nadia.baru@example.test');
    Notification::assertSentTo($user, SecurityNotice::class, fn (SecurityNotice $n) => $n->kind === SecurityNotice::EMAIL_CHANGED);
    expect(DB::table('email_notices')->where('user_id', $user->getKey())->count())->toBe(2);
});

it('refuses an email that another account uses or the current one', function (): void {
    User::factory()->create(['email' => 'dipakai@example.test']);
    $this->actingAs(b1Student());

    Livewire::test(Keamanan::class)
        ->set('emailPassword', H::PASSWORD)
        ->set('newEmail', 'dipakai@example.test')
        ->call('sendEmailCode')
        ->assertHasErrors('newEmail')
        ->set('newEmail', 'NADIA.anindya@example.test')
        ->call('sendEmailCode')
        ->assertHasErrors('newEmail')
        ->set('newEmail', 'bukan-email')
        ->call('sendEmailCode')
        ->assertHasErrors('newEmail');

    Mail::assertNothingQueued();
});

// Devices ----------------------------------------------------------------------------------------

it('lists own devices without exposing session ids and signs out one or all other devices', function (): void {
    $user = b1Student();
    $other = b1StudentOther();
    H::fakeSessions($user, 3);
    H::fakeSessions($other, 1);
    DB::table('sessions')->where('id', 'sess-'.$user->getKey().'-0')->update(['user_agent' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/129.0 Mobile Safari/537.36']);
    $this->actingAs($user);

    $component = Livewire::test(Keamanan::class)
        ->assertSee('Chrome di Android')
        ->assertDontSee('sess-'.$user->getKey().'-0');

    // Another user's session key does nothing.
    $component->call('revokeSession', ListUserSessions::key('sess-'.$other->getKey().'-0'));
    expect(H::sessionCount($other))->toBe(1);

    $component->call('revokeSession', ListUserSessions::key('sess-'.$user->getKey().'-0'));
    expect(H::sessionCount($user))->toBe(2)
        ->and(Activity::query()->where('event', 'account.session_revoked')->exists())->toBeTrue();

    $component->call('revokeOtherSessions');
    expect(H::sessionCount($user))->toBe(0);
});

function b1StudentOther(): User
{
    return User::query()->findOrFail(
        Student::factory()->create()->user_id,
    );
}
