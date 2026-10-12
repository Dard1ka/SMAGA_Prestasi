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
        ->assertSee('Pakai NISN dan kata sandi dari sekolah.')
        // M1-B: one field for NIS, NISN or the email bound at activation; the note follows the new order.
        ->assertSee('NISN atau email')->assertDontSee('NIS, NISN')
        ->assertSee('Sudah aktivasi? Bisa juga masuk pakai email. Guru dan staf: username atau email.')
        ->assertSee('Nanti diminta menghubungkan email dulu. Setelah itu boleh ganti kata sandi.')
        ->assertSee('name="identifier"', false)
        ->assertSee(route('password.request'), false)
        ->assertDontSee('sm4g4')
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('renders the forgot password page for guests', function (): void {
    $this->get(route('password.request'))
        ->assertOk()
        ->assertSee('Lupa kata sandi')
        ->assertSee('NISN atau email')
        ->assertDontSee('sm4g4');
});

it('renders the activation page for a first login: the email comes first', function (): void {
    $this->actingAs(H::pendingStudent()->user)
        ->get(route('aktivasi'))
        ->assertOk()
        ->assertSee('Langkah 1 dari 3')
        ->assertSee('Hubungkan email')
        ->assertSee('NISN '.H::NISN)->assertDontSee(H::NIS)
        ->assertDontSee('Kata sandi baru')
        ->assertDontSee('Lewati, pakai kata sandi sekarang')
        ->assertDontSee('sm4g4');
});

it('renders the password step with the skip button for a student whose email is bound', function (): void {
    $user = H::pendingStudent()->user;
    $user->forceFill(['email' => 'nadia.anindya@example.test', 'email_verified_at' => now()])->save();

    $this->actingAs($user)
        ->get(route('aktivasi'))
        ->assertOk()
        ->assertSee('Langkah 3 dari 3')
        ->assertSee('Ganti kata sandi')
        ->assertSee('Lewati, pakai kata sandi sekarang')
        ->assertSee('Minimal 10 karakter')
        // M2-A: the hint names every rule, including the common-password one.
        ->assertSee('Jangan pakai tanggal lahir, NIS/NISN, nama, atau kata yang mudah ditebak.')
        ->assertSee('n•••@example.test')
        // Never the full address of step 1, never a hint at the password from school.
        ->assertDontSee('nadia.anindya@example.test')
        ->assertDontSee('sm4g4');
});

it('shows the test mode banner only in local test mode', function (): void {
    app()->detectEnvironment(fn () => 'local');
    config(['fortify.sth.otp_test_mode' => true]);

    $user = H::pendingStudent()->user;
    app(IssueOtpCode::class)->handle($user, 'verify_email', 'nadia.anindya@example.test');

    $this->actingAs($user)->get(route('aktivasi'))->assertOk()->assertSee('Langkah 2 dari 3')->assertSee('Mode uji: kode apa pun diterima.');
});

it('forwards an activated student from /beranda and the tour link to the siswa landing', function (): void {
    // Wave 2 defined `siswa.beranda`; the tour is an overlay there (W2-A1).
    $this->actingAs(H::activeStudent()->user);

    $this->get(route('home'))->assertRedirect(route('siswa.beranda'));
    $this->get(route('siswa.tour'))->assertRedirect(route('siswa.beranda'));
});

it('keeps the placeholder home for an account without an area role', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    // W4: e.g. a new privileged staff account whose role still waits for a second Admin.
    $this->actingAs($user->fresh())->get(route('home'))->assertOk()->assertSee('Akun ini belum punya peran.');
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
