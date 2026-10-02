<?php

declare(strict_types=1);

use App\Actions\Accounts\ResetStudentAccount;
use App\Actions\Fortify\AuthenticateUser;
use App\Enums\RoleName;
use App\Livewire\Auth\TwoFactorSetup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

/*
 | TOTP is for staff only (SECURITY §3a). A student account, activated or not, must never be able to enrol a
 | second factor over Fortify's JSON routes: the real owner would be stuck at /two-factor-challenge and the Admin
 | reset (K10) would not help. Fictional data only.
 */

beforeEach(function (): void {
    H::seedRoles();
    $this->withoutVite();
});

/** Every Fortify route that manages TOTP: [HTTP method, route name]. */
dataset('two-factor management routes', [
    'enable' => ['POST', 'two-factor.enable'],
    'confirm' => ['POST', 'two-factor.confirm'],
    'disable' => ['DELETE', 'two-factor.disable'],
    'qr code' => ['GET', 'two-factor.qr-code'],
    'secret key' => ['GET', 'two-factor.secret-key'],
    'recovery codes' => ['GET', 'two-factor.recovery-codes'],
    'regenerate recovery codes' => ['POST', 'two-factor.regenerate-recovery-codes'],
]);

function h1Student(string $account): User
{
    $student = $account === 'pending' ? H::pendingStudent() : H::activeStudent();

    return User::query()->findOrFail($student->user_id);
}

/** @return array<string, mixed> */
function h1StaleTotp(): array
{
    return [
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ];
}

it('answers 404 to a student on every two-factor management route, even after confirming the password', function (string $account, string $method, string $route): void {
    $user = h1Student($account);
    $this->actingAs($user);

    // Before the password was confirmed: not a redirect to the confirmation page either.
    $this->json($method, route($route), ['code' => '123456'])->assertNotFound();

    $this->post(route('password.confirm.store'), ['password' => H::PASSWORD])->assertSessionHasNoErrors();

    $this->json($method, route($route), ['code' => '123456', 'force' => true])->assertNotFound();
    $this->call($method, route($route), ['code' => '123456'])->assertNotFound();

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
})->with(['pending', 'activated'])->with('two-factor management routes');

it('stops the takeover: a login with the school password cannot enrol TOTP before activation', function (): void {
    $student = H::pendingStudent();

    $this->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => H::PASSWORD])->assertRedirect(route('aktivasi'));
    $this->postJson(route('password.confirm.store'), ['password' => H::PASSWORD])->assertSuccessful();

    $this->postJson(route('two-factor.enable'))->assertNotFound();
    $this->postJson(route('two-factor.confirm'), ['code' => '123456'])->assertNotFound();

    expect($student->user->fresh()->two_factor_secret)->toBeNull();
});

it('answers 404 to a student on the TOTP setup screen and its Livewire component', function (): void {
    $user = h1Student('activated');
    $this->actingAs($user);

    $this->get(route('two-factor.setup'))->assertNotFound();

    $this->post(route('password.confirm.store'), ['password' => H::PASSWORD])->assertSessionHasNoErrors();
    $this->get(route('two-factor.setup'))->assertNotFound();

    Livewire::test(TwoFactorSetup::class)->assertNotFound();

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

it('treats an account with a student record as a student even when the siswa role is missing', function (): void {
    $user = h1Student('activated');
    $user->syncRoles([]);
    $this->actingAs($user->fresh())->withSession(['auth.password_confirmed_at' => time()]);

    $this->postJson(route('two-factor.enable'))->assertNotFound();
    Livewire::test(TwoFactorSetup::class)->assertNotFound();

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

it('still sends guests to the login', function (): void {
    $this->post(route('two-factor.enable'))->assertRedirect(route('login'));
    $this->postJson(route('two-factor.enable'))->assertUnauthorized();
});

it('still lets staff enable and confirm TOTP over the Fortify routes', function (RoleName $role): void {
    $staff = H::staff($role);
    $this->actingAs($staff);

    // Unchanged for staff: the password comes first.
    $this->postJson(route('two-factor.enable'))->assertStatus(423);
    $this->post(route('password.confirm.store'), ['password' => H::PASSWORD])->assertSessionHasNoErrors();

    $this->postJson(route('two-factor.enable'))->assertOk();

    $secret = Fortify::currentEncrypter()->decrypt($staff->fresh()->two_factor_secret);
    $this->getJson(route('two-factor.secret-key'))->assertOk()->assertJson(['secretKey' => $secret]);

    $this->postJson(route('two-factor.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertOk();

    expect($staff->fresh()->two_factor_confirmed_at)->not->toBeNull();

    $this->getJson(route('two-factor.recovery-codes'))->assertOk()->assertJsonCount(8);
    $this->postJson(route('two-factor.regenerate-recovery-codes'))->assertOk();
    $this->deleteJson(route('two-factor.disable'))->assertOk();

    expect($staff->fresh()->two_factor_secret)->toBeNull();
})->with([RoleName::Guru, RoleName::Admin]);

it('keeps the setup screen open for a new staff account whose role still waits for approval', function (): void {
    // W4: a privileged staff account has no role until a second Admin approves it; `staff.2fa` already sends it here.
    $user = User::factory()->create()->fresh();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->get(route('two-factor.setup'))->assertOk()->assertSee('Pindai kode QR');
    $this->postJson(route('two-factor.enable'))->assertOk();
});

it('removes TOTP from a student account on the Admin reset (K10)', function (): void {
    config(['session.driver' => 'database']);

    $user = h1Student('pending');
    $user->forceFill(h1StaleTotp())->save();

    $temporary = app(ResetStudentAccount::class)->handle(H::staff(RoleName::Admin), $user, 'Siswa tertahan di verifikasi dua langkah.');

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and(Activity::query()->where('event', 'account.reset')->sole()->properties['two_factor_removed'])->toBeTrue();

    $this->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => $temporary])->assertRedirect(route('aktivasi'));
    $this->assertAuthenticatedAs($user);
});

it('records in the reset audit entry that there was no TOTP to remove', function (): void {
    config(['session.driver' => 'database']);

    app(ResetStudentAccount::class)->handle(H::staff(RoleName::Admin), h1Student('activated'), 'Lupa kata sandi dan email.');

    expect(Activity::query()->where('event', 'account.reset')->sole()->properties['two_factor_removed'])->toBeFalse();
});

it('never sends a student to the TOTP challenge at login, even with a leftover secret', function (string $account, string $target): void {
    $user = h1Student($account);
    $user->forceFill(h1StaleTotp())->save();

    $this->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => H::PASSWORD])->assertRedirect(route($target));

    $this->assertAuthenticatedAs($user);
})->with([['pending', 'aktivasi'], ['activated', 'home']]);

it('still checks the password of a student with a leftover secret', function (): void {
    h1Student('activated')->forceFill(h1StaleTotp())->save();

    $this->from('/login')->post('/login', ['identifier' => H::NIS, 'password' => 'salah-sekali-123'])
        ->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);

    $this->assertGuest();
});

it('still sends staff with confirmed TOTP to the challenge', function (): void {
    H::staff(RoleName::Kesiswaan)->forceFill(h1StaleTotp())->save();

    $this->from('/login')->post('/login', ['identifier' => 'staf.kesiswaan@example.test', 'password' => H::PASSWORD])
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
});
