<?php

declare(strict_types=1);

use App\Enums\PublicationStatus;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Livewire\Admin\ArsipOtomatis;
use App\Livewire\Admin\AuditLog;
use App\Livewire\Guru\Binaan;
use App\Livewire\Guru\Lomba as GuruLomba;
use App\Livewire\Humas\Arsip;
use App\Livewire\Kepsek\SuratSaya;
use App\Livewire\Kesiswaan\DatabasePrestasi;
use App\Livewire\Kesiswaan\Lomba\Index as KelolaLomba;
use App\Livewire\Kesiswaan\Talenta;
use App\Livewire\Siswa\Lomba\Katalog;
use App\Livewire\TataUsaha\ArsipSurat;
use App\Models\Competition;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Feature\Admin\AdminTestHelpers;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Guru\GuruFixtures;
use Tests\Feature\Humas\HumasFixtures;
use Tests\Feature\Kepsek\KepsekFixtures;
use Tests\Feature\Kesiswaan\KesiswaanFixtures;
use Tests\Feature\Kesiswaan\Prestasi\PrestasiFixtures;
use Tests\Feature\Siswa\Lomba\LombaFixtures;

/*
| SV1 browser check (backlog #81): a search box Livewire binds live keeps the text as typed. The update hooks of these
| screens wrote the TRIMMED value back into the property, Livewire put that value into the box while the user was still
| typing, and "Nadia " + a pause + "Putri" became "NadiaPutri", which found nothing. Now the property stays as typed
| (only a text longer than the search reads is cut, never trimmed) and the term is trimmed where the query, the links,
| the export and its audit row are built, as Humas › Apresiasi "Cari prestasi" does since EM2. Fictional data only
| (DESIGN-SPEC-v2 §5).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    KesiswaanFixtures::boot();
    Notification::fake();
    $this->withoutVite();
});

/** @return list<string> the values of $key in the rows a screen hands its view, sorted (the order is not the point here) */
function sv1SearchColumn(iterable $rows, string $key): array
{
    return collect($rows)->pluck($key)->map(fn ($value): string => (string) $value)->sort()->values()->all();
}

/** @return list<Letter> one student letter each for two lomba of the set whose names share the first word "Lomba" */
function sv1SearchLetters(): array
{
    return array_map(function (string $name): Letter {
        $registration = Registration::factory()->status(RegistrationStatus::SuratTerbit)->create([
            'competition_id' => Competition::factory()->published()->create(['name' => $name])->getKey(),
        ]);

        return Letter::factory()->create(['registration_id' => $registration->getKey()])->fresh();
    }, ['Lomba Debat Bahasa Indonesia (LDBI) Kota 2026', 'Lomba Peneliti Belia (LPB) 2026']);
}

/** @return list<array<int, mixed>> the rows of the spreadsheet a Livewire download handed out */
function sv1SearchDownloadedRows(Testable $component): array
{
    $path = sys_get_temp_dir().'/sv1-search-'.uniqid().'.xlsx';
    file_put_contents($path, base64_decode((string) $component->effects['download']['content']));

    try {
        return array_values(SimpleExcelReader::create($path)->noHeaderRow()->getRows()->all());
    } finally {
        @unlink($path);
    }
}

it('cuts only a text longer than the search reads and never trims it', function (string $component, Closure $actor): void {
    $typed = '  '.str_repeat('a', 120);

    Livewire::actingAs($actor())->test($component)
        ->set('q', $typed)
        ->assertSet('q', mb_substr($typed, 0, $component::SEARCH_MAX));
})->with([
    'Kesiswaan › Kelola lomba' => [KelolaLomba::class, fn () => KesiswaanFixtures::staff(RoleName::Kesiswaan)],
    'Kesiswaan › Database talenta siswa' => [Talenta::class, fn () => KesiswaanFixtures::staff(RoleName::Kesiswaan)],
    'Kesiswaan › Database prestasi' => [DatabasePrestasi::class, fn () => KesiswaanFixtures::staff(RoleName::Kesiswaan)],
    'Guru › Siswa binaan' => [Binaan::class, fn () => GuruFixtures::guru()],
    'Guru › Lomba' => [GuruLomba::class, fn () => GuruFixtures::guru()],
    'Admin › Arsip otomatis' => [ArsipOtomatis::class, fn () => AdminTestHelpers::admin()],
    'Admin › Log audit' => [AuditLog::class, fn () => AdminTestHelpers::admin()],
    'Humas › Arsip publikasi' => [Arsip::class, fn () => HumasFixtures::humas()],
    'Tata Usaha › Arsip surat' => [ArsipSurat::class, fn () => AuthTestHelpers::staff(RoleName::TataUsaha)],
    'Kepala Sekolah › Surat atas nama saya' => [SuratSaya::class, fn () => KepsekFixtures::kepsek()],
    'Siswa › Cari lomba' => [Katalog::class, fn () => KesiswaanFixtures::student('Nadia Putri Anindya', 'XI-D')->user],
]);

