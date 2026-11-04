<?php

declare(strict_types=1);

use App\Actions\Registrations\CreateRegistration;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Livewire\Siswa\Lomba\Detail;
use App\Livewire\Siswa\Pendaftaran\Form;
use App\Notifications\Competitions\CompetitionSubmitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| FS1 QA round 4: the rows of the Pendaftaran lomba panel (Detail lomba and the second step of Usulkan lomba) keep the
| whole name at 390 px, the way BUG-N5 fixed Undangan tim; the bell entry of a new lomba proposal reads like its mail;
| the registration link on Detail lomba has the name both lomba forms give it. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    K::boot();
    Notification::fake();
    $this->withoutVite();
    $this->guru = F::staff(RoleName::Guru);
    $this->laras = F::student('Qa Talenta Laras Wulandari Kusumaningrum', 'XI-2');
    $this->bagas = F::student('Qa Talenta Bagas Pratama Wicaksono', 'XI-2');
    $this->bagus = F::student('Qa Talenta Bagas Prasetyo Nugroho', 'XI-2');
});

/** The rendered panel without Livewire's block markers, so a tag and its text can be matched together. */
function fs1qa4Plain(string $html): string
{
    return (string) preg_replace('/<!--\[if (?:END)?BLOCK\]><!\[endif\]-->/', '', $html);
}

/** The first <ul> whose opening tag carries $attribute, with everything inside it. */
function fs1qa4List(string $html, string $attribute): string
{
    $start = strpos($html, $attribute);

    if ($start === false) {
        return '';
    }

    $from = (int) strrpos(substr($html, 0, $start), '<ul');
    preg_match('/<ul[^>]*>.*?<\/ul>/s', substr($html, $from), $list);

    return $list[0] ?? '';
}

/** A name that may wrap: no one-line cut ("Qa Talenta Baga…"), the name in its own shrinkable, wrapping span. */
function fs1qa4ExpectWrappedName(string $list, string $name): void
{
    expect($list)->toMatch('/<span class="min-w-0 [^"]*\[overflow-wrap:anywhere\][^"]*">\s*'.preg_quote($name, '/').'\s*<\/span>/');
}

it('keeps whole names in the member search and the member rows before sending (BUG-N5b)', function (): void {
    $lomba = F::teamCompetition(4);

    $form = Livewire::actingAs(F::user($this->laras))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->set('q', 'Qa Talenta Bagas');

    $results = fs1qa4List(fs1qa4Plain($form->html()), 'aria-label="Akun siswa yang cocok"');

    // The two classmates share the first three words; the whole name tells them apart. The row wraps: the "Tambah"
    // button moves under the name when the row is too narrow for both.
    expect($results)->toContain('Qa Talenta Bagas Pratama Wicaksono')
        ->toContain('Qa Talenta Bagas Prasetyo Nugroho')
        ->not->toContain('ellip')
        ->toMatch('/class="flex min-w-0 flex-1 flex-wrap[^"]*"/');
    fs1qa4ExpectWrappedName($results, 'Qa Talenta Bagas Pratama Wicaksono');
    fs1qa4ExpectWrappedName($results, 'Qa Talenta Bagas Prasetyo Nugroho');

    $form->call('tambah', $this->bagas->getKey());
    $members = fs1qa4List(fs1qa4Plain($form->html()), 'aria-labelledby="lbl-anggota"');

    // The ketua's own row ("Kamu" follows the name or takes the next line) and the added member's row.
    expect($members)->toContain('Qa Talenta Laras Wulandari Kusumaningrum')
        ->toContain('Qa Talenta Bagas Pratama Wicaksono')
        ->not->toContain('ellip');
    fs1qa4ExpectWrappedName($members, 'Qa Talenta Laras Wulandari Kusumaningrum');
    fs1qa4ExpectWrappedName($members, 'Qa Talenta Bagas Pratama Wicaksono');
});

it('keeps whole names in the team rows and the invite search after "Kirim pendaftaran" (BUG-N5b)', function (): void {
    $lomba = F::teamCompetition(4);
    app(CreateRegistration::class)
        ->handle(F::user($this->laras), $lomba, RegistrationType::Tim, [$this->bagas->getKey()], $this->guru->getKey());

    $form = Livewire::actingAs(F::user($this->laras))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->assertSee('Undang anggota pengganti');

    $team = fs1qa4List(fs1qa4Plain($form->html()), 'aria-labelledby="lbl-tim-kamu"');

    // The status chip and the × (Batalkan undangan) move under the name when the row is too narrow for all three.
    expect($team)->toContain('Qa Talenta Laras Wulandari Kusumaningrum')
        ->toContain('Qa Talenta Bagas Pratama Wicaksono')
        ->toContain('aria-label="Batalkan undangan Qa Talenta Bagas Pratama Wicaksono"')
        ->not->toContain('ellip')
        ->toMatch('/class="flex min-w-0 flex-1 flex-wrap[^"]*"/');
    fs1qa4ExpectWrappedName($team, 'Qa Talenta Laras Wulandari Kusumaningrum');
    fs1qa4ExpectWrappedName($team, 'Qa Talenta Bagas Pratama Wicaksono');

    $form->set('q', 'Qa Talenta Bagas Pras');
    $results = fs1qa4List(fs1qa4Plain($form->html()), 'aria-label="Akun siswa yang cocok"');

    expect($results)->toContain('Qa Talenta Bagas Prasetyo Nugroho')
        ->toContain('aria-label="Undang Qa Talenta Bagas Prasetyo Nugroho"')
        ->not->toContain('ellip');
    fs1qa4ExpectWrappedName($results, 'Qa Talenta Bagas Prasetyo Nugroho');
});

it('titles the bell entry of a new lomba proposal like its mail and keeps the body (BUG-LOMBA-08)', function (): void {
    $competition = F::pendingCompetition(F::user($this->laras), F::usulanAttributes(['name' => 'Olimpiade Sains Fiktif 2026']));
    $notice = new CompetitionSubmitted($competition, 'Qa Talenta Laras Wulandari Kusumaningrum');
    $kesiswaan = K::staff(RoleName::Kesiswaan);
    $bell = $notice->toArray($kesiswaan);

    expect($bell['title'])->toBe('Verifikasi usulan lomba: Olimpiade Sains Fiktif 2026')
        ->and($bell['title'])->toBe($notice->toMail($kesiswaan)->subject)
        ->and($bell['message'])->toBe('Qa Talenta Laras Wulandari Kusumaningrum mengusulkan Olimpiade Sains Fiktif 2026.');
});

it('names the registration link "Link registrasi" on Detail lomba, as both lomba forms do (BUG-LOMBA-20)', function (): void {
    $lomba = F::openCompetition(['registration_url' => 'https://example.org/nsif-2026/daftar']);

    Livewire::actingAs(F::user($this->laras))->test(Detail::class, ['competition' => $lomba])
        ->assertSeeHtml('<span class="font-bold">Link registrasi</span>')
        ->assertSeeHtml('<span class="sr-only"> Link registrasi (example.org, tab baru)</span>')
        ->assertDontSee('Link registrasi resmi');
});
