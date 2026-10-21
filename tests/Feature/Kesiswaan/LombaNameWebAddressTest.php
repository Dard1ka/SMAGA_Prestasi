<?php

declare(strict_types=1);

use App\Actions\Competitions\CreateCompetition;
use App\Actions\Competitions\SubmitCompetition;
use App\Actions\Competitions\UpdateCompetition;
use App\Enums\RoleName;
use App\Models\Competition;
use App\Rules\NoWebAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;
use Tests\Feature\Siswa\Lomba\LombaFixtures;

/*
| D-76: a lomba name must not carry a web address. The name is printed in mails from the school's address and mail
| clients turn anything that looks like an address into a link (phishing). Addresses belong in the URL fields.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    // Only siswa and Admin add a lomba (D-145).
    $this->admin = F::staff(RoleName::Admin, 'Operator STH');
});

it('finds a web address in a name', function (string $name): void {
    expect(NoWebAddress::found($name))->toBeTrue();
})->with([
    'scheme' => 'Lomba Sains https://situs-palsu.example/daftar',
    'upper-case scheme' => 'HTTP://SITUS.EXAMPLE',
    'www' => 'Lomba Sains, daftar ulang di www.situs-palsu.com',
    'bare host' => 'Olimpiade situs-palsu.com 2026',
    'school domain' => 'Lomba resmi lomba.sch.id',
    'link shortener' => 'Daftar bit.ly/daftar2026',
    'mail address' => 'Info: panitia@lomba.id',
    'markdown link' => '[Masuk](https://evil.example/login)',
]);

it('accepts ordinary lomba names, also with dots and numbers', function (string $name): void {
    expect(NoWebAddress::found($name))->toBeFalse();
})->with([
    'Olimpiade Sains Nasional (OSN) 2026',
    'KSN-K Tk. Kota Semarang 2026',
    'FLS3N Tk. Prov. Jateng',
    'Lomba Web Design Vol.2',
    'S.T.E.M Fair 4.0',
    'O2SN Cabang Pencak Silat No.1',
    'Debat Bahasa Inggris (NSDC)',
    'Lomba Karya Tulis Ilmiah: "Indonesia Emas"',
    'World Invention Competition and Exhibition (WICE)',
]);

it('refuses a usulan whose name carries a web address and stores nothing', function (): void {
    $siswa = F::student('Raka Pradipta', 'XII-B')->user;

    try {
        app(SubmitCompetition::class)->handle($siswa, LombaFixtures::usulan(['name' => 'Lomba Sains, daftar ulang di www.situs-palsu.com']), F::pdf());
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors()['name'][0] ?? null)->toBe(NoWebAddress::MESSAGE);
    }

    expect(Competition::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('still accepts a usulan with an ordinary name and the address in the website field', function (): void {
    $siswa = F::student('Raka Pradipta', 'XII-B')->user;

    $competition = app(SubmitCompetition::class)->handle($siswa, LombaFixtures::usulan([
        'name' => 'Olimpiade Sains Pelajar Nusantara 2026',
        'website_url' => 'https://olimpiade-fiktif.example/2026',
    ]), F::pdf());

    expect($competition->name)->toBe('Olimpiade Sains Pelajar Nusantara 2026')
        ->and($competition->website_url)->toBe('https://olimpiade-fiktif.example/2026');
});

it('refuses the same on the full form, on create and on update', function (): void {
    $create = app(CreateCompetition::class);

    try {
        $create->handle($this->admin, F::form(['name' => 'Lomba resmi www.situs-palsu.com']), F::pdf());
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors()['name'][0] ?? null)->toBe(NoWebAddress::MESSAGE);
    }

    $competition = $create->handle($this->admin, F::form(), F::pdf());

    expect(fn () => app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, F::form(['name' => 'Daftar di bit.ly/lomba'])))
        ->toThrow(ValidationException::class)
        ->and($competition->fresh()->name)->toBe(F::form()['name']);
});
