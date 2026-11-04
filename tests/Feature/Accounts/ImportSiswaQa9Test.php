<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Akun\ImportSiswa;
use App\Models\Classroom;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

uses(RefreshDatabase::class);

/*
 | QA9 (2026-10-09), item 4: an import writes accounts, so it needs a password confirmation from the last 15 minutes
 | (SECURITY §3m-9), for Kesiswaan and Admin alike. The preview (dry run) writes nothing and stays as it was.
 */

beforeEach(function (): void {
    F::boot();
    Queue::fake();
    $this->withoutVite();
    Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
});

function qa9ImportFile(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('data-siswa.csv', implode("\n", [
        'Nama lengkap,NIS,NISN,Tanggal lahir,Kelas saat pendaftaran,No HP',
        'Aurelia Maharani,226227031,0101234557,12-05-2011,X-1,',
        'Hana Safira,226227088,0103456714,03-01-2011,X-1,',
    ])."\n");
}

it('asks for the password again before an import writes anything (QA9)', function (RoleName $role): void {
    $actor = F::staff($role, $role === RoleName::Admin ? 'Operator STH' : 'Ibu Dwi Rahayu');

    Livewire::actingAs($actor)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', qa9ImportFile())
        ->assertHasNoErrors()
        ->assertSee('Impor 2 siswa')
        // D-128: the button asks first (askImport()); the password comes before that question (ImportSiswaDialogTest).
        ->call('askImport')
        ->assertRedirect(route('password.confirm'))
        ->assertNotDispatched('sth-modal-open');

    expect(Student::query()->count())->toBe(0);
})->with([RoleName::Kesiswaan, RoleName::Admin]);

it('imports once the password was confirmed in the last 15 minutes (QA9)', function (): void {
    session(A::passwordConfirmed());

    Livewire::actingAs(F::staff(RoleName::Kesiswaan))->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', qa9ImportFile())
        ->call('askImport')
        ->assertNoRedirect()
        ->call('import')
        ->assertNoRedirect()
        ->assertSee('2 akun siswa dibuat');

    expect(Student::query()->count())->toBe(2);
});

it('says before the import when the confirmation of step 2 is too old and the file is chosen again (QA9)', function (): void {
    // Only reachable when the file was uploaded without a recent confirmation (more than 15 minutes since step 2).
    $hint = 'Konfirmasi kata sandi sudah lebih dari 15 menit. Sebelum impor kata sandi diminta lagi, sesudahnya upload file ini lagi.';
    $kesiswaan = F::staff(RoleName::Kesiswaan);

    Livewire::actingAs($kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', qa9ImportFile())
        ->assertSee($hint);

    session(A::passwordConfirmed());

    Livewire::actingAs($kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', qa9ImportFile())
        ->assertDontSee($hint);
});

it('sends Kesiswaan and Admin back to their own import page after the password (QA9)', function (RoleName $role, string $route): void {
    $actor = F::staff($role, $role === RoleName::Admin ? 'Operator STH' : 'Ibu Dwi Rahayu');

    Livewire::actingAs($actor)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', qa9ImportFile())
        ->call('askImport');

    expect(session('url.intended'))->toBe(route($route));
})->with([
    'kesiswaan' => [RoleName::Kesiswaan, 'kesiswaan.import'],
    'admin' => [RoleName::Admin, 'admin.import'],
]);

it('asks for the password before the upload, so the file is chosen only once (QA9)', function (): void {
    // The first version asked when "Impor" was pressed: the password page dropped the uploaded file and its preview,
    // so the file had to be uploaded twice. Now step 2 asks first and shows the upload field afterwards.
    $kesiswaan = F::staff(RoleName::Kesiswaan);

    Livewire::actingAs($kesiswaan)->test(ImportSiswa::class)
        ->assertSee('Konfirmasi kata sandi')
        ->assertDontSee('Pilih file atau seret ke sini')
        ->call('askPassword')
        ->assertRedirect(route('password.confirm'));

    session(A::passwordConfirmed());

    Livewire::actingAs($kesiswaan)->test(ImportSiswa::class)
        ->assertSee('Pilih file atau seret ke sini')
        ->assertDontSee('Konfirmasi kata sandi')
        ->assertDontSee('upload file ini lagi');
});
