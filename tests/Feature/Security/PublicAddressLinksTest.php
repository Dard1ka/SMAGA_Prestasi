<?php

declare(strict_types=1);

use App\Actions\Accounts\SendEmailBindingNotice;
use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterDraft;
use App\Actions\Letters\LetterPdf;
use App\Domain\Letters\PdfQrCode;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Models\User;
use App\Notifications\Accounts\EmailBindingNotice;
use App\Support\PublicAddress;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Tests\Feature\Auth\AuthTestHelpers as H;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
 | SV1 (browser verification for the school's server, 2026-10-30): a link that leaves the app (the QR code and the
 | printed check address of a surat tugas, the signed "Bukan saya?" link of an email-binding notice) was built on the
 | host of the request. A letter TU made while the app was opened by its LAN address carried that address for good, and
 | the mailed link was signed for it, so it was refused at the public address. Both now use config('app.url'), unless
 | it is empty or a loopback address (a laptop copy: App\Support\PublicAddress), where the request host is the only one.
 | phpunit.xml does not force APP_URL, so every test sets it here.
 */

uses(RefreshDatabase::class);

const SV1_PUBLIC_ADDRESS = 'http://203.0.113.10:8149';

const SV1_LAN_ADDRESS = 'http://192.168.10.5:8149';

beforeEach(function (): void {
    H::seedRoles();
    Notification::fake();
    config(['app.url' => SV1_PUBLIC_ADDRESS]);

    // The request came in on the school's LAN address: route() alone builds every link on it.
    URL::setRequest(Request::create(SV1_LAN_ADDRESS.'/tu/surat'));
});

function publicAddressDraft(): LetterDraft
{
    return new LetterDraft(
        number: '400.3.8/313/MRD/X/2026',
        issuedOn: CarbonImmutable::today(),
        basisFrom: '',
        basisNumber: null,
        basisDate: null,
        scheduleDay: 'Sabtu',
        scheduleDate: '7 November 2026',
        scheduleTime: '07.00 WIB s.d Selesai',
        place: 'Daring',
    );
}

it('prints the letter check and its QR code on the public address, not on the host TU opened the app with', function (): void {
    $tu = F::staff(RoleName::TataUsaha);
    F::signature();
    $registration = F::registration(RegistrationStatus::Disetujui, ['Raka Pradipta'], F::mentor());

    // What the PDF template prints under the QR code, and the QR picture written into the render sandbox.
    $printed = [];
    View::composer('pdf::surat-tugas-siswa', function (ViewContract $view) use (&$printed): void {
        $data = $view->getData();
        $printed = ['url' => $data['verification']['url'], 'qr' => file_get_contents($data['pdfImages']['qr.png'])];
    });

    $letter = app(IssueLetters::class)->handle($tu, $registration, LetterType::Siswa, publicAddressDraft());
    $expected = SV1_PUBLIC_ADDRESS.'/verifikasi-surat/'.$letter->verification_code;

    // Control: route() alone would have printed the LAN address.
    expect(route('public.surat.verifikasi', ['code' => $letter->verification_code]))->toStartWith(SV1_LAN_ADDRESS.'/')
        ->and($printed['url'])->toBe($expected)
        ->and(LetterPdf::verificationUrl($letter))->toBe($expected)
        // The same picture as a QR code of the public address (the encoder is deterministic).
        ->and($printed['qr'])->toBe(app(PdfQrCode::class)->png($expected, 6));

    // The printed address opens the public check.
    $this->get($expected)->assertOk()->assertSee('Sah');
});

it('keeps the request host for the letter check only when no APP_URL is set', function (): void {
    config(['app.url' => '']);
    F::signature();
    $letter = app(IssueLetters::class)->handle(
        F::staff(RoleName::TataUsaha),
        F::registration(RegistrationStatus::Disetujui, ['Raka Pradipta'], F::mentor()),
        LetterType::Siswa,
        publicAddressDraft(),
    );

    expect(LetterPdf::verificationUrl($letter))->toBe(SV1_LAN_ADDRESS.'/verifikasi-surat/'.$letter->verification_code);
});