it('keeps the typed text on Kelola lomba and finds a lomba by two words', function (): void {
    KesiswaanFixtures::competition(['name' => 'Lomba Debat Bahasa Indonesia (LDBI) Kota 2026', 'organizer_name' => 'Dinas Pendidikan Kota Semarang']);
    KesiswaanFixtures::competition(['name' => 'Lomba Peneliti Belia (LPB) 2026', 'organizer_name' => 'Center for Young Scientists']);

    Livewire::actingAs(KesiswaanFixtures::staff(RoleName::Kesiswaan))->test(KelolaLomba::class)
        ->set('q', 'Lomba ')
        ->assertSet('q', 'Lomba ')
        ->assertViewHas('rows', fn (Collection $rows): bool => sv1SearchColumn($rows, 'name') === ['Lomba Debat Bahasa Indonesia (LDBI) Kota 2026', 'Lomba Peneliti Belia (LPB) 2026'])
        ->set('q', '  Lomba Debat ')
        ->assertSet('q', '  Lomba Debat ')
        ->assertViewHas('rows', fn (Collection $rows): bool => sv1SearchColumn($rows, 'name') === ['Lomba Debat Bahasa Indonesia (LDBI) Kota 2026'])
        // Spaces only is no search: the whole catalog.
        ->set('q', '   ')
        ->assertSet('q', '   ')
        ->assertViewHas('rows', fn (Collection $rows): bool => $rows->count() === 2);
});

it('keeps the typed text on Database talenta siswa and puts the trimmed term in the export, its filter row and its audit row', function (): void {
    KesiswaanFixtures::application(KesiswaanFixtures::student('Nadia Putri Anindya', 'X-A'));
    KesiswaanFixtures::application(KesiswaanFixtures::student('Dimas Arya Nugroho', 'X-B'), branch: 'Kimia');
    $kesiswaan = KesiswaanFixtures::staff(RoleName::Kesiswaan);

    Livewire::actingAs($kesiswaan)->test(Talenta::class)
        ->set('q', 'Nadia ')
        ->assertSet('q', 'Nadia ')
        ->assertViewHas('rows', fn (Collection $rows): bool => sv1SearchColumn($rows, 'name') === ['Nadia Putri Anindya'])
        ->set('q', '  Nadia Putri ')
        ->assertSet('q', '  Nadia Putri ')
        ->assertViewHas('rows', fn (Collection $rows): bool => sv1SearchColumn($rows, 'name') === ['Nadia Putri Anindya'])
        ->assertViewHas('shownText', '1 hasil sesuai filter')
        // Without a recent password the download goes to the password page and comes back to the trimmed search.
        ->call('export')
        ->assertRedirect(route('password.confirm'));

    expect(session('url.intended'))->toBe(route('kesiswaan.talenta.index', ['q' => 'Nadia Putri']));

    session()->put('auth.password_confirmed_at', time());
    $screen = Livewire::actingAs($kesiswaan)->test(Talenta::class)
        ->set('q', '  Nadia Putri ')
        ->call('export')
        ->assertFileDownloaded();

    $rows = sv1SearchDownloadedRows($screen);
    $log = Activity::query()->where('event', 'talent.exported')->sole();

    expect(collect($rows)->flatten()->all())->toContain('Filter: Siswa: Nadia Putri', 'Nadia Putri Anindya')
        ->and(collect($rows)->flatten()->all())->not->toContain('Dimas Arya Nugroho')
        ->and($log->properties['filters'])->toBe(['q' => 'Nadia Putri'])
        ->and($log->properties['rows'])->toBe(1);
});

