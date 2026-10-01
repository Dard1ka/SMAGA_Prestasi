<?php

declare(strict_types=1);

use App\Actions\Accounts\IssueOtpCode;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
    $this->withoutVite();
});

it('renders the login page without ever showing the initial password pattern', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Pakai NIS atau NISN dan kata sandi dari sekolah.')
        ->assertSee('name="identifier"', false)
        ->assertSee(route('password.request'), false)
        ->assertDontSee('sm4g4')
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('renders the forgot password page for guests', function (): void {
    $this->get(route('password.request'))->assertOk()->assertSee('Lupa kata sandi')->assertDontSee('sm4g4');
});

it('renders the activation page for a first login', function (): void {
    $this->actingAs(H::pendingStudent()->user)
        ->get(route('aktivasi'))
        ->assertOk()
        ->assertSee('Buat kata sandi baru')
        ->assertSee('Minimal 10 karakter')
        ->assertSee(H::NIS)
        ->assertDontSee('sm4g4');
});

it('shows the test mode banner only in local test mode', function (): void {
    app()->detectEnvironment(fn () => 'local');
    config(['fortify.sth.otp_test_mode' => true]);

    $user = H::pendingStudent()->user;
    $user->forceFill(['must_change_password' => false])->save();
    app(IssueOtpCode::class)->handle($user, 'verify_email', 'nadia.anindya@example.test');

    $this->actingAs($user)->get(route('aktivasi'))->assertOk()->assertSee('Mode uji: kode apa pun diterima.');
});

it('forwards an activated student from /beranda and the tour link to the siswa landing', function (): void {
    // Wave 2 defined `siswa.beranda`; the tour is an overlay there (W2-A1).
    $this->actingAs(H::activeStudent()->user);

    $this->get(route('home'))->assertRedirect(route('siswa.beranda'));
    $this->get(route('siswa.tour'))->assertRedirect(route('siswa.beranda', ['tur' => 1]));
});

it('keeps the placeholder home for an account without an area role', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    // W4: e.g. a new privileged staff account whose role still waits for a second Admin.
    $this->actingAs($user->fresh())->get(route('home'))->assertOk()->assertSee('Akunmu belum punya peran.');
});

it('renders the password confirmation and 2FA setup pages for staff', function (): void {
    $staff = H::staff(RoleName::Admin);
    $this->actingAs($staff);

    $this->get(route('password.confirm'))->assertOk()->assertSee('Konfirmasi kata sandi');

    $this->post(route('password.confirm.store'), ['password' => H::PASSWORD])->assertRedirect();

    $this->get(route('two-factor.setup'))->assertOk()->assertSee('Pindai kode QR')->assertSee('data:image/svg+xml;base64,', false);
});

it('rejects a wrong password on the confirmation page', function (): void {
    $this->actingAs(H::staff(RoleName::Admin))
        ->from(route('password.confirm'))
        ->post(route('password.confirm.store'), ['password' => 'salah-sekali-123'])
        ->assertSessionHasErrors('password');
});

it('renders the 2FA challenge page after the password step', function (): void {
    $staff = H::staff(RoleName::Kesiswaan);
    $staff->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->post('/login', ['identifier' => 'staf.kesiswaan@example.test', 'password' => H::PASSWORD])
        ->assertRedirect(route('two-factor.login'));

    $this->get(route('two-factor.login'))->assertOk()->assertSee('Verifikasi dua langkah');
});