dataset('sv1 loopback app urls', [
    'localhost with port (smaga_server on the laptop)' => ['http://localhost:8090'],
    '127.0.0.1 (Smaga4 on the laptop)' => ['http://127.0.0.1:8080'],
    'a .localhost name' => ['http://app.localhost'],
    'IPv6 loopback' => ['http://[::1]:8080'],
]);

it('keeps the request host for the letter check when APP_URL is a loopback address (a laptop copy)', function (string $loopback): void {
    config(['app.url' => $loopback]);
    F::signature();
    $letter = app(IssueLetters::class)->handle(
        F::staff(RoleName::TataUsaha),
        F::registration(RegistrationStatus::Disetujui, ['Raka Pradipta'], F::mentor()),
        LetterType::Siswa,
        publicAddressDraft(),
    );

    expect(LetterPdf::verificationUrl($letter))->toBe(SV1_LAN_ADDRESS.'/verifikasi-surat/'.$letter->verification_code);
})->with('sv1 loopback app urls');

it('signs the "Bukan saya?" link on the request host when APP_URL is a loopback address (a laptop copy)', function (string $loopback): void {
    config(['app.url' => $loopback]);
    $user = User::query()->findOrFail(H::activeStudent()->user_id);

    app(SendEmailBindingNotice::class)->handle($user, EmailBindingNotice::BOUND, 'nadia.anindya@example.test', 'nadia.anindya@example.test');

    Notification::assertSentOnDemand(EmailBindingNotice::class, fn (EmailBindingNotice $notice): bool => str_starts_with($notice->notMeUrl, SV1_LAN_ADDRESS.'/akun/bukan-saya/'));
})->with('sv1 loopback app urls');

it('reads the public address from APP_URL and leaves out empty and loopback values', function (string $appUrl, ?string $expected): void {
    config(['app.url' => $appUrl]);

    expect(PublicAddress::root())->toBe($expected);
})->with([
    'school server' => ['http://203.0.113.10:8149/', 'http://203.0.113.10:8149'],
    'domain' => ['https://prestasi.sman3-smg.sch.id', 'https://prestasi.sman3-smg.sch.id'],
    'LAN address' => ['http://192.168.100.200:8149', 'http://192.168.100.200:8149'],
    'empty' => ['', null],
    'localhost' => ['http://localhost:8090', null],
    '127.0.0.1' => ['http://127.0.0.1:8080', null],
    '127.1.2.3' => ['http://127.1.2.3', null],
    'IPv6 loopback' => ['http://[::1]', null],
    '.localhost name' => ['http://sth.localhost', null],
]);

it('signs the "Bukan saya?" link for the public address, and it opens there', function (): void {
    $user = User::query()->findOrFail(H::activeStudent()->user_id);

    app(SendEmailBindingNotice::class)->handle($user, EmailBindingNotice::BOUND, 'nadia.anindya@example.test', 'nadia.anindya@example.test');

    $url = null;
    Notification::assertSentOnDemand(EmailBindingNotice::class, function (EmailBindingNotice $notice) use (&$url): bool {
        $url = $notice->notMeUrl;

        return true;
    });

    expect($url)->toStartWith(SV1_PUBLIC_ADDRESS.'/akun/bukan-saya/')
        ->and($url)->toContain('signature=')
        // Nothing else of the request changed: route() still answers on the request host.
        ->and(route('public.surat.verifikasi', ['code' => 'abc']))->toStartWith(SV1_LAN_ADDRESS.'/');

    // The `signed` middleware checks scheme, host and path: the link opens at the public address …
    $this->get($url)->assertOk()->assertSee('Bekukan akun');

    // … and not at the LAN address it would have been signed for before.
    $this->get(str_replace(SV1_PUBLIC_ADDRESS, SV1_LAN_ADDRESS, (string) $url))->assertForbidden();
});
