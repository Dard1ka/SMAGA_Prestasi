<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Kesiswaan\Lomba\Form as KelolaLombaForm;
use App\Livewire\Lomba\UsulanForm;
use App\Models\Competition;
use App\Models\User;
use App\Rules\ExternalUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| The four link fields of the app answer one address the same way (the lead's mail test of 2026-10-09: "kadang bisa,
| kadang engga"): Siswa › Usulkan lomba › Link resmi, Guru › Upload info lomba › Link resmi, Kesiswaan › Kelola lomba ›
| Website resmi and Link registrasi. One rule (App\Rules\ExternalUrl), the same cleaning, the same sentence.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    K::boot();
    Notification::fake();
    $this->withoutVite();

    $this->siswa = F::user(F::student());
    $this->guru = K::staff(RoleName::Guru, 'Bu Retno Wulandari');
    $this->kesiswaan = K::staff(RoleName::Kesiswaan);
});

/**
 * Sends one address through the usulan sheet as that user.
 *
 * @return array{error: ?string, stored: ?string, shown: mixed}
 */
function linkThroughUsulan(User $user, bool $upload, string $typed): array
{
    $sheet = Livewire::actingAs($user)->test(UsulanForm::class, ['upload' => $upload])
        ->set('name', 'Lomba Uji Link 2026')
        ->set('organizer_name', 'Panitia Uji')
        ->set('website_url', $typed)
        ->set('brosur', UploadedFile::fake()->image('brosur.jpg', 600, 800))
        ->call('kirim');

    return [
        'error' => $sheet->errors()->first('website_url') ?: null,
        'stored' => Competition::query()->where('submitted_by', $user->getKey())->value('website_url'),
        'shown' => $sheet->get('website_url'),
    ];
}

/**
 * Sends one address through "Tambah lomba" of Kelola lomba, in the given link field.
 *
 * @return array{error: ?string, stored: ?string, invalid: bool}
 */
function linkThroughKelolaLomba(User $user, string $field, string $typed): array
{
    $name = 'Lomba Uji '.str_replace('_', ' ', $field);

    $drawer = Livewire::actingAs($user)->test(KelolaLombaForm::class)
        ->set('form.name', $name)
        ->set('form.bidang', 'sains')
        ->set('form.'.$field, $typed)
        ->call('save');

    return [
        'error' => $drawer->errors()->first('form.'.$field) ?: null,
        'stored' => Competition::query()->where('name', $name)->value($field),
        'invalid' => collect(data_get($drawer->effects, 'dispatches', []))->contains(fn (array $event): bool => ($event['name'] ?? null) === 'sth-form-invalid'),
    ];
}