it('keeps the typed text on Database prestasi, still cleans the other filters and exports the trimmed term', function (): void {
    $f = new PrestasiFixtures;
    $f->prestasi([KesiswaanFixtures::student('Nadia Putri Anindya', 'XI-D')], $f->lomba('WICE 2026'));
    $f->prestasi([KesiswaanFixtures::student('Dimas Arya Nugroho', 'XI-D')], $f->lomba('OPSI 2026'));
    $kesiswaan = KesiswaanFixtures::staff(RoleName::Kesiswaan);

    Livewire::actingAs($kesiswaan)->test(DatabasePrestasi::class)
        ->set('q', 'Nadia ')
        ->assertSet('q', 'Nadia ')
        ->assertViewHas('rows', fn (array $rows): bool => sv1SearchColumn($rows, 'name') === ['Nadia Putri Anindya'])
        ->set('q', '  Nadia Putri ')
        ->assertSet('q', '  Nadia Putri ')
        ->assertViewHas('rows', fn (array $rows): bool => sv1SearchColumn($rows, 'name') === ['Nadia Putri Anindya'])
        ->assertViewHas('active', fn (array $active): bool => $active === ['Siswa: Nadia Putri'])
        // The same normalize() still drops a value the filter object ignores, and leaves the search as typed.
        ->set('bidang', 'sulap')
        ->assertSet('bidang', '')
        ->assertSet('q', '  Nadia Putri ')
        ->call('export')
        ->assertRedirect(route('password.confirm'));

    expect(session('url.intended'))->toBe(route('kesiswaan.prestasi.index', ['q' => 'Nadia Putri']));

    session()->put('auth.password_confirmed_at', time());
    Livewire::actingAs($kesiswaan)->test(DatabasePrestasi::class)
        ->set('q', '  Nadia Putri ')
        ->call('export')
        ->assertFileDownloaded();

    $log = Activity::query()->where('event', 'achievement.exported')->sole();

    expect($log->properties['filters'])->toBe(['q' => 'Nadia Putri'])
        ->and($log->properties['rows'])->toBe(1);
});

it('keeps the typed text on Guru › Siswa binaan', function (): void {
    $guru = GuruFixtures::guru();
    GuruFixtures::registration($guru, GuruFixtures::student('Nadia Putri Anindya'), competition: KesiswaanFixtures::competition(['name' => 'WICE 2026']));
    GuruFixtures::registration($guru, GuruFixtures::student('Dimas Arya Nugroho'), competition: KesiswaanFixtures::competition(['name' => 'NSIF 2026']));

    Livewire::actingAs($guru)->test(Binaan::class)
        ->set('q', 'Nadia ')
        ->assertSet('q', 'Nadia ')
        ->assertViewHas('registrations', fn (array $cards): bool => sv1SearchColumn($cards, 'title') === ['WICE 2026'])
        ->set('q', '  Nadia Putri ')
        ->assertSet('q', '  Nadia Putri ')
        ->assertViewHas('registrations', fn (array $cards): bool => sv1SearchColumn($cards, 'title') === ['WICE 2026']);
});

it('keeps the typed text on Guru › Lomba and carries the trimmed term in its links', function (): void {
    $ldbi = KesiswaanFixtures::competition(['name' => 'Lomba Debat Bahasa Indonesia (LDBI) Kota 2026', 'organizer_name' => 'Dinas Pendidikan Kota Semarang']);
    KesiswaanFixtures::competition(['name' => 'Lomba Peneliti Belia (LPB) 2026', 'organizer_name' => 'Center for Young Scientists']);

    Livewire::actingAs(GuruFixtures::guru())->test(GuruLomba::class)
        ->set('q', 'Lomba ')
        ->assertSet('q', 'Lomba ')
        ->assertViewHas('cards', fn (array $cards): bool => count($cards) === 2)
        ->set('q', '  Lomba Debat ')
        ->assertSet('q', '  Lomba Debat ')
        ->assertViewHas('cards', fn (array $cards): bool => sv1SearchColumn($cards, 'title') === [$ldbi->name]
            && $cards[0]['href'] === route('guru.lomba.index', ['q' => 'Lomba Debat', 'lihat' => $ldbi->getKey()]))
        ->assertViewHas('closeUrl', route('guru.lomba.index', ['q' => 'Lomba Debat']));
});

it('keeps the typed text on Admin › Arsip otomatis and carries the trimmed term in the tab links', function (): void {
    foreach (['Nadia Putri Anindya', 'Dimas Arya Nugroho'] as $name) {
        KesiswaanFixtures::student($name, 'XII-B')->forceFill(['archive_at' => now()->addDays(10)->toDateString()])->save();
    }

    Livewire::actingAs(AdminTestHelpers::admin())->test(ArsipOtomatis::class)
        ->set('q', 'Nadia ')
        ->assertSet('q', 'Nadia ')
        ->set('q', '  Nadia Putri ')
        ->assertSet('q', '  Nadia Putri ')
        ->assertViewHas('due', fn ($due): bool => sv1SearchColumn($due->getCollection()->map(fn (Student $s): array => ['name' => $s->user?->name]), 'name') === ['Nadia Putri Anindya'])
        ->assertViewHas('tabs', fn (array $tabs): bool => $tabs[0]['href'] === route('admin.arsip.index', ['q' => 'Nadia Putri'])
            && $tabs[1]['href'] === route('admin.arsip.index', ['tab' => 'arsip', 'q' => 'Nadia Putri']));
});

