<?php

declare(strict_types=1);

use App\Actions\Competitions\CompetitionChange;
use App\Actions\Registrations\CreateRegistration;
use App\Domain\Competitions\OrganizerWeight;
use App\Enums\OrganizerType;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Enums\WeightCategory;
use App\Livewire\Guru\Lomba as GuruLomba;
use App\Livewire\Kesiswaan\Lomba\Form as KelolaLombaForm;
use App\Livewire\Siswa\KonfirmasiTim;
use App\Livewire\Siswa\Lomba\Detail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| FS1 QA round 3 (tracks QA1 and QA3): the Kategori bobot legend of the Kesiswaan drawer is the one list of
| WeightCategory::hint() (as in Pengaturan › Bobot poin), the official link is "Link resmi" on every screen (Detail
| lomba, Guru › Lomba, the change notice), and a member row of Undangan tim keeps the whole name at 390 px.
| Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    K::boot();
    Notification::fake();
    $this->withoutVite();
    $this->kesiswaan = K::staff(RoleName::Kesiswaan);
    $this->guru = F::staff(RoleName::Guru);
});

/** The rendered page without Livewire's block markers, so a tag and its text can be matched together. */
function fs1qa3Plain(string $html): string
{
    return (string) preg_replace('/<!--\[if (?:END)?BLOCK\]><!\[endif\]-->/', '', $html);
}

it('draws the Kategori bobot legend of the drawer from WeightCategory::hint() (BUG-LOMBA-18)', function (): void {
    $lomba = F::pendingCompetition(F::user(F::student()), F::usulanAttributes(['name' => 'Usulan Legenda Fiktif']));

    $legend = 'A Puspresnas · B Pemerintah pusat/daerah, BUMN/BUMD · C Federasi, perguruan tinggi, organisasi profesi'
        .' · D Swasta, yayasan, lembaga · E Lainnya/umum';

    expect(WeightCategory::legend())->toBe($legend);

    Livewire::actingAs($this->kesiswaan)->test(KelolaLombaForm::class)->call('open', $lomba->getKey())
        ->assertSee($legend)
        ->assertDontSee('C Federasi/PT')
        ->assertDontSee('D Swasta ·');
});

it('names every jenis penyelenggara in the legend of the category the map gives it (D-146)', function (OrganizerType $type, string $named): void {
    $category = OrganizerWeight::for($type, false)->category;

    expect(mb_strtolower($category->hint()))->toContain(mb_strtolower($named))
        ->and(WeightCategory::legend())->toContain($category->value.' '.$category->hint());
})->with([
    'pemerintah pusat' => [OrganizerType::PemerintahPusat, 'Pemerintah pusat'],
    'pemerintah daerah' => [OrganizerType::PemerintahDaerah, 'daerah'],
    'bumn/bumd' => [OrganizerType::BumnBumd, 'BUMN/BUMD'],
    'ptn' => [OrganizerType::Ptn, 'perguruan tinggi'],
    'pts' => [OrganizerType::Pts, 'perguruan tinggi'],
    'organisasi profesi' => [OrganizerType::OrganisasiProfesi, 'organisasi profesi'],
    'federasi olahraga' => [OrganizerType::FederasiOlahraga, 'Federasi'],
    'yayasan/lembaga' => [OrganizerType::YayasanLembaga, 'yayasan, lembaga'],
    'perusahaan swasta' => [OrganizerType::PerusahaanSwasta, 'Swasta'],
    'lainnya' => [OrganizerType::Lainnya, 'Lainnya'],
]);

it('names the official link "Link resmi" on Detail lomba, in Tentang and in Dokumen (BUG-LOMBA-19)', function (): void {
    $lomba = F::openCompetition(['description' => null, 'website_url' => 'https://example.org/nsif-2026']);

    Livewire::actingAs(F::user(F::student()))->test(Detail::class, ['competition' => $lomba])
        ->assertSee('Deskripsi belum ada. Lihat juknis atau link resmi di tab Dokumen.')
        ->assertSee('Link resmi')
        ->assertSee('example.org')
        ->assertDontSee('Website resmi')
        ->assertDontSee('website resmi');
});

it('names the official link "Link resmi" in the Guru › Lomba drawer (BUG-LOMBA-19)', function (): void {
    $lomba = F::openCompetition(['website_url' => 'https://example.org/nsif-2026']);

    Livewire::actingAs($this->guru)->withQueryParams(['lihat' => $lomba->getKey()])->test(GuruLomba::class)
        ->assertSee('Link resmi')
        ->assertSee('example.org')
        ->assertDontSee('Website resmi');
});

it('names the official link "Link resmi" in the lomba change notice (BUG-LOMBA-19)', function (): void {
    $lomba = F::openCompetition(['website_url' => 'https://example.org/nsif-2026']);

    expect(CompetitionChange::line('website_url', $lomba))->toBe('Link resmi diganti.');

    $lomba->forceFill(['website_url' => null])->save();

    expect(CompetitionChange::line('website_url', $lomba->fresh()))->toBe('Link resmi dihapus.');
});

it('keeps the whole member name in the Tim card of Undangan tim on a phone (BUG-N5)', function (): void {
    $hanum = F::student('Qa Talenta Hanum Safitri', 'XI-2');
    $irfan = F::student('Qa Talenta Irfan Hakim', 'XI-3');

    $registration = app(CreateRegistration::class)
        ->handle(F::user($hanum), F::teamCompetition(3), RegistrationType::Tim, [$irfan->getKey()], $this->guru->getKey());

    $html = fs1qa3Plain(Livewire::actingAs(F::user($irfan))->test(KonfirmasiTim::class, ['registration' => $registration])
        ->assertSee('Menunggu konfirmasi')
        ->html());

    preg_match('/<ul[^>]*>.*?<\/ul>/s', substr($html, (int) strpos($html, 'id="h-tim"')), $list);
    $team = $list[0] ?? '';

    // No one-line cut ("Qa…"): each name may wrap, and the "Kamu" chip and the status chip move to the next line.
    expect($team)->toContain('Qa Talenta Hanum Safitri')
        ->toContain('Qa Talenta Irfan Hakim')
        ->not->toContain('ellip')
        ->toMatch('/<span class="min-w-0 \[overflow-wrap:anywhere\]">\s*Qa Talenta Irfan Hakim\s*<\/span>/')
        ->toMatch('/<span class="min-w-0 \[overflow-wrap:anywhere\]">\s*Qa Talenta Hanum Safitri\s*<\/span>/')
        ->toMatch('/class="flex min-w-0 flex-1 flex-wrap[^"]*"/');
});
