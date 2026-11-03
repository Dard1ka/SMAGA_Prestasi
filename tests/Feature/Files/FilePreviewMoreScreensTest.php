<?php

declare(strict_types=1);

use App\Enums\FilePurpose;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Livewire\Kesiswaan\Lomba\Index as LombaIndex;
use App\Livewire\Kesiswaan\Talenta;
use App\Livewire\Siswa\ProfilTalenta;
use App\Models\Competition;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| M3 (coordinator) · the preview popup of M3-C on three more screens: the evidence of a talent choice
| (Kesiswaan › Talenta and the student's own Profil talenta) and the juknis of a queued lomba (Kesiswaan ›
| Usulan lomba). Same contract as FilePreviewScreensTest: href = download, data-sth-preview = inline address.
| Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    F::boot();
});

/** @return list<string> */
function m3PreviewAttributes(File $file, string $type): array
{
    return [
        'href="'.route('files.show', $file).'"',
        'data-sth-preview="'.route('files.show', $file).'?tampil=1"',
        'data-sth-preview-type="'.$type.'"',
        'data-sth-preview-name="'.$file->original_name.'"',
    ];
}

it('opens the evidence of a talent choice in the popup on Kesiswaan › Talenta', function (): void {
    $kesiswaan = F::staff(RoleName::Kesiswaan);
    $application = F::application(F::student('Hana Safira', 'X-H'), TalentStatus::Terdaftar, 'Biologi');
    $evidence = File::factory()->create([
        'original_name' => 'sertifikat-osn-smp-biologi-2025.pdf',
        'purpose' => FilePurpose::TalentEvidence,
        'owner_user_id' => $application->student->user_id,
    ]);
    $application->forceFill(['evidence_file_id' => $evidence->getKey()])->save();

    $component = Livewire::actingAs($kesiswaan)->test(Talenta::class)->call('showPanel', $application->getKey());

    foreach (m3PreviewAttributes($evidence, 'pdf') as $attribute) {
        $component->assertSeeHtml($attribute);
    }
});

it('opens the student\'s own talent evidence in the popup on Profil talenta', function (): void {
    $application = F::application(F::student('Hana Safira', 'X-H'), TalentStatus::Terdaftar, 'Biologi');
    $evidence = File::factory()->create([
        'original_name' => 'sertifikat-osn-smp-biologi-2025.pdf',
        'purpose' => FilePurpose::TalentEvidence,
        'owner_user_id' => $application->student->user_id,
    ]);
    $application->forceFill(['evidence_file_id' => $evidence->getKey()])->save();

    $component = Livewire::actingAs($application->student->user)->test(ProfilTalenta::class);

    foreach (m3PreviewAttributes($evidence, 'pdf') as $attribute) {
        $component->assertSeeHtml($attribute);
    }
});

it('opens the juknis of a queued lomba in the popup on Usulan lomba', function (): void {
    $kesiswaan = F::staff(RoleName::Kesiswaan);
    $siswa = F::student('Bima Satria Wicaksono', 'XII-G')->user;
    $juknis = File::factory()->create([
        'original_name' => 'juknis-robotik-jateng.pdf',
        'purpose' => FilePurpose::Juknis,
        'owner_user_id' => $siswa->getKey(),
    ]);
    Competition::factory()->proposal()->create([
        'name' => 'Kompetisi Robotik Pelajar Jateng 2026',
        'submitted_by' => $siswa->getKey(),
        'juknis_file_id' => $juknis->getKey(),
    ]);

    $component = Livewire::withQueryParams(['tab' => 'verifikasi'])->actingAs($kesiswaan)->test(LombaIndex::class);

    foreach (m3PreviewAttributes($juknis, 'pdf') as $attribute) {
        $component->assertSeeHtml($attribute);
    }
});

it('leaves a file that cannot be previewed as a plain download link', function (): void {
    $kesiswaan = F::staff(RoleName::Kesiswaan);
    $application = F::application(F::student('Hana Safira', 'X-H'), TalentStatus::Terdaftar, 'Biologi');
    $evidence = File::factory()->create([
        'original_name' => 'bukti.docx',
        'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'purpose' => FilePurpose::TalentEvidence,
        'owner_user_id' => $application->student->user_id,
    ]);
    $application->forceFill(['evidence_file_id' => $evidence->getKey()])->save();

    Livewire::actingAs($kesiswaan)->test(Talenta::class)
        ->call('showPanel', $application->getKey())
        ->assertSeeHtml('href="'.route('files.show', $evidence).'"')
        ->assertDontSeeHtml('data-sth-preview="');
});
