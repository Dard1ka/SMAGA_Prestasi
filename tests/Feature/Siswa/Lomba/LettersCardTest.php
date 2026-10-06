<?php

declare(strict_types=1);

use App\Enums\FilePurpose;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Livewire\Siswa\LombaSaya\Index;
use App\Models\File;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\StaffProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

uses(RefreshDatabase::class);

/*
| M2-C (QA Q1-01): Siswa › Lomba saya › "Surat tugas". The guru letter and its Lampiran carry the teacher's NIP
| and rank, so LetterPolicy keeps that file from students. The student's card therefore shows it as information
| only (no link, no button); the guru pembimbing and staff keep their download. A letter Tata Usaha has not
| sent yet is not offered either (files.show would answer 404).
*/

beforeEach(function (): void {
    Notification::fake();
    F::boot();
    config(['fortify.sth.require_staff_2fa' => false]);
    $this->withoutVite();

    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho');
    $this->mentor = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    StaffProfile::factory()->create(['user_id' => $this->mentor->getKey(), 'honorific' => 'Bu']);

    $this->registration = F::registration($this->nadia, RegistrationStatus::SuratTerbit, members: [$this->dimas], mentor: $this->mentor);
});

/** Both letters of the registration with a stored PDF each; returns [siswa file, guru file]. */
function m2cLetters(Registration $registration, bool $sent): array
{
    $files = [];

    foreach ([LetterType::Siswa, LetterType::Guru] as $type) {
        $file = File::factory()->purpose(FilePurpose::Letter)->create(['owner_user_id' => null]);
        Storage::disk('local')->put($file->path, '%PDF-1.4');

        Letter::factory()->create(['registration_id' => $registration->getKey(), 'type' => $type, 'file_id' => $file->getKey()])
            ->forceFill(['sent_at' => $sent ? now() : null])->save();

        $files[] = $file;
    }

    return $files;
}

it('shows a student the guru letter as information with the guru name and no link', function (): void {
    [$siswaPdf, $guruPdf] = m2cLetters($this->registration, sent: true);

    foreach ([$this->nadia, $this->dimas] as $student) {
        $this->actingAs($student->user)
            ->get(route('siswa.lomba-saya.show', $this->registration))
            ->assertOk()
            ->assertSee('Surat tugas siswa')
            ->assertSee(route('files.show', $siswaPdf), false)
            ->assertSee('Surat perintah tugas untuk guru pendamping sudah terbit')
            ->assertSee('Untuk Bu Retno Wulandari, S.Pd.')
            ->assertDontSee(route('files.show', $guruPdf), false)
            ->assertDontSee('Download surat perintah tugas');
    }
});

it('builds the guru row without a file link and the siswa row from LetterPolicy', function (): void {
    [$siswaPdf] = m2cLetters($this->registration, sent: true);

    Livewire::actingAs($this->nadia->user)
        ->test(Index::class, ['registration' => $this->registration])
        ->assertViewHas('detail', function (array $detail) use ($siswaPdf): bool {
            [$siswa, $guru] = $detail['letters'];

            return count($detail['letters']) === 2
                && $siswa['info'] === false && $siswa['href'] === route('files.show', $siswaPdf)
                && $guru['info'] === true && $guru['href'] === null;
        });
});

it('keeps the policy: the guru file answers 404 for students, the siswa file is served', function (): void {
    [$siswaPdf, $guruPdf] = m2cLetters($this->registration, sent: true);

    foreach ([$this->nadia, $this->dimas] as $student) {
        $this->actingAs($student->user)->get(route('files.show', $guruPdf))->assertNotFound();
        $this->actingAs($student->user)->get(route('files.show', $siswaPdf))->assertOk();
    }
});

it('keeps the download for the guru pembimbing and for staff', function (): void {
    [$siswaPdf, $guruPdf] = m2cLetters($this->registration, sent: true);

    $this->actingAs($this->mentor)
        ->get(route('guru.binaan.show', $this->registration))
        ->assertOk()
        ->assertSee(route('files.show', $guruPdf), false)
        ->assertSee(route('files.show', $siswaPdf), false);
    $this->actingAs($this->mentor)->get(route('files.show', $guruPdf))->assertOk();

    foreach ([RoleName::TataUsaha, RoleName::KepalaSekolah] as $role) {
        $this->actingAs(F::staff($role, 'Staf '.$role->value))->get(route('files.show', $guruPdf))->assertOk();
    }

    // Another guru is not the pembimbing of this registration.
    $this->actingAs(F::staff(RoleName::Guru, 'Hendra Kusuma, S.Pd.'))->get(route('files.show', $guruPdf))->assertNotFound();
});

it('offers no letter that Tata Usaha has not sent yet', function (): void {
    $approved = F::registration($this->nadia, RegistrationStatus::Disetujui, members: [$this->dimas], mentor: $this->mentor);
    [$siswaPdf, $guruPdf] = m2cLetters($approved, sent: false);

    $this->actingAs($this->nadia->user)
        ->get(route('siswa.lomba-saya.show', $approved))
        ->assertOk()
        ->assertSee('Tata Usaha sedang menyiapkan surat tugas.')
        ->assertSee('Belum terbit')
        ->assertDontSee('Surat perintah tugas untuk guru pendamping sudah terbit')
        ->assertDontSee(route('files.show', $siswaPdf), false)
        ->assertDontSee(route('files.show', $guruPdf), false);

    $this->actingAs($this->nadia->user)->get(route('files.show', $siswaPdf))->assertNotFound();
});

it('says who the guru letter is for even without a guru profile title', function (): void {
    $plain = F::staff(RoleName::Guru, 'Hendra Kusuma, S.Pd.');
    $registration = F::registration($this->nadia, RegistrationStatus::SuratTerbit, mentor: $plain);
    m2cLetters($registration, sent: true);

    $this->actingAs($this->nadia->user)
        ->get(route('siswa.lomba-saya.show', $registration))
        ->assertOk()
        ->assertSee('Untuk Hendra Kusuma, S.Pd.');
});
