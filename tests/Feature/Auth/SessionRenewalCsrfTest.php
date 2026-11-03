<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Akun\Keamanan;
use App\Livewire\Auth\Activation;
use App\Livewire\Auth\TwoFactorSetup;
use App\Mail\OtpCodeMail;
use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Auth\AuthTestHelpers as H;
use Tests\TestCase;

/*
 | SV1 (browser verification for the school's server, 2026-10-30). The steps that renew the session id (the activation
 | code and password, the password change on Akun › Keamanan, the staff 2FA confirmation) used session()->regenerate(true),
 | which also gives the session a NEW CSRF token. The open page keeps the old one (<meta name="csrf-token">, which Livewire
 | sends, and the @csrf field of the logout form), so its next request answered 419 wherever the browser sends no
 | Sec-Fetch-Site header: plain http to an IP address, as on the school's server ("Sesi sudah berakhir" twice in every
 | activation, the 419 page after "Keluar"). The steps now call session()->migrate(true): a new id and the old row
 | destroyed (D-93), the token kept.
 |
 | Laravel skips the CSRF check while unit tests run (PreventRequestForgery::runningUnitTests()), so these tests bind the
 | same middleware without that exception (csrfKeepCheckTokens()) and send what the browser of the open page sends: the
 | session cookie of the last answer, the page's token and no Sec-Fetch-Site header. A control request with a token the
 | page does not carry shows that the check really runs.
 */

uses(RefreshDatabase::class);

const CSRF_KEEP_EMAIL = 'nadia.anindya@example.test';

const CSRF_KEEP_NEW_PASSWORD = 'Pelangi-senja-2026';

beforeEach(function (): void {
    H::seedRoles();
    Mail::fake();
    config(['session.driver' => 'database', 'fortify.sth.otp_test_mode' => false]);
});

/** Laravel's CSRF middleware without its exception for unit tests: a token the session does not hold answers 419. */
function csrfKeepCheckTokens(): void
{
    app()->bind(PreventRequestForgery::class, fn ($app): PreventRequestForgery => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
}

/**
 * The browser that holds the session `$sessionId`: only that cookie travels, and everything the test process still
 * remembers (the guard's user, the session data in memory) is dropped first, so the session row decides, as for a real
 * request. No Sec-Fetch-Site header, as over plain http to an IP address.
 */
function csrfKeepBrowser(string $sessionId): TestCase
{
    Auth::forgetGuards();
    session()->flush();

    return test()->withCredentials()->withCookie((string) config('session.cookie'), $sessionId);
}

/** The token the open page carries: <meta name="csrf-token">, which Livewire and every form of the page send. */
function csrfKeepPageToken(TestResponse $page): string
{
    preg_match('/<meta name="csrf-token" content="([^"]+)"/', (string) $page->assertOk()->getContent(), $match);

    expect($match)->not->toBeEmpty();

    return html_entity_decode($match[1], ENT_QUOTES);
}

/**
 * The next Livewire request of the open page, as livewire.js sends it: the component's snapshot and `_token` (read
 * from the page's meta tag) in the body, the session cookie of the last answer.
 *
 * @param  array<string, mixed>  $snapshot
 */
function csrfKeepLivewireRequest(string $sessionId, string $token, array $snapshot): TestResponse
{
    return csrfKeepBrowser($sessionId)->postJson(app('livewire')->getUpdateUri(), [
        '_token' => $token,
        'components' => [['snapshot' => json_encode($snapshot), 'updates' => (object) [], 'calls' => []]],
    ], ['X-Livewire' => '1']);
}

/** "Keluar": the logout form of the page with its @csrf token. */
function csrfKeepLogout(string $sessionId, string $token): TestResponse
{
    return csrfKeepBrowser($sessionId)->post(route('logout'), ['_token' => $token]);
}

/** The plaintext code of the newest queued activation mail to that address. */
function csrfKeepMailedCode(string $email): string
{
    $code = null;

    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use ($email, &$code): bool {
        if ($mail->hasTo($email)) {
            $code = $mail->code;
        }

        return true;
    });

    expect($code)->not->toBeNull();

    return (string) $code;
}

it('checks the token in these tests: a token the page does not carry answers 419', function (): void {
    H::pendingStudent();

    test()->from('/login')->post('/login', ['identifier' => H::NISN, 'password' => H::PASSWORD])->assertRedirect(route('aktivasi'));
    $session = session()->getId();
    $token = csrfKeepPageToken(csrfKeepBrowser($session)->get(route('aktivasi')));
    $snapshot = Livewire::test(Activation::class)->snapshot;

    csrfKeepCheckTokens();

    csrfKeepLivewireRequest($session, 'bukan-token-halaman-ini', $snapshot)->assertStatus(419);
    csrfKeepLivewireRequest($session, $token, $snapshot)->assertOk();
    csrfKeepLogout($session, 'bukan-token-halaman-ini')->assertStatus(419);
    csrfKeepLogout($session, $token)->assertRedirect();
});

