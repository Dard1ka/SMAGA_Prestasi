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
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
 | D-128 (lead, 2026-10-10), sweep: Import siswa › "Impor N siswa" asks in the app's own dialog instead of the browser's
 | confirm(). askImport() runs the checks of the import (file, preview, a password confirmation from the last 15
 | minutes) and opens the dialog "impor-siswa", which says what the import writes; import() is the confirm step and
 | does nothing without that question. Fictional data only; the initial password pattern is never shown.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Queue::fake();
    $this->withoutVite();
    Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
});

function d128ImportFile(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('data-siswa.csv', implode("\n", [
        'Nama lengkap,NIS,NISN,Tanggal lahir,Kelas saat pendaftaran,No HP',
        'Aurelia Maharani,226227031,0101234557,12-05-2011,X-1,',
        'Hana Safira,226227088,0103456714,03-01-2011,X-1,',
    ])."\n");
}

it('asks in its own dialog what the import writes, and writes nothing yet (D-128)', function (): void {
    session(A::passwordConfirmed());

    Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        // The dialog is on the page with its description from the first render.
        ->assertSeeHtml('aria-describedby="impor-siswa-desc"')
        ->set('schoolYear', '2026/2027')
        ->set('file', d128ImportFile())
        ->assertHasNoErrors()
        ->assertSeeHtml('wire:click="askImport"')
        ->assertDontSeeHtml('wire:confirm')
        // Nothing is asked yet: the sentence and the confirm button are not in the dialog.
        ->assertDontSee('Buat 2 akun siswa.')
        ->assertDontSeeHtml('wire:click="import"')
        ->call('askImport')
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertDispatched('sth-modal-open', id: 'impor-siswa')
        ->assertViewHas('importAsking', true)
        ->assertSee('Impor data siswa?')
        ->assertSee('Buat 2 akun siswa.')
        ->assertSeeHtml('wire:click="import"')
        ->assertDontSee('sekarang?')
        ->assertDontSee('sm4g4');

    expect(Student::query()->count())->toBe(0);
});

it('imports after the confirmation and closes the dialog (D-128)', function (): void {
    session(A::passwordConfirmed());

    Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', d128ImportFile())
        ->call('askImport')
        ->call('import')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-close', id: 'impor-siswa')
        ->assertSet('importAsked', null)
        ->assertDispatched('sth-toast', message: '2 akun siswa dibuat.', tone: 'success')
        ->assertSee('2 akun siswa dibuat');

    expect(Student::query()->count())->toBe(2);
});

// Forbidden cases ----------------------------------------------------------------------------------------------------

it('writes nothing when the confirmation arrives without the question, and shows no error (D-128)', function (): void {
    session(A::passwordConfirmed());

    Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', d128ImportFile())
        ->call('import')
        ->assertOk()
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertNotDispatched('sth-toast')
        ->assertSet('imported', null);

    expect(Student::query()->count())->toBe(0);
});

it('imports once when "Impor" is pressed twice in the dialog (D-128)', function (): void {
    session(A::passwordConfirmed());

    Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', d128ImportFile())
        ->call('askImport')
        ->call('import')
        ->call('import')
        ->assertOk()
        ->assertHasNoErrors()
        ->assertNotDispatched('sth-toast')
        ->assertSee('2 akun siswa dibuat');

    expect(Student::query()->count())->toBe(2);
});

it('voids the question when another file or school year is read (D-128)', function (string $change): void {
    session(A::passwordConfirmed());

    $component = Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', d128ImportFile())
        ->call('askImport')
        ->assertViewHas('importAsking', true);

    match ($change) {
        'school year' => $component->set('schoolYear', '2025/2026'),
        'file' => $component->set('file', d128ImportFile()),
        'start over' => $component->call('startOver'),
    };

    $component->assertSet('importAsked', null)
        ->assertViewHas('importAsking', false)
        ->call('import')
        ->assertOk()
        ->assertSet('imported', null);

    expect(Student::query()->count())->toBe(0);
})->with(['school year', 'file', 'start over']);

it('keeps the dialog state locked: the browser cannot claim a question (D-128)', function (): void {
    session(A::passwordConfirmed());

    $component = Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', d128ImportFile());

    expect(fn () => $component->set('importAsked', $component->get('previewToken')))->toThrow(Exception::class)
        ->and(Student::query()->count())->toBe(0);
});

it('opens no dialog without a file to import (D-128)', function (): void {
    session(A::passwordConfirmed());

    Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->call('askImport')
        ->assertHasErrors(['file'])
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('importAsked', null);
});

it('asks for the password before the dialog, never after the answer in it (D-128, QA9)', function (): void {
    // No confirmation in the last 15 minutes: the question would be followed by the password page, which drops the file.
    Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', d128ImportFile())
        ->call('askImport')
        ->assertRedirect(route('password.confirm'))
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('importAsked', null);

    expect(Student::query()->count())->toBe(0);
});

it('still asks for the password in the confirm step when the confirmation ran out while the dialog was open (D-128, QA9)', function (): void {
    session(A::passwordConfirmed());

    $component = Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', d128ImportFile())
        ->call('askImport')
        ->assertDispatched('sth-modal-open', id: 'impor-siswa');

    session()->forget('auth.password_confirmed_at');

    $component->call('import')->assertRedirect(route('password.confirm'));

    expect(Student::query()->count())->toBe(0);
});

it('keeps every other role away from the import and its question (D-128)', function (): void {
    foreach ([RoleName::Guru, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas] as $role) {
        Livewire::actingAs(F::staff($role, 'Staf '.$role->value))->test(ImportSiswa::class)->assertForbidden();
    }

    Livewire::actingAs(F::student('Raka Pradipta', 'XII-2')->user)->test(ImportSiswa::class)->assertForbidden();
});

it('lists the NISN, not the NIS, in the preview and in "baris perlu dicek" (D-137)', function (): void {
    session(A::passwordConfirmed());
    $file = UploadedFile::fake()->createWithContent('data-siswa.csv', implode("\n", [
        'Nama lengkap,NIS,NISN,Tanggal lahir,Kelas saat pendaftaran,No HP',
        'Aurelia Maharani,226227031,0101234557,12-05-2011,XIII-9,',
    ])."\n");

    $component = Livewire::actingAs($this->kesiswaan)->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')->set('file', $file)
        ->assertSeeHtml('<th scope="col">NISN</th>')
        ->assertSee('0101234557')->assertDontSee('226227031');

    $path = tempnam(sys_get_temp_dir(), 'sth').'.xlsx';
    file_put_contents($path, base64_decode((string) $component->call('downloadInvalid')->effects['download']['content']));
    expect(SimpleExcelReader::create($path)->getHeaders())->toBe(['Baris', 'Nama lengkap', 'NISN', 'Kelas', 'Status', 'Keterangan']);
    @unlink($path);
});
