<?php

declare(strict_types=1);

use App\Actions\Registrations\CreateRegistration;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Livewire\Siswa\Pendaftaran\Form;
use App\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| FS1 findings after the final polish: the team list of the Pendaftaran lomba panel puts the ketua first in every state
| (a waiting member has no invited_at, which sorted the ketua last), its chips use the words of the Lomba saya team card,
| and the Persetujuan lomba screen never speaks of its own reader in the third person (D-119): Tata Usaha reads a line
| without "Tata Usaha" in it. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    K::boot();
    Notification::fake();
    $this->withoutVite();
    $this->guru = F::staff(RoleName::Guru);
    $this->nadia = F::student();
    $this->nadiaUser = F::user($this->nadia);
});

/** The rendered HTML without Livewire's block markers, so a tag and its text can be matched together. */
function fs1qa6Plain(string $html): string
{
    return (string) preg_replace('/<!--\[if (?:END)?BLOCK\]><!\[endif\]-->/', '', $html);
}

/** The <ul> whose opening tag carries $marker, or else the first <ul> after $marker, with everything inside it. */
function fs1qa6List(string $html, string $marker): string
{
    $start = strpos($html, $marker);

    if ($start === false) {
        return '';
    }

    $before = substr($html, 0, $start);
    $open = strrpos($before, '<ul');
    $inTag = $open !== false && strrpos($before, '>') < $open;
    preg_match('/<ul[^>]*>.*?<\/ul>/s', substr($html, $inTag ? $open : $start), $list);

    return $list[0] ?? '';
}

/**
 * The given names in the order they appear in $html; fails when one is missing.
 *
 * @param  list<string>  $names
 * @return list<string>
 */
function fs1qa6Order(string $html, array $names): array
{
    $at = [];

    foreach ($names as $name) {
        $position = strpos($html, $name);
        expect($position)->not->toBeFalse("{$name} is not in the list");
        $at[$name] = $position;
    }

    asort($at);

    return array_keys($at);
}

/** The names of the Form panel's team list and of the Lomba saya team card, each in screen order. */
function fs1qa6Orders(object $test, Registration $registration, array $names): array
{
    $form = Livewire::actingAs($test->nadiaUser)->test(Form::class, ['competitionId' => $registration->competition_id]);
    $panel = fs1qa6List(fs1qa6Plain($form->html()), 'aria-labelledby="lbl-tim-kamu"');

    $page = $test->actingAs($test->nadiaUser)->get(route('siswa.lomba-saya.show', $registration))->assertOk()->getContent();
    $card = fs1qa6List(fs1qa6Plain((string) $page), 'id="tim-judul"');

    return [fs1qa6Order($panel, $names), fs1qa6Order($card, $names), $panel];
}

/** A chip whose whole text is $text (an icon may come first). */
function fs1qa6Chip(string $text): string
{
    return '/<span class="chip[^"]*">\s*(?:<svg[^>]*>(?:(?!<\/svg>).)*<\/svg>\s*)?'.preg_quote($text, '/').'\s*<\/span>/s';
}

it('lists the ketua first while the own lomba waits and the members have no invitation yet, as Lomba saya does', function (): void {
    $own = F::pendingCompetition($this->nadiaUser, F::usulanAttributes([
        'name' => 'Lomba Tim Usulan Fiktif', 'participation' => Participation::Tim, 'max_team_size' => 4,
    ]));
    $bunga = F::student('Qa Talenta Bunga Citra', 'XI-B');
    $dimas = F::student('Qa Talenta Dimas Arya', 'XI-C');
    $registration = app(CreateRegistration::class)
        ->handle($this->nadiaUser, $own, RegistrationType::Tim, [$bunga->getKey(), $dimas->getKey()], $this->guru->getKey());

    expect($registration->status)->toBe(RegistrationStatus::MenungguLomba)
        ->and($registration->members()->where('role', 'anggota')->whereNull('invited_at')->count())->toBe(2);

    $names = ['Nadia Putri Anindya', 'Qa Talenta Bunga Citra', 'Qa Talenta Dimas Arya'];
    [$panel, $card, $html] = fs1qa6Orders($this, $registration, $names);

    expect($panel[0])->toBe('Nadia Putri Anindya')
        ->and($panel)->toBe($names)
        ->and($panel)->toBe($card);

    // The chips use the words of the Lomba saya team card: the ketua confirmed, the members are invited later.
    expect($html)->toMatch(fs1qa6Chip('Sudah konfirmasi'))
        ->toMatch(fs1qa6Chip('Diundang setelah lomba diverifikasi'))
        ->not->toMatch(fs1qa6Chip('Konfirmasi'));
});

