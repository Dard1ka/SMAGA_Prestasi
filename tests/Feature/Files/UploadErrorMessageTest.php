<?php

declare(strict_types=1);

use App\Actions\Reports\SubmitReport;
use App\Enums\RoleName;
use App\Livewire\Akun\ImportSiswa;
use App\Livewire\Siswa\LombaSaya\LaporHasil;
use App\Livewire\Siswa\ProfilTalenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use Livewire\Livewire;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

uses(RefreshDatabase::class);

/*
 | SV1 browser check (2026-10-30): a failed upload said "FotoBaru gagal di-upload." or "bukti gagal di-upload.": the
 | name of the Livewire property, and no reason. Every upload field now has a readable name in lang/id/validation.php
 | and the sentence says what to do. A failure reaches the screen two ways (Livewire\WithFileUploads::_uploadErrored()):
 | an answer it cannot read (413 from nginx or PHP, no network) gives "validation.uploaded" with the field's name from
 | validation.attributes; a 422 of Livewire's temporary upload gives the messages of that request, which checked each
 | file as "files.N". Fictional data only.
 */

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();
});

function ui2UploadAdvice(): string
{
    return 'Periksa ukuran file (batasnya ada di bawah kolom), lalu coba lagi.';
}

/**
 * The attributes of every <x-sth.file-drop> opening tag in a Blade source. The tag ends at the first ">" outside a
 * quoted value, read the way Blade reads component attributes ("…" or '…'): a ">" inside a value (:file="$form->juknis")
 * does not end it.
 *
 * @return list<string>
 */
function ui2FileDropTags(string $source): array
{
    preg_match_all('/<x-sth\.file-drop(?![\w.\-])((?:"[^"]*"|\'[^\']*\'|[^"\'>])*+)>/', $source, $tags);

    return $tags[1];
}

/**
 * The wire:model of every <x-sth.file-drop> in a Blade source, wherever it stands among the attributes. A quoted value
 * is read as a whole, so text inside it (a hint that mentions wire:model) is never taken for an attribute.
 *
 * @return list<string>
 */
function ui2FileDropModels(string $source): array
{
    $models = [];

    foreach (ui2FileDropTags($source) as $attributes) {
        preg_match_all('/([\w:.@\-]+)\s*=\s*("[^"]*"|\'[^\']*\')|"[^"]*"|\'[^\']*\'/', $attributes, $pairs, PREG_SET_ORDER);

        foreach ($pairs as $pair) {
            if (preg_match('/^wire:model(?:\.[\w\-]+)*$/', $pair[1] ?? '') === 1) {
                $models[] = substr($pair[2], 1, -1);
            }
        }
    }

    return $models;
}

/** The wire:model of every <x-sth.file-drop> on a screen (Blade comments with examples left out). @return list<string> */
function ui2UploadFields(): array
{
    $fields = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        $text = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());

        // A tag the reader cannot close must fail here, not drop out of the list unnoticed.
        expect(ui2FileDropTags($text))->toHaveCount(preg_match_all('/<x-sth\.file-drop(?![\w.\-])/', $text), $file->getRelativePathname());

        array_push($fields, ...ui2FileDropModels($text));
    }

    return array_values(array_unique($fields));
}

it('reads the wire:model of a file-drop behind an attribute with "->", never from inside a quoted value', function (): void {
    $blade = <<<'BLADE'
        <x-sth.file-drop :file="$form->juknis" wire:model="form.juknis" label="Juknis / brosur / undangan" />
        <x-sth.file-drop :title="$cover ? 'Ganti > poster' : 'Pilih'" hint="Bukan wire:model='palsu' > juga" wire:model.live="form.cover"
            :optional="isset($existing['poster'])" />
        <x-sth.file-drop name="foto[]" multiple label="Tanpa wire:model" hint='wire:model="dikutip"' />
        <x-sth.file-drop-lain wire:model="bukanFileDrop" />
        BLADE;

    expect(ui2FileDropModels($blade))->toBe(['form.juknis', 'form.cover'])
        ->and(ui2FileDropTags($blade))->toHaveCount(3);
});

it('gives every upload field of a screen a readable name, as the failed upload looks it up', function (): void {
    $fields = ui2UploadFields();

    // The fields of the two messages the browser check found, and the nested one of the lomba form object.
    expect($fields)->toContain('fotoBaru', 'bukti', 'file', 'form.juknis', 'form.cover', 'signature');

    foreach ($fields as $field) {
        // _uploadErrored() asks the translator for "validation.attributes.<property>" and prints the property itself
        // when that key is missing.
        $label = trans("validation.attributes.{$field}");

        expect($label)->toBeString($field)
            ->not->toBe("validation.attributes.{$field}", $field)
            ->toMatch('/^[a-z][a-z\/ ]+$/', $field);
    }
});

