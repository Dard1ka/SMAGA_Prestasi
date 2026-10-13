<?php

declare(strict_types=1);

use App\Actions\Talent\RejectTalentApplication;
use App\Actions\Talent\RestoreTalentApplication;
use App\Enums\FilePurpose;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\ReportFileKind;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Livewire\Guru\Binaan;
use App\Livewire\Guru\BinaanDetail;
use App\Livewire\Guru\Lomba;
use App\Models\Competition;
use App\Models\File;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Guru\GuruFixtures;
use Tests\Feature\Kesiswaan\KesiswaanFixtures;

/*
| B4 · backlog #3: guru area (guru.beranda, guru.binaan.show, guru.lomba.index). A guru sees ONLY their own
| binaan (registrations / talent applications with mentor_id = the guru); everything else → 404 / 403.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    GuruFixtures::boot();
    Notification::fake();

    $this->retno = GuruFixtures::guru('Retno Wulandari, S.Pd.');
    $this->hendra = GuruFixtures::guru('Hendra Kusuma, S.Pd., M.Si.');

    $this->nadia = GuruFixtures::student('Nadia Putri Anindya', 'XI-4');
    $this->dimas = GuruFixtures::student('Dimas Arya Nugroho', 'XI-4');
    $this->raka = GuruFixtures::student('Raka Pradipta', 'XII-2');

    $this->wice = KesiswaanFixtures::competition(['name' => 'WICE 2026']);
    $this->nsif = KesiswaanFixtures::competition(['name' => 'NSIF 2026']);

    $this->mine = GuruFixtures::registration($this->retno, $this->nadia, RegistrationStatus::MenungguLaporan, [$this->dimas], $this->wice);
    $this->other = GuruFixtures::registration($this->hendra, $this->raka, RegistrationStatus::SuratTerbit, [], $this->nsif);
});

// Access ------------------------------------------------------------------------------------------

it('sends a guru from /beranda to guru.beranda', function (): void {
    $this->actingAs($this->retno)->get('/beranda')->assertRedirect(route('guru.beranda'));
});

it('lets only gurus into the guru area', function (string $route): void {
    $url = $route === 'guru.binaan.show' ? route($route, $this->mine) : route($route);

    $this->get($url)->assertRedirect(route('login'));

    foreach ([RoleName::Kesiswaan, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas, RoleName::Admin] as $role) {
        $this->actingAs(KesiswaanFixtures::staff($role, 'Staf '.$role->value))->get($url)->assertForbidden();
    }

    $this->actingAs($this->nadia->user)->get($url)->assertForbidden();
    $this->actingAs($this->retno)->get($url)->assertOk();
})->with(['guru.beranda', 'guru.lomba.index', 'guru.binaan.show']);

it('answers 404 for a registration the guru does not mentor', function (): void {
    $this->actingAs($this->retno)->get(route('guru.binaan.show', $this->other))->assertNotFound();
    $this->actingAs($this->hendra)->get(route('guru.binaan.show', $this->mine))->assertNotFound();

    $unassigned = GuruFixtures::registration(null, GuruFixtures::student('Hana Safira', 'X-8'));
    $this->actingAs($this->retno)->get(route('guru.binaan.show', $unassigned))->assertNotFound();

    $this->actingAs($this->retno)->get('/guru/binaan/bukan-ulid')->assertNotFound();
    $this->actingAs($this->retno)->get('/guru/binaan/01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
});

it('keeps the mentor check on every render of the detail page', function (): void {
    $page = Livewire::actingAs($this->retno)->test(BinaanDetail::class, ['registration' => $this->mine])->assertOk();

    $this->mine->forceFill(['mentor_id' => $this->hendra->getKey()])->save();

    $page->call('$refresh')->assertNotFound();
});

it('refuses gurus that cannot log in and other roles at the policy', function (): void {
    expect($this->retno->can('viewBinaan', Registration::class))->toBeTrue()
        ->and($this->retno->can('viewAsMentor', $this->mine))->toBeTrue()
        ->and($this->retno->can('viewAsMentor', $this->other))->toBeFalse()
        ->and(KesiswaanFixtures::staff(RoleName::Kesiswaan, 'Ibu Dwi Rahayu')->can('viewAsMentor', $this->mine))->toBeFalse();

    $this->retno->forceFill(['status' => 'disabled'])->save();

    expect($this->retno->fresh()->can('viewAsMentor', $this->mine))->toBeFalse();
});

it('keeps gurus out of the Kesiswaan database prestasi', function (): void {
    $this->actingAs($this->retno)->get(route('kesiswaan.prestasi.index'))->assertForbidden();
});

// Siswa binaan ------------------------------------------------------------------------------------

it('lists only the own binaan with status and stats', function (): void {
    GuruFixtures::talent($this->retno, GuruFixtures::student('Aurelia Maharani', 'X-3'));
    GuruFixtures::talent($this->hendra, GuruFixtures::student('Fajar Ramadhan', 'XII-1'));

    Livewire::actingAs($this->retno)->test(Binaan::class)
        ->assertSee('WICE 2026')
        ->assertSee('Nadia Putri Anindya')
        ->assertSee('Dimas Arya Nugroho')
        ->assertSee('Menunggu laporan')
        ->assertDontSee('NSIF 2026')
        ->assertDontSee('Raka Pradipta')
        ->assertViewHas('counts', fn (array $c): bool => $c['siswa'] === 3 && $c['aktif'] === 1 && $c['laporan'] === 1 && $c['talenta'] === 1)
        ->call('setTab', 'talenta')
        ->assertSee('Aurelia Maharani')
        ->assertSee('Seleksi/asesmen')
        ->assertDontSee('Fajar Ramadhan');
});

it('shows the note for the guru pembina in the Talenta tab, never the note for the student, and a restored choice in its stage', function (): void {
    $kesiswaan = KesiswaanFixtures::staff(RoleName::Kesiswaan, 'Ibu Dwi Rahayu');
    $application = GuruFixtures::talent($this->retno, GuruFixtures::student('Aurelia Maharani', 'X-3'));

    app(RejectTalentApplication::class)->handle($kesiswaan, $application, 'Catatan yang hanya untuk siswa.', ['note' => 'Dampingi latihan soal tiap Kamis.']);

    Livewire::actingAs($this->retno)->test(Binaan::class)
        ->call('setTab', 'talenta')
        ->assertSee('Aurelia Maharani')
        ->assertSee('Tidak lolos')
        ->assertSee('Catatan untuk pembina:')
        ->assertSee('Dampingi latihan soal tiap Kamis.')
        ->assertDontSee('Catatan yang hanya untuk siswa.');

    app(RestoreTalentApplication::class)->handle($kesiswaan, $application->fresh(), 'Dibuka lagi untukmu.');

    Livewire::actingAs($this->retno)->test(Binaan::class)
        ->call('setTab', 'talenta')
        ->assertSee('Aurelia Maharani')
        ->assertSee('Seleksi/asesmen')
        ->assertDontSee('Tidak lolos')
        ->assertSee('Dampingi latihan soal tiap Kamis.')
        ->assertDontSee('Dibuka lagi untukmu.')
        ->assertViewHas('counts', fn (array $c): bool => $c['talenta'] === 1);

    // Still only the assigned pembina.
    Livewire::actingAs($this->hendra)->test(Binaan::class)
        ->call('setTab', 'talenta')
        ->assertDontSee('Aurelia Maharani');
});

it('separates finished registrations and searches by student or lomba', function (): void {
    $done = GuruFixtures::registration($this->retno, GuruFixtures::student('Citra Ayu Lestari', 'XI-2'), RegistrationStatus::Selesai,
        competition: KesiswaanFixtures::competition(['name' => 'FLS3N Kota 2026']));

    Livewire::actingAs($this->retno)->test(Binaan::class)
        ->assertDontSee('FLS3N Kota 2026')
        ->call('setTab', 'selesai')
        ->assertSee('FLS3N Kota 2026')
        ->assertDontSee('WICE 2026')
        ->call('setTab', 'aktif')
        ->set('q', 'Dimas')
        ->assertSee('WICE 2026')
        ->set('q', 'Raka')
        ->assertDontSee('NSIF 2026')
        ->assertSee('Tidak ada yang cocok')
        ->set('q', 'wice')
        ->assertSee('Nadia Putri Anindya');

    expect($done->mentor_id)->toBe($this->retno->getKey());
});

it('ignores an unknown tab', function (): void {
    Livewire::actingAs($this->retno)->withQueryParams(['tab' => 'semua-guru'])->test(Binaan::class)
        ->assertSet('tab', 'aktif');
});

// Detail ------------------------------------------------------------------------------------------

it('shows the tracker, team, report and points of an own binaan', function (): void {
    $report = new Report(['registration_id' => $this->mine->getKey(), 'claimed_result' => ResultType::Juara2]);
    $report->forceFill(['status' => ReportStatus::PerluRevisi, 'note' => 'Sertifikat belum terbaca.', 'submitted_by' => $this->nadia->user_id, 'submitted_at' => now()])->save();
    $file = File::factory()->create(['original_name' => 'sertifikat-wice.pdf', 'owner_user_id' => $this->nadia->user_id]);
    ReportFile::factory()->create(['report_id' => $report->getKey(), 'kind' => ReportFileKind::Sertifikat, 'file_id' => $file->getKey()]);

    $this->actingAs($this->retno)->get(route('guru.binaan.show', $this->mine))
        ->assertOk()
        ->assertSee('WICE 2026')
        ->assertSee('Nadia Putri Anindya')
        ->assertSee('Dimas Arya Nugroho')
        ->assertSee('Perlu revisi')
        ->assertSee('Sertifikat belum terbaca.')
        ->assertSee('sertifikat-wice.pdf')
        ->assertSee(route('files.show', $file), false);

    // The guru pembimbing may open the report file (FilePolicy → ReportPolicy::view).
    expect($this->retno->can('view', $file))->toBeTrue()
        ->and($this->hendra->can('view', $file))->toBeFalse();
});

it('shows surat tugas to the mentor only after TU sent them', function (): void {
    $pdf = File::factory()->purpose(FilePurpose::Letter)->create(['original_name' => 'surat-perintah-tugas.pdf']);
    $letter = Letter::factory()->guru()->create(['registration_id' => $this->mine->getKey(), 'file_id' => $pdf->getKey()]);

    $this->actingAs($this->retno)->get(route('guru.binaan.show', $this->mine))
        ->assertOk()
        ->assertSee('Surat muncul di sini setelah Tata Usaha mengirimnya.')
        ->assertDontSee(route('files.show', $pdf), false);

    $letter->forceFill(['sent_at' => now()])->save();

    $this->actingAs($this->retno)->get(route('guru.binaan.show', $this->mine))
        ->assertSee('Surat perintah tugas guru')
        ->assertSee(route('files.show', $pdf), false);

    expect($letter->type)->toBe(LetterType::Guru);
});

// Lomba ---------------------------------------------------------------------------------------------

it('lists only the published catalog for a guru', function (): void {
    // D-145: a guru no longer uploads lomba info, so there is no "Upload saya" tab; an old upload stays in the database.
    $pending = Competition::factory()->create(['name' => 'Lomba Esai Guru 2026']);
    $pending->forceFill(['submitted_by' => $this->retno->getKey()])->save();

    Livewire::actingAs($this->retno)->test(Lomba::class)
        ->assertSee('WICE 2026')
        ->assertSee('NSIF 2026')
        ->assertDontSee('Lomba Esai Guru 2026')
        ->assertDontSee('Upload saya')
        ->assertDontSee('Upload info lomba');

    // The old upload still opens in the drawer for the guru who sent it (CompetitionPolicy::view).
    $this->actingAs($this->retno)->get(route('guru.lomba.index', ['lihat' => $pending->getKey()]))
        ->assertOk()
        ->assertSee('Lomba Esai Guru 2026');
});

it('opens the detail drawer for a visible lomba and 404 for an invisible one', function (): void {
    GuruFixtures::registration($this->retno, GuruFixtures::student('Bima Satria Wicaksono', 'XII-4'), competition: $this->nsif);
    $foreign = Competition::factory()->create(['name' => 'Usulan Pak Hendra']);
    $foreign->forceFill(['submitted_by' => $this->hendra->getKey()])->save();

    Livewire::actingAs($this->retno)->withQueryParams(['lihat' => $this->nsif->getKey()])->test(Lomba::class)
        ->assertSee('Siswa binaan di lomba ini')
        ->assertSee('Bima Satria Wicaksono')
        ->assertDontSee('Raka Pradipta');

    $this->actingAs($this->retno)->get(route('guru.lomba.index', ['lihat' => $foreign->getKey()]))->assertNotFound();
    $this->actingAs($this->retno)->get(route('guru.lomba.index', ['lihat' => 'xyz']))->assertNotFound();
});

it('enables the guru menu items and the topbar search', function (): void {
    $menu = Navigation::for($this->retno, 'guru', currentRoute: 'guru.beranda');
    $items = collect($menu['sections'])->flatMap(fn (array $s): array => $s['items'])->keyBy('key');

    expect($items['binaan']['href'])->toBe(route('guru.beranda'))
        ->and($items['binaan']['disabled'])->toBeFalse()
        ->and($items['lomba']['href'])->toBe(route('guru.lomba.index'))
        ->and($menu['search']['action'])->toBe(route('guru.beranda'));
});

it('treats a user without the guru role as not allowed even with a mentor id', function (): void {
    $humas = KesiswaanFixtures::staff(RoleName::Humas, 'Bapak Humas');
    $this->other->forceFill(['mentor_id' => $humas->getKey()])->save();

    expect($humas->can('viewAsMentor', $this->other->fresh()))->toBeFalse();
    expect(User::query()->whereKey($humas->getKey())->exists())->toBeTrue();
});

// QA5 (2026-10-07) -------------------------------------------------------------------------------------------

it('tells the guru a report waits for its revision, not that none was sent', function (): void {
    $this->actingAs($this->retno)->get(route('guru.binaan.show', $this->mine))
        ->assertOk()
        ->assertSee('Laporan hasil belum dikirim')
        ->assertDontSee('Laporan perlu direvisi');

    $report = new Report(['registration_id' => $this->mine->getKey(), 'claimed_result' => ResultType::Juara2]);
    $report->forceFill(['status' => ReportStatus::PerluRevisi, 'note' => 'Sertifikat belum terbaca.', 'submitted_by' => $this->nadia->user_id, 'submitted_at' => now()])->save();

    $this->actingAs($this->retno)->get(route('guru.binaan.show', $this->mine))
        ->assertOk()
        ->assertSee('Laporan perlu direvisi')
        ->assertSee('Ketua tim memperbaiki laporan dari akunnya.')
        ->assertDontSee('Laporan hasil belum dikirim');
});

it('opens a sent surat tugas in the preview popup, the link itself staying the download', function (): void {
    $pdf = File::factory()->purpose(FilePurpose::Letter)->create(['original_name' => 'surat-perintah-tugas.pdf', 'mime' => 'application/pdf']);
    Letter::factory()->guru()->create(['registration_id' => $this->mine->getKey(), 'file_id' => $pdf->getKey(), 'sent_at' => now()]);

    $html = $this->actingAs($this->retno)->get(route('guru.binaan.show', $this->mine))->assertOk()->getContent();

    expect($html)->toContain('href="'.route('files.show', $pdf).'"')
        ->and($html)->toContain('data-sth-preview="'.e(route('files.show', ['file' => $pdf, 'tampil' => 1])).'"')
        ->and($html)->toContain('data-sth-preview-type="pdf"')
        ->and($html)->toContain('data-sth-preview-name="surat-perintah-tugas.pdf"');
});