it('lists the ketua first and then the members by invitation time once the lomba is published, as Lomba saya does', function (): void {
    $lomba = F::teamCompetition(4, ['name' => 'Lomba Tim Terbuka Fiktif']);
    $zaki = F::student('Qa Talenta Zaki Pratama', 'XI-B');
    $ayu = F::student('Qa Talenta Ayu Lestari', 'XI-C');
    $registration = app(CreateRegistration::class)
        ->handle($this->nadiaUser, $lomba, RegistrationType::Tim, [$zaki->getKey(), $ayu->getKey()], $this->guru->getKey());

    expect($registration->status)->toBe(RegistrationStatus::MenungguKonfirmasi);

    // Zaki was invited before Ayu, and the ketua's own row is the newest: the role decides first, then the time.
    $registration->members()->where('student_id', $zaki->getKey())->update(['invited_at' => now()->subHours(2)]);
    $registration->members()->where('student_id', $ayu->getKey())->update(['invited_at' => now()->subHour()]);
    $registration->members()->where('student_id', $this->nadia->getKey())->update(['invited_at' => now()->addMinute()]);

    $names = ['Nadia Putri Anindya', 'Qa Talenta Zaki Pratama', 'Qa Talenta Ayu Lestari'];
    [$panel, $card, $html] = fs1qa6Orders($this, $registration, $names);

    expect($panel)->toBe($names)
        ->and($panel)->toBe($card);

    // Same label set as the Lomba saya team card: "Sudah konfirmasi" and "Menunggu konfirmasi", never a bare
    // "Konfirmasi" (reads like a button) or "Menunggu".
    expect($html)->toMatch(fs1qa6Chip('Sudah konfirmasi'))
        ->toMatch(fs1qa6Chip('Menunggu konfirmasi'))
        ->not->toMatch(fs1qa6Chip('Konfirmasi'))
        ->not->toMatch(fs1qa6Chip('Menunggu'));
});

it('sorts members invited at the same moment by name in the panel', function (): void {
    $lomba = F::teamCompetition(4, ['name' => 'Lomba Tim Terbuka Fiktif']);
    $zaki = F::student('Qa Talenta Zaki Pratama', 'XI-B');
    $ayu = F::student('Qa Talenta Ayu Lestari', 'XI-C');
    $registration = app(CreateRegistration::class)
        ->handle($this->nadiaUser, $lomba, RegistrationType::Tim, [$zaki->getKey(), $ayu->getKey()], $this->guru->getKey());
    $registration->members()->where('role', 'anggota')->update(['invited_at' => now()->subHour()->startOfSecond()]);

    $form = Livewire::actingAs($this->nadiaUser)->test(Form::class, ['competitionId' => $lomba->getKey()]);
    $panel = fs1qa6List(fs1qa6Plain($form->html()), 'aria-labelledby="lbl-tim-kamu"');

    expect(fs1qa6Order($panel, ['Nadia Putri Anindya', 'Qa Talenta Zaki Pratama', 'Qa Talenta Ayu Lestari']))
        ->toBe(['Nadia Putri Anindya', 'Qa Talenta Ayu Lestari', 'Qa Talenta Zaki Pratama']);
});

it('words the Persetujuan lomba subtitle and the bulk dialog for the role that reads them (D-119)', function (): void {
    $kepsek = K::staff(RoleName::KepalaSekolah, 'Kepala Sekolah Fiktif');
    $tu = K::staff(RoleName::TataUsaha, 'Sri Handayani, S.E.');

    // The Kepala Sekolah reads who acts next: Tata Usaha.
    $this->actingAs($kepsek)->get(route('kepsek.persetujuan.index'))->assertOk()
        ->assertSee('Setujui pendaftaran agar Tata Usaha bisa membuat surat tugas.')
        ->assertSee('Tata Usaha langsung bisa membuat surat tugasnya.');

    // Tata Usaha is the reader: no sentence names it in the third person, and none addresses it with a pronoun.
    $page = $this->actingAs($tu)->get(route('tu.persetujuan.index'))->assertOk()
        ->assertSee('Setujui pendaftaran, lalu buat surat tugasnya.')
        ->assertSee('Surat tugasnya langsung bisa dibuat.')
        ->assertDontSee('agar Tata Usaha bisa membuat')
        ->assertDontSee('Tata Usaha langsung bisa');

    expect(strip_tags((string) $page->getContent()))->not->toMatch('/\b(?:kamu|Anda)\b/');
});