it('keeps the typed text on Admin › Log audit and filters, exports and audits the trimmed term', function (): void {
    $admin = AdminTestHelpers::admin();
    $tu = AdminTestHelpers::staff(RoleName::TataUsaha, ['name' => 'Ibu Sri Handayani', 'username' => 'sri.handayani']);
    $kesiswaan = AdminTestHelpers::staff(RoleName::Kesiswaan, ['name' => 'Ibu Dwi Rahayu', 'username' => 'dwi.rahayu']);
    Activity::query()->delete(); // test DB only: drop the model events of the fixtures above
    activity('letter')->causedBy($tu)->event('letters.issued')->log('letters.issued');
    activity('talent')->causedBy($kesiswaan)->event('talent.exported')->log('talent.exported');

    Livewire::actingAs($admin)->test(AuditLog::class)
        ->set('q', 'Ibu ')
        ->assertSet('q', 'Ibu ')
        ->assertViewHas('rows', fn (Collection $rows): bool => sv1SearchColumn($rows, 'event') === ['letters.issued', 'talent.exported'])
        ->set('q', '  Sri Handayani ')
        ->assertSet('q', '  Sri Handayani ')
        ->assertViewHas('rows', fn (Collection $rows): bool => sv1SearchColumn($rows, 'event') === ['letters.issued'])
        ->assertViewHas('filtered', true)
        ->call('export')
        ->assertRedirect(route('password.confirm'));

    expect(session('url.intended'))->toBe(route('admin.audit.index', ['q' => 'Sri Handayani']));

    session()->put('auth.password_confirmed_at', time());
    Livewire::actingAs($admin)->test(AuditLog::class)
        ->set('q', '  Sri Handayani ')
        ->call('export')
        ->assertFileDownloaded();

    expect(Activity::query()->where('event', 'audit.exported')->sole()->properties['filters'])->toBe(['q' => 'Sri Handayani']);

    // Spaces only is no filter at all.
    Livewire::actingAs($admin)->test(AuditLog::class)
        ->set('q', '   ')
        ->assertSet('q', '   ')
        ->assertViewHas('filtered', false);
});

it('keeps the typed text on Humas › Arsip publikasi', function (): void {
    HumasFixtures::publication(publicationState: ['status' => PublicationStatus::Dipublikasikan, 'published_at' => now()->subDay()], studentName: 'Nadia Putri Anindya', competitionName: 'OPSI 2026');
    HumasFixtures::publication(publicationState: ['status' => PublicationStatus::Dipublikasikan, 'published_at' => now()->subDays(2)], studentName: 'Dimas Arya Nugroho', competitionName: 'FIKSI 2026');

    Livewire::actingAs(HumasFixtures::humas())->test(Arsip::class)
        ->set('q', 'Nadia ')
        ->assertSet('q', 'Nadia ')
        ->assertViewHas('rows', fn (array $rows): bool => sv1SearchColumn($rows, 'competition') === ['OPSI 2026'])
        ->set('q', '  Nadia Putri ')
        ->assertSet('q', '  Nadia Putri ')
        ->assertViewHas('rows', fn (array $rows): bool => sv1SearchColumn($rows, 'competition') === ['OPSI 2026']);
});

it('keeps the typed text on Tata Usaha › Arsip surat', function (): void {
    [$ldbi] = sv1SearchLetters();

    Livewire::actingAs(AuthTestHelpers::staff(RoleName::TataUsaha))->test(ArsipSurat::class)
        ->set('q', 'Lomba ')
        ->assertSet('q', 'Lomba ')
        ->assertViewHas('letters', fn ($letters): bool => $letters->total() === 2)
        ->set('q', '  Lomba Debat ')
        ->assertSet('q', '  Lomba Debat ')
        ->assertViewHas('letters', fn ($letters): bool => collect($letters->items())->pluck('id')->all() === [$ldbi->getKey()]);
});

it('keeps the typed text on Kepala Sekolah › Surat atas nama saya', function (): void {
    [$ldbi] = sv1SearchLetters();

    Livewire::actingAs(KepsekFixtures::kepsek())->test(SuratSaya::class)
        ->set('q', 'Lomba ')
        ->assertSet('q', 'Lomba ')
        ->assertViewHas('letters', fn ($letters): bool => $letters->total() === 2)
        ->set('q', '  Lomba Debat ')
        ->assertSet('q', '  Lomba Debat ')
        ->assertViewHas('letters', fn ($letters): bool => collect($letters->items())->pluck('id')->all() === [$ldbi->getKey()]);
});

