<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\FilePurpose;
use App\Enums\Level;
use App\Enums\RegistrationStatus;
use App\Enums\ReportFileKind;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\WeightCategory;
use App\Livewire\Kesiswaan\DatabasePrestasi;
use App\Livewire\Kesiswaan\VerifikasiHasil;
use App\Models\Achievement;
use App\Models\File;
use App\Models\Letter;
use App\Models\Report;
use App\Models\ReportFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Guru\GuruFixtures;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;
use Tests\Feature\Kesiswaan\Prestasi\PrestasiFixtures;
use Tests\Feature\Siswa\Hasil\HasilFixtures;

/*
| M3-C · file preview popup, the screen side. A link that can be previewed carries
|   href = download · data-sth-preview = inline address · data-sth-preview-type = pdf|image · data-sth-preview-name
| and the signed-in layouts render the one dialog (#sth-preview) that resources/js/app.js fills.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    F::boot();
});

/** The attributes a previewable link must carry, as they appear in the HTML. @return list<string> */
function m3cPreviewAttributes(File $file, string $type): array
{
    return [
        'href="'.route('files.show', $file).'"',
        'data-sth-preview="'.route('files.show', $file).'?tampil=1"',
        'data-sth-preview-type="'.$type.'"',
        'data-sth-preview-name="'.$file->original_name.'"',
    ];
}

// The dialog ----------------------------------------------------------------------------------------

it('renders the preview dialog with a title, a place for the viewer, Tutup and Download', function (): void {
    $view = $this->blade('<x-sth.preview />');

    $view->assertSee('id="sth-preview"', false)
        ->assertSee('id="sth-preview-title"', false)
        ->assertSee('aria-labelledby="sth-preview-title"', false)
        ->assertSee('data-sth-preview-stage', false)
        ->assertSee('role="status"', false)
        ->assertSee('data-sth-preview-download', false)
        ->assertSee('data-sth-close', false)
        ->assertSee('Tutup')
        ->assertSee('Download')
        ->assertSee('Browser ini tidak bisa menampilkan PDF, download untuk membukanya.')
        // No viewer and no address until a link is clicked; never an <object> or <embed> (object-src 'none').
        ->assertDontSee('<iframe', false)
        ->assertDontSee('<object', false)
        ->assertDontSee('<embed', false)
        ->assertDontSee('style="', false)
        ->assertDontSee('wire:navigate', false);
});

it('puts the dialog once on a signed-in page and on the mobile layout, not on public or login pages', function (): void {
    $kesiswaan = F::staff(RoleName::Kesiswaan);

    $page = $this->actingAs($kesiswaan)->get(route('kesiswaan.verifikasi.index'))->assertOk()->getContent();
    expect(substr_count((string) $page, 'id="sth-preview"'))->toBe(1);

    $mobile = (string) $this->blade('<x-layouts::mobile title="Laporkan hasil">Isi</x-layouts::mobile>');
    expect(substr_count($mobile, 'id="sth-preview"'))->toBe(1);

    auth()->logout();

    $this->get(route('login'))->assertOk()->assertDontSee('id="sth-preview"', false);
    $this->get(route('public.peringkat'))->assertOk()->assertDontSee('id="sth-preview"', false);
});

it('documents both hooks in app.js and keeps the frame free of sandbox and of other origins', function (): void {
    $script = (string) file_get_contents(resource_path('js/app.js'));

    expect($script)
        ->toContain('data-sth-preview="<inline url>"')
        ->toContain('data-sth-copy="#target-id"')
        ->toContain("method: 'HEAD'")
        ->toContain('parsed.origin === window.location.origin')
        ->toContain('Data disalin.')
        ->toContain('Tekan Ctrl+C untuk menyalin.')
        ->not->toContain('createObjectURL')
        ->not->toContain('innerHTML');
});

// Kesiswaan › Verifikasi hasil ----------------------------------------------------------------------

