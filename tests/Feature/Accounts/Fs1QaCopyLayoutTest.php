<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Akun\ImportSiswa;
use App\Models\Classroom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

uses(RefreshDatabase::class);

/*
 | FS1 QA leftovers (2026-10-13): QA-01 search placeholder without the comma, QA-02 the step 3 note on the initial
 | password says it is the same for every new student and must be changed at activation (never the value), QA-03
 | the Nama column of the import preview keeps a floor so a name does not wrap word by word. Fictional data only.
 */

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    Queue::fake();
    $this->withoutVite();
});

it('shows the search placeholder "Nama atau NISN lengkap" on Status aktivasi and Arsip otomatis (QA-01)', function (): void {
    $admin = A::admin();

    $this->actingAs(F::staff(RoleName::Kesiswaan))->get(route('kesiswaan.aktivasi.index'))
        ->assertOk()
        ->assertSee('placeholder="Nama atau NISN lengkap"', false)
        ->assertDontSee('Nama, atau');

    $this->actingAs($admin)->get(route('admin.arsip.index'))
        ->assertOk()
        ->assertSee('placeholder="Nama atau NISN lengkap"', false)
        ->assertDontSee('Nama, atau');
});

it('explains the initial password of new students without the pattern and keeps the Nama column wide (QA-02, QA-03)', function (): void {
    Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
    $file = UploadedFile::fake()->createWithContent('data-siswa.csv', implode("\n", [
        'Nama lengkap,NIS,NISN,Tanggal lahir,Kelas saat pendaftaran,No HP',
        'Aurelia Maharani Kusumawardani,226227031,0101234557,12-05-2011,X-1,',
    ])."\n");

    Livewire::actingAs(F::staff(RoleName::Kesiswaan))->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', $file)
        ->assertHasNoErrors()
        ->assertSee('Kata sandi awal hanya dibuat untuk siswa baru, sama untuk semua siswa baru dan wajib diganti saat aktivasi.')
        ->assertSee('Kata sandi siswa lama tidak berubah.')
        ->assertDontSee('pola sekolah')
        ->assertSeeHtml('<th scope="col" class="min-w-48">Nama</th>');
});
