<?php

declare(strict_types=1);

use App\Actions\Talent\SaveTalentProfile;
use App\Enums\TalentTypeCode;
use App\Livewire\Siswa\ProfilTalenta;
use App\Models\TalentApplication;
use App\Models\TalentType;
use Database\Seeders\TalentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;
use Tests\Support\TestDisks;

/*
 * R3 (second browser check, Playwright, 2026-10-31) on Profil saya › Talenta saya: a refused bukti dukung stood in a
 * "Siap dikirim" row, and the picker of "Sertifikat pendukung" kept the name of a refused file. A refused file does not
 * stay chosen now: the drop zone comes back with the reason under it. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Livewire keeps test uploads on its own disk "tmp-for-tests", which it fakes in ONE fixed folder that a test run
    // starting at the same time wipes (Storage::fake()); these tests hold uploads across requests, so they get a folder
    // of their own (TestDisks::cleanup() deletes it).
    TestDisks::isolate('tmp-for-tests');
    F::boot();
    $this->seed(TalentSeeder::class);
    $this->withoutVite();

    $this->nadia = F::student(talent: false);
    $this->osn = TalentType::query()->where('code', TalentTypeCode::Osn)->firstOrFail();
    $this->kimia = $this->osn->branches()->where('name', 'Kimia')->firstOrFail();
});

it('drops a refused bukti, so its drop zone comes back with the reason (R3)', function (string $name, int $kilobytes, string $message): void {
    $form = Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->set('bukti', UploadedFile::fake()->create($name, $kilobytes))
        ->assertSet('bukti', null)
        ->assertDontSee('Siap dikirim')
        ->assertDontSee($name);

    expect($form->errors()->get('bukti'))->toBe([$message])
        // <x-sth.file-drop> prints the message under the drop zone and ties it to its input.
        ->and($form->html())->toContain('<p id="talenta-bukti-error" class="field-error">')
        ->and($form->html())->toContain('aria-describedby="talenta-bukti-hint talenta-bukti-error"');

    // A file that passes takes the drop zone's place, ready to send, and the message goes.
    $form->set('bukti', F::pdf('sertifikat-osn-kimia.pdf'))
        ->assertHasNoErrors()
        ->assertSee('sertifikat-osn-kimia.pdf')
        ->assertSee('Siap dikirim');
})->with([
    'over 5 MB' => ['sertifikat-osn.pdf', 6 * 1024, 'Ukuran file maksimal 5 MB.'],
    'another type' => ['sertifikat-osn.docx', 40, 'Pakai PDF, JPG, atau PNG.'],
]);

it('keeps the talent choice when the bukti is refused, and adds it with the next file', function (): void {
    $form = Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->call('pilihJenis', $this->osn->getKey())
        ->call('pilihCabang', $this->kimia->getKey())
        ->set('bukti', UploadedFile::fake()->create('sertifikat-osn.pdf', 6 * 1024))
        ->assertSet('bukti', null)
        ->assertSet('jenis', $this->osn->getKey())
        ->assertSet('cabang', $this->kimia->getKey())
        ->set('bukti', F::pdf('sertifikat-osn.pdf'))
        ->call('daftar')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'OSN · Kimia ditambahkan.', tone: 'success');

    expect(TalentApplication::query()->where('student_id', $this->nadia->getKey())->sole()->evidence_file_id)->not->toBeNull();
});

it('drops a bukti the Action refused for its content, so its drop zone comes back with the reason', function (): void {
    $form = Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->call('pilihJenis', $this->osn->getKey())
        ->call('pilihCabang', $this->kimia->getKey())
        // A PDF by its name only: the screen's rules let it through, the content check of ApplyTalent (StoreUpload) refuses it.
        ->set('bukti', UploadedFile::fake()->createWithContent('sertifikat-osn.pdf', 'Bukan isi PDF.'))
        ->assertSee('Siap dikirim')
        ->call('daftar')
        ->assertHasErrors(['bukti'])
        ->assertSet('bukti', null)
        ->assertDontSee('Siap dikirim')
        ->assertNotDispatched('sth-toast');

    expect($form->html())->toContain('<p id="talenta-bukti-error" class="field-error">')
        ->and(TalentApplication::query()->count())->toBe(0);

    // The choice stays on the screen: the student only picks another file.
    $form->assertSet('jenis', $this->osn->getKey())
        ->assertSet('cabang', $this->kimia->getKey())
        ->set('bukti', F::pdf('sertifikat-osn.pdf'))
        ->call('daftar')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'OSN · Kimia ditambahkan.', tone: 'success');
});

it('does not keep the name of a refused certificate in the picker of Sertifikat pendukung (R3)', function (string $name, int $kilobytes, string $message): void {
    $form = Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->set('sertifikatPilih', UploadedFile::fake()->create($name, $kilobytes))
        ->assertSet('sertifikatPilih', null)
        ->assertCount('sertifikatBaru', 0)
        ->assertDontSee($name);

    expect($form->errors()->get('sertifikatPilih'))->toBe([$message])
        ->and($form->html())->toContain('<p id="sertifikat-pilih-error" class="field-error">');

    // The next file is added as a row ready to save, and the message goes.
    $form->set('sertifikatPilih', F::pdf('piagam-lkti-2024.pdf'))
        ->assertSet('sertifikatPilih', null)
        ->assertCount('sertifikatBaru', 1)
        ->assertHasNoErrors()
        ->assertSee('piagam-lkti-2024.pdf')
        ->assertSee('Siap dikirim');
})->with([
    'over 5 MB' => ['piagam-lkti-2024.pdf', 6 * 1024, 'Ukuran file maksimal 5 MB.'],
    'another type' => ['piagam-lkti-2024.docx', 120, 'Pakai PDF, JPG, atau PNG.'],
]);

/*
 * Review of r3 (2026-11-01): a refused bukti left its message in the error bag after the file was dropped, so a later
 * "Tambah talenta" without a file added the talent ("OSN · Kimia ditambahkan.") while "Ukuran file maksimal 5 MB." still
 * stood under the empty drop zone; the Riwayat form did the same with a refused "Sertifikat pendukung" after a save.
 */