it('opens every evidence file of Verifikasi hasil in the popup and keeps the download as href', function (): void {
    $kesiswaan = F::staff(RoleName::Kesiswaan);
    $report = F::pendingReport(F::student('Rizky Maulana', 'XI-1'));
    $pdf = File::factory()->create(['original_name' => 'sertifikat-okn-undip-2026.pdf', 'owner_user_id' => $report->submitted_by]);
    $photo = File::factory()->image()->create(['original_name' => 'foto-penyerahan-piala.jpg', 'owner_user_id' => $report->submitted_by]);
    ReportFile::factory()->create(['report_id' => $report->getKey(), 'file_id' => $pdf->getKey(), 'kind' => ReportFileKind::Sertifikat]);
    ReportFile::factory()->create(['report_id' => $report->getKey(), 'file_id' => $photo->getKey(), 'kind' => ReportFileKind::Foto]);

    $component = Livewire::actingAs($kesiswaan)->test(VerifikasiHasil::class);

    foreach ([...m3cPreviewAttributes($pdf, 'pdf'), ...m3cPreviewAttributes($photo, 'image')] as $attribute) {
        $component->assertSeeHtml($attribute);
    }

    $component->assertSee('Buka')
        ->assertSeeHtml('aria-haspopup="dialog"')
        // The thumbnail of an image is the inline address too; a PDF has none.
        ->assertSeeHtml('<img src="'.route('files.show', $photo).'?tampil=1"')
        ->assertDontSeeHtml('<img src="'.route('files.show', $pdf))
        ->assertViewHas('detail', function (array $detail): bool {
            $byType = collect($detail['files'])->keyBy('previewType');

            return count($detail['files']) === 2
                && $byType['pdf']['thumb'] === null
                && $byType['image']['thumb'] === $byType['image']['preview'];
        });
});

it('offers a plain download on Verifikasi hasil for a file that has no preview', function (): void {
    $kesiswaan = F::staff(RoleName::Kesiswaan);
    $report = F::pendingReport(F::student('Rizky Maulana', 'XI-1'));
    $other = File::factory()->create(['original_name' => 'lampiran.bin', 'mime' => 'application/octet-stream', 'owner_user_id' => $report->submitted_by]);
    ReportFile::factory()->create(['report_id' => $report->getKey(), 'file_id' => $other->getKey(), 'kind' => ReportFileKind::Sertifikat]);

    Livewire::actingAs($kesiswaan)->test(VerifikasiHasil::class)
        ->assertSeeHtml('href="'.route('files.show', $other).'"')
        ->assertSee('Download')
        ->assertDontSeeHtml('data-sth-preview=');
});

// Kesiswaan › Database prestasi ---------------------------------------------------------------------

it('opens the certificate of the Database prestasi drawer in the popup', function (): void {
    $kesiswaan = F::staff(RoleName::Kesiswaan);
    $nadia = F::student('Nadia Putri Anindya', 'XI-4', '225226201');
    $fixtures = new PrestasiFixtures;
    [$achievement] = $fixtures->prestasi([$nadia], $fixtures->lomba('WICE 2026', Bidang::Riset), ResultType::Juara2);
    $certificate = File::factory()->create(['original_name' => 'sertifikat-wice-2026.pdf']);
    ReportFile::factory()->create(['report_id' => $achievement->report_id, 'kind' => ReportFileKind::Sertifikat, 'file_id' => $certificate->getKey()]);

    $component = Livewire::actingAs($kesiswaan)->test(DatabasePrestasi::class)->call('showDetail', (string) $achievement->getKey());

    foreach (m3cPreviewAttributes($certificate, 'pdf') as $attribute) {
        $component->assertSeeHtml($attribute);
    }

    $component->assertSee('sertifikat-wice-2026.pdf');
});

// Guru › Siswa binaan detail ------------------------------------------------------------------------

