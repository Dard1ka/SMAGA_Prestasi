<?php

declare(strict_types=1);

use App\Actions\Registrations\CreateRegistration;
use App\Enums\CompetitionStatus;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Livewire\Admin\AuditLog;
use App\Livewire\Sekolah\Dashboard;
use App\Livewire\Siswa\Lomba\Detail;
use App\Livewire\Siswa\Lomba\Katalog;
use App\Livewire\Siswa\Pendaftaran\Form;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| FS1 final polish: sentences that still named one role where D-139 / D-145 made it two (the empty chart of the school
| dashboard, the "Diverifikasi …" chip of Detail lomba), the Cari lomba subtitle per tab, one menu name for the approval
| screen (D-119), an audit label that is true for rows written before and after FS1, and the team of a registration that
| waits for its own lomba in the Pendaftaran lomba panel. Fictional data only.
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

/** The rendered panel without Livewire's block markers, so a tag and its text can be matched together. */
function fs1qa5Plain(string $html): string
{
    return (string) preg_replace('/<!--\[if (?:END)?BLOCK\]><!\[endif\]-->/', '', $html);
}

/** The first <ul> whose opening tag carries $attribute, with everything inside it. */
function fs1qa5List(string $html, string $attribute): string
{
    $start = strpos($html, $attribute);

    if ($start === false) {
        return '';
    }

    $from = (int) strrpos(substr($html, 0, $start), '<ul');
    preg_match('/<ul[^>]*>.*?<\/ul>/s', substr($html, $from), $list);

    return $list[0] ?? '';
}

it('says the school verifies the reports in the empty chart of the school dashboard (D-139)', function (): void {
    Livewire::actingAs(K::staff(RoleName::KepalaSekolah))->test(Dashboard::class)
        ->assertSee('Belum ada prestasi terverifikasi')
        ->assertSee('Grafik muncul setelah sekolah memverifikasi laporan hasil.')
        ->assertDontSee('setelah Kesiswaan memverifikasi laporan hasil');
});

it('names the role that verified the lomba on the chip of Detail lomba', function (string $role, string $label): void {
    $verifier = F::staff(RoleName::from($role), 'Verifikator Fiktif');
    $lomba = F::openCompetition(['verified_by' => $verifier->getKey()]);

    $page = Livewire::actingAs($this->nadiaUser)->test(Detail::class, ['competition' => $lomba]);

    expect(fs1qa5Plain($page->html()))->toMatch('/<span class="chip[^"]*">\s*(?:<svg.*?<\/svg>\s*)?Diverifikasi '.$label.'\s*<\/span>/s');

    if ($label !== 'Kesiswaan') {
        $page->assertDontSee('Diverifikasi Kesiswaan');
    }
})->with([
    'Kesiswaan' => ['kesiswaan', 'Kesiswaan'],
    'Admin' => ['admin', 'Admin'],
]);

it('falls back to Kesiswaan on the chip when the verifier is unknown', function (): void {
    $lomba = F::openCompetition();
    $lomba->forceFill(['verified_by' => null])->save();

    Livewire::actingAs($this->nadiaUser)->test(Detail::class, ['competition' => $lomba])
        ->assertSee('Diverifikasi Kesiswaan');
});

it('words the Cari lomba subtitle for the tab that is open', function (): void {
    F::pendingCompetition($this->nadiaUser, ['name' => 'Usulan Menunggu Fiktif']);

    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba.index'))->assertOk()
        ->assertSeeHtml('data-sth-subtitle')
        ->assertSee('Lomba di katalog sudah diperiksa Kesiswaan.')
        ->assertDontSee('Usulan lombamu dan statusnya.');

    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba.index', ['tab' => 'usulan']))->assertOk()
        ->assertSee('Usulan lombamu dan statusnya.')
        ->assertDontSee('Lomba di katalog sudah diperiksa Kesiswaan.');
});

it('tells the top bar the subtitle of the tab picked on the page', function (): void {
    Livewire::actingAs($this->nadiaUser)->test(Katalog::class)
        ->call('pilihTab', 'usulan')
        ->assertDispatched('sth-subtitle', text: 'Usulan lombamu dan statusnya.')
        ->call('pilihTab', '')
        ->assertDispatched('sth-subtitle', text: 'Lomba di katalog sudah diperiksa Kesiswaan.');

    Livewire::actingAs($this->nadiaUser)->test(Katalog::class)
        ->set('tab', 'usulan')
        ->assertDispatched('sth-subtitle', text: 'Usulan lombamu dan statusnya.');
});

