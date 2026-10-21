<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Http\Middleware\PreventCredentialPageCaching;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| M2-A (QA 2026-10-05, Q2-01): after "Keluar", Back brought the old login page back with NIS and password still
| filled in. Every page that shows or takes a password, a one-time code or a 2FA secret is sent with
| `Cache-Control: no-store`, so the browser keeps no copy of it (App\Http\Middleware\PreventCredentialPageCaching).
| Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
    $this->withoutVite();
});

function m2aAssertNotStored(TestResponse $response): TestResponse
{
    expect($response->headers->hasCacheControlDirective('no-store'))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('no-cache'))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('public'))->toBeFalse()
        ->and($response->headers->get('Pragma'))->toBe('no-cache');

    return $response;
}

it('sends the login page with no-store, also when the form comes back with an error', function (): void {
    m2aAssertNotStored($this->get('/login')->assertOk());

    H::pendingStudent();

    m2aAssertNotStored($this->from('/login')->post('/login', ['identifier' => H::NISN, 'password' => 'salah-sekali-123'])->assertRedirect('/login'));
    m2aAssertNotStored($this->from('/login')->post('/login', ['identifier' => H::NISN, 'password' => H::PASSWORD])->assertRedirect(route('aktivasi')));
});

it('sends the activation page with no-store', function (): void {
    m2aAssertNotStored($this->actingAs(H::pendingStudent()->user)->get(route('aktivasi'))->assertOk());
});

it('sends the forgot password page with no-store', function (): void {
    m2aAssertNotStored($this->get(route('password.request'))->assertOk());
});

it('sends the password confirmation page and its answer with no-store', function (): void {
    $this->actingAs(H::staff(RoleName::Admin));

    m2aAssertNotStored($this->get(route('password.confirm'))->assertOk());
    m2aAssertNotStored($this->from(route('password.confirm'))->post(route('password.confirm.store'), ['password' => 'salah-sekali-123'])->assertRedirect());
});

it('sends the 2FA challenge page with no-store', function (): void {
    $staff = H::staff(RoleName::Kesiswaan);
    $staff->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->post('/login', ['identifier' => 'staf.kesiswaan@example.test', 'password' => H::PASSWORD])
        ->assertRedirect(route('two-factor.login'));

    m2aAssertNotStored($this->get(route('two-factor.login'))->assertOk());
});

it('sends the 2FA setup page (QR code and secret) with no-store', function (): void {
    $this->actingAs(H::staff(RoleName::Guru))->withSession(['auth.password_confirmed_at' => time()]);

    m2aAssertNotStored($this->get(route('two-factor.setup'))->assertOk()->assertSee('Pindai kode QR'));
});

it('does not store the redirect that sends a guest away from a credential page either', function (): void {
    m2aAssertNotStored($this->get(route('aktivasi'))->assertRedirect(route('login')));
    m2aAssertNotStored($this->get(route('two-factor.setup'))->assertRedirect(route('login')));
});

// The repo has no JavaScript test runner: the behaviour of this block was tried by hand in a browser
// (docs/dev-notes/M2-A.md). This only keeps the block from being dropped without anybody noticing.
it('keeps the script that empties password fields on a restored page and after a plain form submit', function (): void {
    $script = (string) file_get_contents(resource_path('js/app.js'));

    expect($script)
        ->toContain("window.addEventListener('pageshow'")
        ->toContain('event.persisted')
        ->toContain("form.method !== 'post'")
        // Livewire forms are left alone on submit, a cancelled submit keeps what was typed.
        ->toContain("name.startsWith('wire:submit')")
        ->toContain('event.defaultPrevented')
        // QA 2026-10-06: the account name is emptied too, also on a page loaded again for Back/Forward.
        ->toContain('input[autocomplete="username"]')
        ->toContain("=== 'back_forward'");

    // The script finds the account name by this attribute.
    $this->get('/login')->assertOk()->assertSee('name="identifier"', false)->assertSee('autocomplete="username"', false);
});

it('covers exactly the credential paths and leaves other pages to their own cache headers', function (): void {
    expect(PreventCredentialPageCaching::PATHS)->toBe([
        'login', 'aktivasi', 'lupa-kata-sandi', 'user/confirm-password', 'two-factor-challenge', 'akun/2fa',
    ]);

    // The paths in the list are the real ones.
    expect([
        route('login', [], false), route('aktivasi', [], false), route('password.request', [], false),
        route('password.confirm', [], false), route('two-factor.login', [], false), route('two-factor.setup', [], false),
    ])->toBe(array_map(fn (string $path): string => '/'.$path, PreventCredentialPageCaching::PATHS));

    Route::middleware('web')->get('/_test/halaman-biasa', fn () => 'ok');
    Route::middleware('web')->get('/login-bantuan', fn () => 'ok');

    foreach (['/_test/halaman-biasa', '/login-bantuan'] as $path) {
        $response = $this->get($path)->assertOk();

        expect($response->headers->hasCacheControlDirective('no-store'))->toBeFalse()
            ->and($response->headers->has('Pragma'))->toBeFalse();
    }
});