it('answers one address the same way on all four link fields', function (string $typed, ?string $stored, ?string $sentence): void {
    $answers = [
        'siswa, Link resmi' => linkThroughUsulan($this->siswa, false, $typed),
        'guru, Link resmi' => linkThroughUsulan($this->guru, true, $typed),
        'kesiswaan, Website resmi' => linkThroughKelolaLomba($this->kesiswaan, 'website_url', $typed),
        'kesiswaan, Link registrasi' => linkThroughKelolaLomba($this->kesiswaan, 'registration_url', $typed),
    ];

    foreach ($answers as $field => $answer) {
        expect($answer['error'])->toBe($sentence, $field)
            ->and($answer['stored'])->toBe($stored, $field);
    }

    // The drawer brings its error into view like the sheet does.
    expect($answers['kesiswaan, Website resmi']['invalid'])->toBe($sentence !== null)
        ->and($answers['kesiswaan, Link registrasi']['invalid'])->toBe($sentence !== null);
})->with([
    // Accepted as typed (the three addresses of the lead's test guide first).
    'guide address, uji' => ['https://contoh.test/lomba-uji', 'https://contoh.test/lomba-uji', null],
    'guide address, siswa' => ['https://contoh.test/lomba-siswa', 'https://contoh.test/lomba-siswa', null],
    'guide address, guru' => ['https://contoh.test/lomba-guru', 'https://contoh.test/lomba-guru', null],
    'copied from a browser tab' => ['https://open.spotify.com/', 'https://open.spotify.com/', null],
    'school subdomain with a query' => ['https://sub.domain.sch.id/path?x=1', 'https://sub.domain.sch.id/path?x=1', null],
    'upper case' => ['HTTPS://CONTOH.TEST/Lomba-Uji', 'HTTPS://CONTOH.TEST/Lomba-Uji', null],
    'short link' => ['https://bit.ly/DaftarLomba2026', 'https://bit.ly/DaftarLomba2026', null],
    'form link' => ['https://docs.google.com/forms/d/e/1FAIpQLSexample/viewform?usp=sf_link', 'https://docs.google.com/forms/d/e/1FAIpQLSexample/viewform?usp=sf_link', null],
    // Accepted after cleaning.
    'space behind' => ['https://contoh.test/lomba-siswa ', 'https://contoh.test/lomba-siswa', null],
    'space in front' => [' https://contoh.test/lomba-siswa', 'https://contoh.test/lomba-siswa', null],
    'line break behind' => ["https://contoh.test/lomba-siswa\r\n", 'https://contoh.test/lomba-siswa', null],
    'no-break space and zero-width space' => ["\u{00A0}https://contoh.test/lomba-siswa\u{200B}", 'https://contoh.test/lomba-siswa', null],
    // Refused, each with its own sentence.
    'http' => ['http://contoh.test/lomba-siswa', null, ExternalUrl::HTTP],
    'no scheme' => ['contoh.test/lomba-uji', null, ExternalUrl::NO_HTTPS],
    'www without scheme' => ['www.contoh.test/lomba-uji', null, ExternalUrl::NO_HTTPS],
    'colon missing' => ['https//contoh.test/lomba-uji', null, ExternalUrl::NO_HTTPS],
    'typo in https' => ['htpps://contoh.test/lomba-siswa', null, ExternalUrl::NO_HTTPS],
    'comma for the dot' => ['https://contoh,test/lomba-siswa', null, ExternalUrl::HOST],
    'space for the hyphen' => ['https://contoh.test/lomba siswa', null, ExternalUrl::SPACE],
    'host without a dot' => ['https://intranet/lomba', null, ExternalUrl::HOST],
    'underscore in the host' => ['https://lomba_sains.contoh.test/', null, ExternalUrl::HOST],
    'ip address' => ['https://203.0.113.7/lomba', null, ExternalUrl::IP],
    'localhost' => ['https://localhost/lomba', null, ExternalUrl::IP],
    'odd port' => ['https://contoh.test:8443/lomba', null, ExternalUrl::PORT],
    'credentials' => ['https://user:rahasia@contoh.test/', null, ExternalUrl::CREDENTIALS],
    'en dash' => ['https://contoh.test/lomba–2026', null, ExternalUrl::CHARACTERS],
    'too long' => ['https://contoh.example/'.str_repeat('a', 478), null, ExternalUrl::TOO_LONG],
]);

it('accepts the addresses of the test guide for a student and for a guru alike', function (): void {
    foreach (['https://contoh.test/lomba-siswa', 'https://contoh.test/lomba-guru'] as $address) {
        foreach ([[$this->siswa, false], [$this->guru, true]] as [$user, $upload]) {
            Competition::query()->where('submitted_by', $user->getKey())->delete();

            expect(linkThroughUsulan($user, $upload, $address))->toMatchArray(['error' => null, 'stored' => $address]);
        }
    }
});

it('shows the same hint and input settings under both link fields of Kelola lomba', function (): void {
    Livewire::actingAs($this->kesiswaan)->test(KelolaLombaForm::class)
        ->assertSee('Website resmi')
        ->assertSee('Link registrasi')
        ->assertSee('Diawali https://.')
        ->assertSeeHtml('maxlength="500"')
        ->assertSeeHtml('spellcheck="false"')
        ->assertSeeHtml('autocapitalize="off"');
});

it('keeps both link fields of Kelola lomba closed to roles that do not manage lomba', function (RoleName $role): void {
    Livewire::actingAs(K::staff($role, 'Staf '.$role->value))->test(KelolaLombaForm::class)->assertForbidden();
})->with([RoleName::Guru, RoleName::Humas, RoleName::TataUsaha, RoleName::KepalaSekolah]);

it('keeps both link fields of Kelola lomba closed to a student', function (): void {
    Livewire::actingAs($this->siswa)->test(KelolaLombaForm::class)->assertForbidden();
});