it('names the approval screen "Persetujuan lomba" in both approver menus and as the page title (D-119)', function (): void {
    foreach (['kepala_sekolah' => 'kepsek.persetujuan.index', 'tata_usaha' => 'tu.persetujuan.index'] as $role => $route) {
        $items = collect(Navigation::for(['name' => 'X', 'roles' => [$role]], $role, [], 'none')['sections'])
            ->flatMap(fn (array $section): array => $section['items'])
            ->keyBy('key');

        expect($items['persetujuan']['label'])->toBe('Persetujuan lomba')
            ->and($items['persetujuan']['route'])->toBe($route);
    }

    $this->actingAs(K::staff(RoleName::KepalaSekolah, 'Kepala Sekolah Fiktif'))->get(route('kepsek.persetujuan.index'))->assertOk()
        ->assertSeeHtml('<title>Persetujuan lomba · SMAGA Talent Hub</title>');
});

it('labels a talent data change in the audit log with words true for rows before and after FS1', function (): void {
    $admin = F::staff(RoleName::Admin, 'Operator STH');
    $kesiswaan = K::staff(RoleName::Kesiswaan);
    Activity::query()->delete(); // test DB only: drop the model events of the fixtures above

    // Before FS1 the same event also recorded a changed assessment date; since D-142 the guru pembina and the note.
    activity('talent')->causedBy($kesiswaan)->event('talent.assessment_updated')
        ->withProperties(['assessment_on' => '2026-08-01'])->log('talent.assessment_updated');
    activity('talent')->causedBy($kesiswaan)->event('talent.assessment_updated')
        ->withProperties(['mentor_id' => $this->guru->getKey()])->log('talent.assessment_updated');

    expect(AuditLog::EVENT_LABELS['talent.assessment_updated'])->toBe('Data talenta diubah');

    $this->actingAs($admin)->get(route('admin.audit.index'))->assertOk()
        ->assertSee('Data talenta diubah')
        ->assertDontSee('Guru pembina atau catatan talenta diubah');
});

it('lists the team of a registration that waits for its own lomba in the panel, as Lomba saya does (OBS-STEP2-TEAM)', function (): void {
    $own = F::pendingCompetition($this->nadiaUser, F::usulanAttributes([
        'name' => 'Lomba Tim Usulan Fiktif', 'participation' => Participation::Tim, 'max_team_size' => 3,
    ]));
    $cahaya = F::student('Qa Talenta Cahaya Lestari Kusumaningrum', 'XI-2');
    $registration = app(CreateRegistration::class)
        ->handle($this->nadiaUser, $own, RegistrationType::Tim, [$cahaya->getKey()], $this->guru->getKey());

    expect($registration->status)->toBe(RegistrationStatus::MenungguLomba);

    $form = Livewire::actingAs($this->nadiaUser)->test(Form::class, ['competitionId' => $own->getKey()])
        ->assertDontSee('Undang anggota pengganti');

    $team = fs1qa5List(fs1qa5Plain($form->html()), 'aria-labelledby="lbl-tim-kamu"');

    // The ketua and the member nobody invited yet; the member's chip says when the invitation goes out, with no ×.
    expect($team)->toContain('Nadia Putri Anindya')
        ->toContain('Qa Talenta Cahaya Lestari Kusumaningrum')
        ->toMatch('/<span class="chip[^"]*">\s*(?:<svg.*?<\/svg>\s*)?Diundang setelah lomba diverifikasi\s*<\/span>/s')
        ->not->toContain('Batalkan undangan')
        ->not->toContain('ellip')
        // The wrapping name of 76e7553: the name in its own shrinkable, wrapping span.
        ->toMatch('/<span class="min-w-0 [^"]*\[overflow-wrap:anywhere\][^"]*">\s*Qa Talenta Cahaya Lestari Kusumaningrum\s*<\/span>/');

    // Detail lomba draws the same panel.
    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba.show', $own))->assertOk()
        ->assertSee('Qa Talenta Cahaya Lestari Kusumaningrum')
        ->assertSee('Diundang setelah lomba diverifikasi');

    // Once the lomba is published the invitation is out: the usual row with the pending chip and the × comes back.
    $registration->forceFill(['status' => RegistrationStatus::MenungguKonfirmasi])->save();
    $registration->members()->whereNull('invited_at')->update(['invited_at' => now()]);
    $own->forceFill(['status' => CompetitionStatus::Dipublikasikan])->save();

    Livewire::actingAs($this->nadiaUser)->test(Form::class, ['competitionId' => $own->getKey()])
        ->assertDontSee('Diundang setelah lomba diverifikasi')
        ->assertSee('Batalkan undangan Qa Talenta Cahaya Lestari Kusumaningrum');
});