it('opens the evidence and the sent letter of an own binaan in the popup, both links staying the download', function (): void {
    $retno = GuruFixtures::guru('Retno Wulandari, S.Pd.');
    $hendra = GuruFixtures::guru('Hendra Kusuma, S.Pd., M.Si.');
    $nadia = GuruFixtures::student('Nadia Putri Anindya', 'XI-4');
    $registration = GuruFixtures::registration($retno, $nadia, RegistrationStatus::MenungguLaporan, [], F::competition(['name' => 'WICE 2026']));

    $report = new Report(['registration_id' => $registration->getKey(), 'claimed_result' => ResultType::Juara2]);
    $report->forceFill(['status' => ReportStatus::Dikirim, 'submitted_by' => $nadia->user_id, 'submitted_at' => now()])->save();
    $evidence = File::factory()->create(['original_name' => 'sertifikat-wice.pdf', 'owner_user_id' => $nadia->user_id]);
    ReportFile::factory()->create(['report_id' => $report->getKey(), 'kind' => ReportFileKind::Sertifikat, 'file_id' => $evidence->getKey()]);

    $letterFile = File::factory()->purpose(FilePurpose::Letter)->create(['original_name' => 'surat-perintah-tugas.pdf']);
    Letter::factory()->guru()->create(['registration_id' => $registration->getKey(), 'file_id' => $letterFile->getKey()])
        ->forceFill(['sent_at' => now()])->save();

    $response = $this->actingAs($retno)->get(route('guru.binaan.show', $registration))->assertOk();

    foreach (m3cPreviewAttributes($evidence, 'pdf') as $attribute) {
        $response->assertSee($attribute, false);
    }

    $html = (string) $response->getContent();

    // QA5 (D-112): the sent letter opens in the popup too, its href stays the download. Exactly these two links.
    foreach (m3cPreviewAttributes($letterFile, 'pdf') as $attribute) {
        $response->assertSee($attribute, false);
    }

    expect(substr_count($html, 'data-sth-preview="'))->toBe(2);

    // Another guru gets neither the page nor the file, inline or not.
    $this->actingAs($hendra)->get(route('guru.binaan.show', $registration))->assertNotFound();
    $this->actingAs($hendra)->get(route('files.show', ['file' => $evidence, 'tampil' => 1]))->assertNotFound();
});

// Siswa › Portofolio --------------------------------------------------------------------------------

it('opens the own certificate of Portofolio in the popup', function (): void {
    $nadia = HasilFixtures::student('Nadia Putri Anindya', 'XI-4');
    $raka = HasilFixtures::student('Raka Pradipta', 'XII-2');
    $certificate = File::factory()->create(['original_name' => 'sertifikat-opsi-2026.pdf', 'owner_user_id' => $nadia->user->getKey()]);

    $registration = HasilFixtures::registration($nadia, RegistrationStatus::Selesai, HasilFixtures::competition([
        'name' => 'OPSI 2026', 'level' => Level::Nasional, 'weight_category' => WeightCategory::A, 'starts_on' => '2026-08-04', 'final_on' => '2026-08-07',
    ]));
    $achievement = Achievement::factory()->scored(Level::Nasional, ResultType::Juara2, WeightCategory::A)->create([
        'student_id' => $nadia->getKey(),
        'competition_id' => $registration->competition_id,
    ]);
    ReportFile::factory()->create(['report_id' => $achievement->report_id, 'kind' => ReportFileKind::Sertifikat, 'file_id' => $certificate->getKey()]);

    $response = $this->actingAs($nadia->user)->get(route('siswa.portofolio'))->assertOk()->assertSee('OPSI 2026');

    foreach (m3cPreviewAttributes($certificate, 'pdf') as $attribute) {
        $response->assertSee($attribute, false);
    }

    $response->assertSee('Terverifikasi · buka');

    // Another student sees neither the link nor the file.
    $this->actingAs($raka->user)->get(route('siswa.portofolio'))->assertOk()->assertDontSee('data-sth-preview=', false);
    $this->actingAs($raka->user)->get(route('files.show', ['file' => $certificate, 'tampil' => 1]))->assertNotFound();
});