it('keeps the token of the open activation page through the code and password steps, so its next requests pass', function (): void {
    $user = H::pendingStudent()->user;

    test()->from('/login')->post('/login', ['identifier' => H::NISN, 'password' => H::PASSWORD])->assertRedirect(route('aktivasi'));
    $atLogin = session()->getId();
    $token = csrfKeepPageToken(csrfKeepBrowser($atLogin)->get(route('aktivasi')));
    csrfKeepCheckTokens();

    $component = Livewire::test(Activation::class)
        ->set('email', CSRF_KEEP_EMAIL)
        ->call('sendCode')
        ->assertSet('step', Activation::STEP_CODE);

    $component->set('code', csrfKeepMailedCode(CSRF_KEEP_EMAIL))
        ->call('verifyCode')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_PROFILE);

    // D-93 as before: a new id, and the row of the old id is gone. New: the session keeps the token the page carries.
    $afterCode = session()->getId();
    expect($afterCode)->not->toBe($atLogin)
        ->and(DB::table('sessions')->where('id', $atLogin)->exists())->toBeFalse()
        ->and(session()->token())->toBe($token);

    session()->save(); // what StartSession does when the Livewire request ends: the browser now holds $afterCode

    // The next Livewire request of the page (Data diri) with the page's token: answered, not 419.
    csrfKeepLivewireRequest($afterCode, $token, $component->snapshot)->assertOk();

    $component->set('phone', '081234567890')
        ->call('saveProfile')
        ->assertSet('step', Activation::STEP_PASSWORD)
        ->set('password', CSRF_KEEP_NEW_PASSWORD)
        ->set('password_confirmation', CSRF_KEEP_NEW_PASSWORD)
        ->call('savePassword')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_DONE);

    $afterPassword = session()->getId();
    expect($afterPassword)->not->toBe($afterCode)
        ->and(DB::table('sessions')->where('id', $afterCode)->exists())->toBeFalse()
        ->and(session()->token())->toBe($token);

    session()->save();

    csrfKeepLivewireRequest($afterPassword, $token, $component->snapshot)->assertOk();
    expect(H::userBehindSession($afterPassword))->toBe($user->getKey());

    // "Keluar" on the activation page: its logout form still carries the token of the first page load.
    csrfKeepLogout($afterPassword, $token)->assertRedirect();
});

it('keeps the token of Akun › Keamanan after a password change, so "Keluar" works without a reload', function (): void {
    $user = User::query()->findOrFail(H::activeStudent()->user_id);

    test()->from('/login')->post('/login', ['identifier' => H::NISN, 'password' => H::PASSWORD])->assertRedirect(route('home'));
    $before = session()->getId();
    $token = csrfKeepPageToken(csrfKeepBrowser($before)->get(route('akun.keamanan')));
    csrfKeepCheckTokens();

    $component = Livewire::test(Keamanan::class)
        ->set('currentPassword', H::PASSWORD)
        ->set('password', 'Bintang-Pagi-77')
        ->set('password_confirmation', 'Bintang-Pagi-77')
        ->call('changePassword')
        ->assertHasNoErrors();

    $after = session()->getId();
    expect($after)->not->toBe($before)
        ->and(DB::table('sessions')->where('id', $before)->exists())->toBeFalse()
        ->and(session()->token())->toBe($token);

    session()->save();

    csrfKeepLivewireRequest($after, $token, $component->snapshot)->assertOk();
    expect(H::userBehindSession($after))->toBe($user->getKey());

    csrfKeepLogout($after, $token)->assertRedirect();
});

it('keeps the token of the 2FA setup page after the code is confirmed', function (): void {
    $staff = H::staff(RoleName::Kesiswaan);

    test()->post('/login', ['identifier' => 'staf.kesiswaan@example.test', 'password' => H::PASSWORD])->assertRedirect(route('home'));
    $before = session()->getId();
    session()->put('auth.password_confirmed_at', time()); // the setup page asks for the password first
    session()->save();
    $token = csrfKeepPageToken(csrfKeepBrowser($before)->get(route('two-factor.setup')));
    csrfKeepCheckTokens();

    $component = Livewire::test(TwoFactorSetup::class);
    $secret = Fortify::currentEncrypter()->decrypt($staff->fresh()->two_factor_secret);
    $component->set('code', app(Google2FA::class)->getCurrentOtp($secret))
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('showRecoveryCodes', true);

    $after = session()->getId();
    expect($after)->not->toBe($before)
        ->and(DB::table('sessions')->where('id', $before)->exists())->toBeFalse()
        ->and(session()->token())->toBe($token);

    session()->save();

    csrfKeepLivewireRequest($after, $token, $component->snapshot)->assertOk();
    csrfKeepLogout($after, $token)->assertRedirect();
});

it('keeps the session as it was when a step is refused', function (): void {
    H::pendingStudent();

    test()->from('/login')->post('/login', ['identifier' => H::NISN, 'password' => H::PASSWORD])->assertRedirect(route('aktivasi'));
    $atLogin = session()->getId();
    $token = session()->token();

    $component = Livewire::test(Activation::class)->set('email', CSRF_KEEP_EMAIL)->call('sendCode');
    $wrong = csrfKeepMailedCode(CSRF_KEEP_EMAIL) === '000000' ? '111111' : '000000';

    $component->set('code', $wrong)->call('verifyCode')->assertHasErrors('code');

    expect(session()->getId())->toBe($atLogin)
        ->and(session()->token())->toBe($token);
});