it('lets a try without a file clear the message of a refused bukti (r3 review)', function (): void {
    $form = Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->call('pilihJenis', $this->osn->getKey())
        ->call('pilihCabang', $this->kimia->getKey())
        ->set('bukti', UploadedFile::fake()->create('sertifikat-osn.pdf', 6 * 1024))
        ->assertSet('bukti', null)
        ->assertHasErrors(['bukti'])
        ->call('daftar')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'OSN · Kimia ditambahkan.', tone: 'success');

    expect($form->html())->not->toContain('Ukuran file maksimal 5 MB.')
        ->and($form->html())->not->toContain('talenta-bukti-error')
        // The talent went in without the refused file.
        ->and(TalentApplication::query()->where('student_id', $this->nadia->getKey())->sole()->evidence_file_id)->toBeNull();
});

it('clears the messages of earlier tries in the Riwayat form once it is saved, and keeps those of Tambah talenta (r3 review)', function (): void {
    $form = Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        // A refused save (too long a text), a refused certificate and a refused bukti of the other form on the page.
        ->set('dikuasai', str_repeat('a', SaveTalentProfile::MAX_SKILLS + 1))
        ->call('simpan')
        ->assertHasErrors(['dikuasai'])
        ->set('sertifikatPilih', UploadedFile::fake()->create('piagam-lkti-2024.pdf', 6 * 1024))
        ->assertSet('sertifikatPilih', null)
        ->set('bukti', UploadedFile::fake()->create('sertifikat-osn.docx', 40))
        ->assertHasErrors(['dikuasai', 'sertifikatPilih', 'bukti']);

    $form->set('dikuasai', 'Kimia organik dasar')
        ->call('simpan')
        ->assertHasNoErrors(['dikuasai', 'sertifikatPilih'])
        ->assertSet('tersimpan', true)
        ->assertDispatched('sth-toast', message: 'Riwayat talenta tersimpan.', tone: 'success');

    $html = $form->html();
    expect($html)->not->toContain('Ukuran file maksimal 5 MB.')
        ->and($html)->not->toContain('Maksimal '.SaveTalentProfile::MAX_SKILLS.' karakter.')
        ->and($html)->not->toContain('sertifikat-pilih-error')
        // A save of the Riwayat form does not decide the Tambah talenta form: its refusal stays until its own next try.
        ->and($form->errors()->get('bukti'))->toBe(['Pakai PDF, JPG, atau PNG.']);
});

it('prints the size of a chosen file as every screen does, with a decimal comma (r3 review)', function (): void {
    // 2 MB and 1.023 KB: the row printed "2 MB" and "1023 KB" with its own rounding before (App\Support\FileSizeLabel now).
    Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->set('bukti', UploadedFile::fake()->create('sertifikat-osn.pdf', 2048))
        ->assertSee('Siap dikirim · 2,0 MB')
        ->set('sertifikatPilih', UploadedFile::fake()->create('piagam-lkti-2024.pdf', 1023))
        ->assertCount('sertifikatBaru', 1)
        ->assertSee('Siap dikirim · 1.023 KB');
});