it('names the field and says what to do when an upload is refused before it reaches the screen', function (): void {
    $nadia = F::student();
    $registration = F::registration($nadia);

    // "FotoBaru gagal di-upload." (Laporan hasil): the field's name now. The photo picker uploads several files at once,
    // so its own sentence asks for fewer photos (next test); the other fields of the form keep the general advice.
    $report = Livewire::actingAs($nadia->user)->test(LaporHasil::class, ['registration' => $registration])
        ->call('_uploadErrored', 'fotoBaru', null, true);
    expect($report->errors()->first('fotoBaru'))->toBe(LaporHasil::FOTO_UPLOAD_FAILED)
        ->toStartWith('Foto dokumentasi gagal di-upload. ');

    $report->call('_uploadErrored', 'sertifikat', null, false);
    expect($report->errors()->first('sertifikat'))->toBe('Sertifikat/piagam gagal di-upload. '.ui2UploadAdvice());

    // "bukti gagal di-upload." (Profil saya › Talenta saya).
    $talent = Livewire::actingAs($nadia->user)->test(ProfilTalenta::class)->call('_uploadErrored', 'bukti', null, false);
    expect($talent->errors()->first('bukti'))->toBe('Bukti dukung gagal di-upload. '.ui2UploadAdvice());

    // Import siswa: "file", not the old "berkas" next to the word "file".
    $import = Livewire::actingAs(F::staff(RoleName::Kesiswaan, 'Ibu Dwi Rahayu'))->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->call('_uploadErrored', 'file', null, false);
    expect($import->errors()->first('file'))->toBe('File gagal di-upload. '.ui2UploadAdvice());
});

it('asks for fewer photos at once when the photo picker\'s upload comes back without messages (SV1)', function (): void {
    $nadia = F::student();
    $report = Livewire::actingAs($nadia->user)->test(LaporHasil::class, ['registration' => F::registration($nadia)]);

    // "Tambah foto" posts every chosen photo in one request: photos under 5 MB each can still pass the request limit
    // (25 MB in production), and the 413 (or a lost connection, a 5xx) reaches the screen without messages.
    foreach ([null, 'Request Entity Too Large', '{"message":"Server Error."}', '{"message":"x","errors":[]}'] as $answer) {
        $report->call('_uploadErrored', 'fotoBaru', $answer, true)
            // The upload state of the picker is cleared as Livewire's own answer does.
            ->assertDispatched('upload:errored', name: 'fotoBaru');

        expect($report->errors()->toArray())->toBe(['fotoBaru' => [LaporHasil::FOTO_UPLOAD_FAILED]]);
    }

    expect(LaporHasil::FOTO_UPLOAD_FAILED)->toBe('Foto dokumentasi gagal di-upload. Pilih lebih sedikit foto sekaligus, maks. 5 MB per foto.')
        // The "5 MB per foto" of the sentence is the photo limit of SubmitReport.
        ->and(SubmitReport::rules('foto')->maxBytes())->toBe(5 * 1024 * 1024);

    // A 422 of Livewire's temporary upload keeps its own message, per photo ("files.1" = the second photo).
    $refused = (string) json_encode(['message' => 'File terlalu besar.', 'errors' => ['files.1' => ['File terlalu besar. '.ui2UploadAdvice()]]]);
    $report->call('_uploadErrored', 'fotoBaru', $refused, true)->assertDispatched('upload:errored', name: 'fotoBaru');
    expect($report->errors()->toArray())->toBe(['fotoBaru.1' => ['File terlalu besar. '.ui2UploadAdvice()]]);

    // The message stands under the picker (<x-sth.file-drop> reads the errors of fotoBaru).
    $report->call('_uploadErrored', 'fotoBaru', null, true)->assertSee(LaporHasil::FOTO_UPLOAD_FAILED);
});

it('answers a file over the limit of the temporary upload without a property name or a kilobyte number', function (): void {
    // The browser posts every file to Livewire's upload address first; that request checks "files.*" (12 MB).
    $response = $this->withHeaders(['Accept' => 'application/json'])
        ->post(app(GenerateSignedUploadUrl::class)->forLocal(), ['files' => [UploadedFile::fake()->create('foto-panggung.jpg', 13 * 1024)]])
        ->assertStatus(422);

    $message = $response->json('errors')['files.0'][0] ?? null;
    expect($message)->toBe('File terlalu besar. '.ui2UploadAdvice());

    // The screen puts that answer under the field: one file ("files.0") and several ("files") the same way.
    $kesiswaan = F::staff(RoleName::Kesiswaan, 'Ibu Dwi Rahayu');

    foreach ([false, true] as $multiple) {
        $screen = Livewire::actingAs($kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
            ->call('_uploadErrored', 'file', $response->getContent(), $multiple);
        $shown = collect($screen->errors()->toArray())->flatten()->all();

        expect($shown)->toBe(['File terlalu besar. '.ui2UploadAdvice()]);
    }
});

it('answers an upload PHP could not take with the same advice, without a property name', function (): void {
    // UPLOAD_ERR_INI_SIZE: the file was bigger than upload_max_filesize, so Laravel's "uploaded" check fails.
    $path = tempnam(sys_get_temp_dir(), 'sv1');
    file_put_contents($path, '%PDF-1.4');
    $broken = new UploadedFile($path, 'sertifikat-wice.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);

    $response = $this->withHeaders(['Accept' => 'application/json'])
        ->post(app(GenerateSignedUploadUrl::class)->forLocal(), ['files' => [$broken]])
        ->assertStatus(422);

    expect($response->json('errors')['files.0'][0] ?? null)->toBe('File gagal di-upload. '.ui2UploadAdvice());
    @unlink($path);
});