/*
| Siswa › Cari lomba (second browser check, 2026-10-31): the in-page box below lg (#f-cari, on "Katalog" and on "Usulan
| saya") lost the space typed before a pause, because Katalog::updated() wrote the trimmed value back into q.
*/

it('keeps the typed text on Siswa › Cari lomba and finds a lomba by two words', function (string $tab, string $list, string $key, string $noMatch): void {
    $nadia = KesiswaanFixtures::student('Nadia Putri Anindya', 'XI-D')->user;
    $names = ['Uji Coba Robotik Fiktif 2026', 'Uji Ulang Sains Fiktif 2026'];

    // "Katalog" lists published lomba, "Usulan saya" the student's own of every status (here still waiting for Kesiswaan).
    foreach ($names as $name) {
        if ($tab === 'usulan') {
            LombaFixtures::pendingCompetition($nadia, ['name' => $name]);
        } else {
            KesiswaanFixtures::competition(['name' => $name]);
        }
    }

    Livewire::actingAs($nadia)->test(Katalog::class)
        ->set('tab', $tab)
        ->set('q', 'Uji ')
        ->assertSet('q', 'Uji ')
        ->assertViewHas($list, fn (array $items): bool => sv1SearchColumn($items, $key) === $names)
        ->set('q', 'Uji Ulang')
        ->assertSet('q', 'Uji Ulang')
        ->assertViewHas($list, fn (array $items): bool => sv1SearchColumn($items, $key) === ['Uji Ulang Sains Fiktif 2026'])
        ->set('q', '  Uji Ulang ')
        ->assertSet('q', '  Uji Ulang ')
        ->assertViewHas($list, fn (array $items): bool => sv1SearchColumn($items, $key) === ['Uji Ulang Sains Fiktif 2026'])
        ->assertViewHas('searching', true)
        ->assertViewHas('filtered', true)
        // Spaces only is no search: every lomba of the tab, nothing "filtered", no "no match" sentence.
        ->set('q', '   ')
        ->assertSet('q', '   ')
        ->assertViewHas($list, fn (array $items): bool => sv1SearchColumn($items, $key) === $names)
        ->assertViewHas('searching', false)
        ->assertViewHas('filtered', false)
        ->assertDontSee($noMatch)
        // A term that finds nothing gets the tab's "no match" state.
        ->set('q', 'Uji Renang ')
        ->assertSet('q', 'Uji Renang ')
        ->assertViewHas($list, [])
        ->assertSee($noMatch)
        // Only a text longer than the search reads is cut, never trimmed, on either tab.
        ->set('q', '  '.str_repeat('a', 120))
        ->assertSet('q', mb_substr('  '.str_repeat('a', 120), 0, Katalog::SEARCH_MAX));
})->with([
    'Katalog' => ['', 'cards', 'title', 'Belum ada lomba yang cocok'],
    'Usulan saya' => ['usulan', 'rows', 'name', 'Belum ada usulan yang cocok'],
]);

it('keeps the spaces of a ?q= from the top bar on Siswa › Cari lomba and still finds the lomba', function (): void {
    KesiswaanFixtures::competition(['name' => 'Uji Coba Robotik Fiktif 2026']);
    KesiswaanFixtures::competition(['name' => 'Uji Ulang Sains Fiktif 2026']);

    Livewire::withQueryParams(['q' => ' Uji Ulang '])
        ->actingAs(KesiswaanFixtures::student('Nadia Putri Anindya', 'XI-D')->user)
        ->test(Katalog::class)
        ->assertSet('q', ' Uji Ulang ')
        ->assertViewHas('cards', fn (array $cards): bool => sv1SearchColumn($cards, 'title') === ['Uji Ulang Sains Fiktif 2026']);
});

it('keeps the Usulan saya box while it holds spaces only, without a "no match" sentence', function (): void {
    // No own lomba: the box shows only because something is typed (an old ?q=); spaces only must not hide it under the
    // student's typing, and they are no search either, so the tab keeps its own empty state.
    Livewire::actingAs(KesiswaanFixtures::student('Nadia Putri Anindya', 'XI-D')->user)->test(Katalog::class)
        ->set('tab', 'usulan')
        ->set('q', '  ')
        ->assertSeeHtml('placeholder="Cari usulan"')
        ->assertSee('Belum ada usulan lomba')
        ->assertDontSee('Belum ada usulan yang cocok')
        ->set('q', '')
        ->assertDontSeeHtml('placeholder="Cari usulan"');
});
