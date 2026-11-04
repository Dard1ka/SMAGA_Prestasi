<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\RegistrationStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Livewire\Siswa\LombaSaya\LaporHasil;
use App\Models\File as StoredFile;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;
use Tests\Support\TestDisks;

/*
 * R3 (second browser check, Playwright, 2026-10-31) on the laporan hasil form:
 *  1. after one refused photo "Tambah foto" refused every later photo: the refused file stayed in the picker property
 *     (fotoBaru) and Livewire appends every later pick to it, so each check failed on the old file again;
 *  2. a pick of six photos kept five without a word;
 *  3. "Pilih hasil yang diraih." and the tick's message stayed after a result was picked or the tick was set;
 *  4. a refused file stood in a "Siap dikirim" row (with its error under it).
 * Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Livewire keeps test uploads on its own disk "tmp-for-tests", which it fakes in ONE fixed folder that a test run
    // starting at the same time wipes (Storage::fake()); these tests hold several uploads across requests, so they get
    // a folder of their own (TestDisks::cleanup() deletes it).
    TestDisks::isolate('tmp-for-tests');
    Notification::fake();
    F::boot();
    F::today('2026-10-01');
    $this->withoutVite();

    F::staff(RoleName::Kesiswaan, 'Ibu Dwi Rahayu');
    $this->nadia = F::student();
    // WICE is a riset lomba: every field of the form is on the screen.
    $this->wice = F::registration($this->nadia, members: [F::student('Dimas Arya Nugroho')]);
});

afterEach(fn () => F::resetToday());

/** 6 MB: over the 5 MB of a photo, under the 12 MB of Livewire's temporary upload, so the screen itself refuses it. */
function r3laporBigPhoto(string $name = 'foto-panggung.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, 640, 480)->size(6 * 1024);
}

/** @return list<string> the names of the photos the form holds, in order */
function r3laporPhotoNames(mixed $photos): array
{
    return array_map(fn (TemporaryUploadedFile $photo): string => $photo->getClientOriginalName(), (array) $photos);
}

it('takes a refused photo out of the picker at once, so the next photo is added (R3)', function (): void {
    $form = Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => true])
        ->set('fotoBaru', [r3laporBigPhoto()])
        ->assertCount('foto', 0)
        ->assertSet('fotoBaru', [])
        ->assertSee('Ukuran foto maksimal 5 MB. Tidak ditambahkan: foto-panggung.jpg.');

    expect($form->errors()->get('foto'))->toBe(['Ukuran foto maksimal 5 MB. Tidak ditambahkan: foto-panggung.jpg.']);

    // Livewire appends a new pick to the picker property: before, the refused photo was still in it and refused this one.
    $form->set('fotoBaru', [F::jpg('foto-podium.jpg')])
        ->assertCount('foto', 1)
        ->assertSet('fotoBaru', [])
        ->assertHasNoErrors()
        ->assertDontSee('Ukuran foto maksimal 5 MB.')
        ->assertSee('foto-podium.jpg');

    expect(r3laporPhotoNames($form->get('foto')))->toBe(['foto-podium.jpg']);
});

it('checks every photo of a pick on its own and names the refused ones per reason (R3)', function (): void {
    $form = Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => true])
        ->set('fotoBaru', [
            F::jpg('foto-1.jpg'),
            r3laporBigPhoto('foto-besar.jpg'),
            F::jpg('scan-piagam.gif'),
            F::jpg('foto-2.jpg'),
            r3laporBigPhoto('foto-besar-2.jpg'),
        ])
        ->assertSet('fotoBaru', []);

    $messages = [
        'Ukuran foto maksimal 5 MB. Tidak ditambahkan: foto-besar.jpg, foto-besar-2.jpg.',
        'Foto harus JPG, PNG, atau WEBP. Tidak ditambahkan: scan-piagam.gif.',
    ];

    expect(r3laporPhotoNames($form->get('foto')))->toBe(['foto-1.jpg', 'foto-2.jpg'])
        ->and($form->errors()->get('foto'))->toBe($messages);

    // Both messages stand under the photo list, each once.
    $form->assertSeeInOrder(['foto-2.jpg', ...$messages]);
    expect(substr_count($form->html(), 'Tidak ditambahkan:'))->toBe(2);
});

it('keeps the first five photos of a bigger pick and names the others (R3)', function (): void {
    $form = Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => true])
        ->set('fotoBaru', array_map(fn (int $i): UploadedFile => F::jpg("foto-{$i}.jpg"), range(1, 6)))
        ->assertSet('fotoBaru', []);

    expect(r3laporPhotoNames($form->get('foto')))->toBe(['foto-1.jpg', 'foto-2.jpg', 'foto-3.jpg', 'foto-4.jpg', 'foto-5.jpg'])
        ->and($form->errors()->get('foto'))->toBe(['Maksimal 5 foto. Tidak ditambahkan: foto-6.jpg.']);

    // The picker is gone at five photos; the message stays under the list.
    $form->assertDontSee('Tambah foto')
        ->assertSee('Maksimal 5 foto. Tidak ditambahkan: foto-6.jpg.');
});

it('fills only the places left, lets a refused photo take none and clears the messages with the next pick', function (): void {
    $form = Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => true])
        ->set('fotoBaru', array_map(fn (int $i): UploadedFile => F::jpg("foto-{$i}.jpg"), range(1, 3)))
        ->assertHasNoErrors()
        ->set('fotoBaru', [F::jpg('foto-4.jpg'), r3laporBigPhoto('foto-besar.jpg'), F::jpg('foto-5.jpg'), F::jpg('foto-6.jpg'), F::jpg('foto-7.jpg')]);

    expect(r3laporPhotoNames($form->get('foto')))->toBe(['foto-1.jpg', 'foto-2.jpg', 'foto-3.jpg', 'foto-4.jpg', 'foto-5.jpg'])
        // Why a photo could not be used comes first, then which good photos found no place.
        ->and($form->errors()->get('foto'))->toBe([
            'Ukuran foto maksimal 5 MB. Tidak ditambahkan: foto-besar.jpg.',
            'Maksimal 5 foto. Tidak ditambahkan: foto-6.jpg, foto-7.jpg.',
        ]);

    // A removed photo brings the picker back; the next pick replaces the messages of the last one.
    $form->call('removeFoto', 4)
        ->assertCount('foto', 4)
        ->assertSee('Tambah foto')
        ->set('fotoBaru', [F::jpg('foto-6.jpg')])
        ->assertCount('foto', 5)
        ->assertHasNoErrors()
        ->assertDontSee('Tidak ditambahkan:');
});

it('sends the photos the picker kept', function (): void {
    $o2sn = F::registration(F::student('Yoga Pratama', 'XII-5'), competition: F::competition(['name' => 'O2SN Kota Semarang 2026', 'bidang' => Bidang::Olahraga]));

    Livewire::actingAs($o2sn->leader->user)
        ->test(LaporHasil::class, ['registration' => $o2sn, 'inline' => true])
        ->set('hasil', ResultType::Juara1->value)
        ->set('sertifikat', F::pdf('piagam-o2sn.pdf'))
        ->set('fotoBaru', [r3laporBigPhoto('foto-besar.jpg')])
        ->set('fotoBaru', [F::jpg('foto-podium.jpg'), F::jpg('foto-tim.jpg')])
        ->set('asli', true)
        ->call('kirim')
        ->assertHasNoErrors()
        ->assertRedirect(route('siswa.lomba-saya.show', $o2sn));

    $report = Report::query()->where('registration_id', $o2sn->getKey())->firstOrFail();
    expect($report->files()->where('kind', 'foto')->count())->toBe(2)
        ->and($o2sn->fresh()->status)->toBe(RegistrationStatus::LaporanDikirim);
});

it('drops the message of a refused send once the field is right (R3)', function (): void {
    $form = Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => true])
        ->call('kirim')
        ->assertHasErrors(['hasil', 'asli', 'sertifikat', 'foto', 'judul', 'abstrak', 'laporan']);

    $form->set('hasil', ResultType::Juara2->value)
        ->assertHasNoErrors('hasil')
        // The other fields keep theirs until they are right.
        ->assertHasErrors(['asli', 'sertifikat', 'foto', 'judul', 'abstrak', 'laporan']);
    expect($form->html())->not->toContain('Pilih hasil yang diraih.');

    $form->set('asli', true)
        ->assertHasNoErrors(['hasil', 'asli'])
        ->assertHasErrors(['sertifikat', 'foto', 'judul', 'abstrak', 'laporan']);
    expect($form->html())->not->toContain('Centang pernyataan keaslian dokumen dulu.');

    $form->set('judul', 'Biofilter sabut kelapa untuk air sumur payau')
        ->set('abstrak', 'Biofilter berbahan sabut kelapa menurunkan salinitas air sumur rata-rata 38 persen.')
        ->assertHasNoErrors(['hasil', 'asli', 'judul', 'abstrak'])
        ->assertHasErrors(['sertifikat', 'foto', 'laporan']);
    expect($form->html())->not->toContain('Isi judul penelitian.')
        ->not->toContain('Isi abstrak penelitian.');
});

it('keeps the message for a wrong answer and judges nothing before the first send', function (): void {
    // Before a send nothing is judged while the form is filled in: the send names what is missing.
    Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => true])
        ->set('asli', true)
        ->set('asli', false)
        ->set('judul', '')
        ->assertHasNoErrors();

    $form = Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => true])
        ->call('kirim')
        // A value the tiles never offer (a forged request) keeps a message under the tiles.
        ->set('hasil', 'juara-umum')
        ->assertHasErrors(['hasil', 'asli']);

    $form->set('hasil', ResultType::Finalis->value)->assertHasNoErrors('hasil')
        ->set('asli', true)->assertHasNoErrors('asli')
        // Unticked again: no new message until the next send asks for it.
        ->set('asli', false)->assertHasNoErrors('asli')
        ->call('kirim')
        ->assertHasErrors(['asli']);
    expect($form->errors()->get('asli'))->toBe(['Centang pernyataan keaslian dokumen dulu.']);
});

it('sends the tick at once, so its message can go when it is set', function (bool $inline): void {
    $html = Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => $inline])
        ->html();

    expect($html)->toContain('wire:model.live="asli"')
        ->and($html)->toContain('wire:model.live="hasil"');
})->with(['inline (Lomba saya)' => [true], 'full page' => [false]]);

it('never shows a refused file as ready to send: its drop zone comes back with the reason (R3)', function (string $field, string $name, int $kilobytes, string $message): void {
    $form = Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => true])
        ->set($field, UploadedFile::fake()->create($name, $kilobytes))
        ->assertSet($field, null)
        ->assertDontSee('Siap dikirim')
        ->assertDontSee($name);

    expect($form->errors()->get($field))->toBe([$message])
        // <x-sth.file-drop> prints the message under the drop zone of that field and ties it to its input.
        ->and($form->html())->toContain('<p id="lapor-form-inline-'.$field.'-error" class="field-error">')
        ->and($form->html())->toContain('aria-describedby="lapor-form-inline-'.$field.'-hint lapor-form-inline-'.$field.'-error"');

    // A file that passes takes the drop zone's place, ready to send, and the message goes.
    $form->set($field, F::pdf("{$field}-wice.pdf"))
        ->assertHasNoErrors($field)
        ->assertSee("{$field}-wice.pdf")
        ->assertSee('Siap dikirim');
})->with([
    'sertifikat over 5 MB' => ['sertifikat', 'piagam-wice.pdf', 6 * 1024, 'Ukuran file maksimal 5 MB.'],
    'pengumuman of another type' => ['pengumuman', 'pengumuman-juara.docx', 40, 'Pakai PDF, JPG, PNG, atau WEBP.'],
    'laporan not a PDF' => ['laporan', 'laporan-penelitian.docx', 200, 'Laporan harus PDF.'],
    'artikel over 10 MB' => ['artikel', 'artikel-ilmiah.pdf', 11 * 1024, 'Ukuran file maksimal 10 MB.'],
    'poster of another type' => ['poster', 'poster-riset.svg', 30, 'Pakai PDF, JPG, PNG, atau WEBP.'],
]);

it('keeps the other messages of the form when a picked file is refused', function (): void {
    $form = Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => true])
        ->call('kirim')
        ->set('sertifikat', UploadedFile::fake()->create('piagam-wice.pdf', 6 * 1024))
        ->assertSet('sertifikat', null)
        ->assertHasErrors(['hasil', 'asli', 'foto', 'judul', 'abstrak', 'laporan']);

    expect($form->errors()->get('sertifikat'))->toBe(['Ukuran file maksimal 5 MB.']);
});

it('drops a single file the send refused for its content, so its drop zone comes back with the reason', function (): void {
    $o2sn = F::registration(F::student('Yoga Pratama', 'XII-5'), competition: F::competition(['name' => 'O2SN Kota Semarang 2026', 'bidang' => Bidang::Olahraga]));

    $form = Livewire::actingAs($o2sn->leader->user)
        ->test(LaporHasil::class, ['registration' => $o2sn, 'inline' => true])
        ->set('hasil', ResultType::Juara1->value)
        // A PDF by its name only: the screen's rules let it through, the content check in the Action (StoreUpload) refuses it.
        ->set('sertifikat', UploadedFile::fake()->createWithContent('piagam-o2sn.pdf', 'Bukan isi PDF.'))
        ->assertSee('piagam-o2sn.pdf')
        ->set('fotoBaru', [F::jpg('foto-podium.jpg')])
        ->set('asli', true)
        ->call('kirim')
        ->assertHasErrors(['sertifikat'])
        ->assertSet('sertifikat', null)
        ->assertCount('foto', 1)
        ->assertNoRedirect();

    $html = $form->html();
    expect($html)->toContain('<p id="lapor-form-inline-sertifikat-error" class="field-error">')
        ->and($html)->not->toContain('piagam-o2sn.pdf')
        // The one row left is the photo, which the send did not refuse.
        ->and(substr_count($html, 'Siap dikirim'))->toBe(1)
        ->and(Report::query()->where('registration_id', $o2sn->getKey())->exists())->toBeFalse();
});

/*
 * Review of r3 (2026-11-01): the messages of the photos stood in plain paragraphs without an id or a live region, so a
 * screen reader heard nothing after a pick; a 422 of the picker's upload did not say that no photo of the pick was added;
 * a picked tile judged away a refusal of SubmitReport under "hasil" that was not about the result; the size of a chosen
 * file had its own rounding ("2 MB").
 */

/** The text inside the live region of the photo messages, Livewire's morph markers left out. */
function r3laporPhotoMessages(string $html, string $form = LaporHasil::FORM_INLINE): string
{
    expect(preg_match('~<div id="'.$form.'-foto-pesan" aria-live="polite" class="([^"]*)">(.*?)</div>~s', $html, $match))->toBe(1);

    return trim((string) preg_replace('/<!--.*?-->/s', '', $match[2]));
}

/** The opening tag of the photo picker's <input>. */
function r3laporPhotoInput(string $html, string $form = LaporHasil::FORM_INLINE): string
{
    expect(preg_match('~<input\b[^>]*\bid="'.$form.'-foto"[^>]*>~', $html, $match))->toBe(1);

    return $match[0];
}

/** The opening tag of the photo picker's visible drop zone (the <label class="drop"> around the input). */
function r3laporPhotoZone(string $html, string $form = LaporHasil::FORM_INLINE): string
{
    expect(preg_match('~<label for="'.$form.'-foto" class="drop"[^>]*>~', $html, $match))->toBe(1);

    return $match[0];
}

it('puts the messages of the photos in a live region the picker points at (r3 review)', function (bool $inline, string $form): void {
    $component = Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => $inline]);

    // Always on the page (a live region added together with its text is often not read); empty, it takes back the gap.
    $html = $component->html();
    expect(r3laporPhotoMessages($html, $form))->toBe('')
        ->and($html)->toContain('<div id="'.$form.'-foto-pesan" aria-live="polite" class="flex flex-col gap-2 -mt-2">')
        ->and(r3laporPhotoInput($html, $form))->not->toContain('aria-invalid')
        ->not->toContain('aria-errormessage')
        ->toContain('aria-describedby="'.$form.'-foto-hint"')
        ->and(r3laporPhotoZone($html, $form))->not->toContain('aria-invalid');

    $component->set('fotoBaru', [F::jpg('foto-podium.jpg'), r3laporBigPhoto()]);
    $html = $component->html();
    $input = r3laporPhotoInput($html, $form);

    expect(r3laporPhotoMessages($html, $form))->toContain('Ukuran foto maksimal 5 MB. Tidak ditambahkan: foto-panggung.jpg.')
        ->and($html)->toContain('<div id="'.$form.'-foto-pesan" aria-live="polite" class="flex flex-col gap-2">')
        ->and($input)->toContain('aria-invalid="true"')
        ->and($input)->toContain('aria-errormessage="'.$form.'-foto-pesan"')
        // VoiceOver does not read aria-errormessage: <x-sth.file-drop> merges the messages after its own hint, in ONE
        // attribute, never printed twice.
        ->and($input)->toContain('aria-describedby="'.$form.'-foto-hint '.$form.'-foto-pesan"')
        ->and(substr_count($input, 'aria-invalid='))->toBe(1)
        ->and(substr_count($input, 'aria-describedby='))->toBe(1)
        // The visible zone is marked too, so its red border shows (.drop[aria-invalid='true']).
        ->and(r3laporPhotoZone($html, $form))->toContain('aria-invalid="true"')
        ->and(substr_count(r3laporPhotoZone($html, $form), 'aria-invalid='))->toBe(1);

    // The next good pick clears the message, the mark and the link to the empty region.
    $component->set('fotoBaru', [F::jpg('foto-tim.jpg')]);
    $html = $component->html();
    expect(r3laporPhotoMessages($html, $form))->toBe('')
        ->and(r3laporPhotoInput($html, $form))->not->toContain('aria-invalid')
        ->toContain('aria-describedby="'.$form.'-foto-hint"')
        ->not->toContain('-foto-pesan')
        ->and(r3laporPhotoZone($html, $form))->not->toContain('aria-invalid');
})->with([
    'inline (Lomba saya)' => [true, LaporHasil::FORM_INLINE],
    'full page' => [false, LaporHasil::FORM_PAGE],
]);

it('keeps the photo messages in the live region when the picker is gone, and marks the picker for a refused send (r3 review)', function (): void {
    $component = Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => true])
        ->set('fotoBaru', array_map(fn (int $i): UploadedFile => F::jpg("foto-{$i}.jpg"), range(1, 6)))
        ->assertDontSee('Tambah foto');

    expect(r3laporPhotoMessages($component->html()))->toContain('Maksimal 5 foto. Tidak ditambahkan: foto-6.jpg.');

    // A send without any photo: its message stands in the same place and the picker is marked.
    $empty = Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => true])
        ->call('kirim')
        ->assertHasErrors(['foto']);

    expect(r3laporPhotoMessages($empty->html()))->toContain('Tambahkan minimal 1 foto dokumentasi.')
        ->and(r3laporPhotoInput($empty->html()))->toContain('aria-invalid="true"')
        ->toContain('aria-describedby="lapor-form-inline-foto-hint lapor-form-inline-foto-pesan"')
        ->and(r3laporPhotoZone($empty->html()))->toContain('aria-invalid="true"');
});

it('says that no photo of the pick was added when Livewire refused the upload of the pick (r3 review)', function (): void {
    $component = Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => true])
        ->set('fotoBaru', [F::jpg('foto-podium.jpg')]);

    // One photo of the next pick is over the limit of Livewire's temporary upload: the whole request answers 422.
    $tooBig = 'File terlalu besar. Periksa ukuran file (batasnya ada di bawah kolom), lalu coba lagi.';
    $refused = (string) json_encode(['message' => 'File terlalu besar.', 'errors' => ['files.1' => [$tooBig]]]);
    $component->call('_uploadErrored', 'fotoBaru', $refused, true);
    $html = $component->html();
    $input = r3laporPhotoInput($html);

    expect(LaporHasil::FOTO_PICK_NOT_ADDED)->toBe('Tidak ada foto dari pilihan ini yang ditambahkan.')
        // Livewire's own message stays under the picker (UploadErrorMessageTest); the sentence stands under the list.
        ->and($component->errors()->toArray())->toBe(['fotoBaru.1' => [$tooBig]])
        ->and(r3laporPhotoMessages($html))->toContain(LaporHasil::FOTO_PICK_NOT_ADDED)
        ->and(substr_count($html, LaporHasil::FOTO_PICK_NOT_ADDED))->toBe(1)
        ->and(r3laporPhotoNames($component->get('foto')))->toBe(['foto-podium.jpg'])
        // <x-sth.file-drop> marks the picker for its own message; the screen adds no second mark.
        ->and(substr_count($input, 'aria-invalid='))->toBe(1)
        ->and($input)->not->toContain('aria-errormessage')
        // The input reads its own message under the picker and the sentence under the list.
        ->and($input)->toContain('aria-describedby="lapor-form-inline-foto-hint lapor-form-inline-foto-error lapor-form-inline-foto-pesan"')
        ->and(r3laporPhotoZone($html))->toContain('aria-invalid="true"');

    // An answer without messages (413, no network) has its own sentence, which says it by itself.
    $component->call('_uploadErrored', 'fotoBaru', null, true);
    expect($component->html())->toContain(LaporHasil::FOTO_UPLOAD_FAILED)
        ->not->toContain(LaporHasil::FOTO_PICK_NOT_ADDED);

    // The next pick ends it.
    $component->call('_uploadErrored', 'fotoBaru', $refused, true)
        ->set('fotoBaru', [F::jpg('foto-tim.jpg')]);
    expect($component->html())->not->toContain(LaporHasil::FOTO_PICK_NOT_ADDED)
        ->and(r3laporPhotoNames($component->get('foto')))->toBe(['foto-podium.jpg', 'foto-tim.jpg']);
});

it('keeps a refusal of SubmitReport under the result when another tile is picked (r3 review)', function (): void {
    $o2sn = F::registration(F::student('Yoga Pratama', 'XII-5'), competition: F::competition(['name' => 'O2SN Kota Semarang 2026', 'bidang' => Bidang::Olahraga]));
    $race = true;

    // Another tab sends the report while this one stores its files: the screen's and the Action's policy checks passed,
    // SubmitReport finds the registration moved on under its lock.
    StoredFile::created(function () use (&$race, $o2sn): void {
        if ($race) {
            DB::table('registrations')->where('id', $o2sn->getKey())->update(['status' => RegistrationStatus::LaporanDikirim->value]);
        }
    });

    $form = Livewire::actingAs($o2sn->leader->user)
        ->test(LaporHasil::class, ['registration' => $o2sn, 'inline' => true])
        ->set('hasil', ResultType::Juara1->value)
        ->set('sertifikat', F::pdf('piagam-o2sn.pdf'))
        ->set('fotoBaru', [F::jpg('foto-podium.jpg')])
        ->set('asli', true)
        ->call('kirim')
        ->assertNoRedirect()
        ->assertSet('hasilRefusedBySend', true);

    expect($form->errors()->get('hasil'))->toBe(['Laporan untuk lomba ini tidak bisa dikirim sekarang.']);

    // Before, the new tile passed the rule of "hasil" and took the refusal away.
    $form->set('hasil', ResultType::Juara2->value)
        ->assertHasErrors(['hasil']);
    expect($form->errors()->get('hasil'))->toBe(['Laporan untuk lomba ini tidak bisa dikirim sekarang.'])
        ->and($form->html())->toContain('Laporan untuk lomba ini tidak bisa dikirim sekarang.');

    // The next send decides again: here its own rules refuse it, and their messages go as usual once right.
    $race = false;
    DB::table('registrations')->where('id', $o2sn->getKey())->update(['status' => RegistrationStatus::MenungguLaporan->value]);
    $form->set('asli', false)
        ->call('kirim')
        ->assertSet('hasilRefusedBySend', false)
        ->assertHasErrors(['asli'])
        ->assertHasNoErrors('hasil')
        ->set('asli', true)
        ->assertHasNoErrors('asli');
});

it('prints the size of a chosen file as every screen does, with a decimal comma (r3 review)', function (): void {
    // 2 MB and 1.023 KB: the row printed "2 MB" and "1023 KB" with its own rounding before (App\Support\FileSizeLabel now).
    Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => true])
        ->set('sertifikat', UploadedFile::fake()->create('piagam-wice.pdf', 2048))
        ->assertSee('Siap dikirim · 2,0 MB')
        ->set('pengumuman', UploadedFile::fake()->create('pengumuman-juara.pdf', 1023))
        ->assertSee('Siap dikirim · 1.023 KB');
});

/*
 * Third browser check (2026-11-01): after "Hapus foto" the message of the earlier pick ("Maksimal 5 foto. Tidak
 * ditambahkan: …") and the red, aria-invalid picker stayed next to four photos. removeFoto() now takes away the same
 * messages a new pick does; the next send names anything still missing.
 */
it('takes the photo messages and the red picker away with "Hapus foto" (third browser check)', function (): void {
    $component = Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => true])
        ->set('fotoBaru', array_map(fn (int $i): UploadedFile => F::jpg("foto-{$i}.jpg"), range(1, 6)));

    expect($component->errors()->get('foto'))->toBe(['Maksimal 5 foto. Tidak ditambahkan: foto-6.jpg.']);

    $component->call('removeFoto', 4)
        ->assertCount('foto', 4)
        ->assertHasNoErrors(['foto', 'foto.*', 'fotoBaru', 'fotoBaru.*']);

    $html = $component->html();
    expect(r3laporPhotoMessages($html))->toBe('')
        ->and($html)->not->toContain('Tidak ditambahkan:')
        ->and(r3laporPhotoInput($html))->not->toContain('aria-invalid')
        ->not->toContain('-foto-pesan')
        ->and(r3laporPhotoZone($html))->not->toContain('aria-invalid');

    // A size message of the pick goes the same way.
    $component->set('fotoBaru', [r3laporBigPhoto()]);
    expect($component->errors()->get('foto'))->toBe(['Ukuran foto maksimal 5 MB. Tidak ditambahkan: foto-panggung.jpg.']);

    $component->call('removeFoto', 0)
        ->assertCount('foto', 3)
        ->assertHasNoErrors(['foto']);
    expect(r3laporPhotoMessages($component->html()))->toBe('');
});

it('lets the next send name a photo that is still missing after "Hapus foto"', function (): void {
    $component = Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => true])
        ->set('fotoBaru', [F::jpg('foto-podium.jpg')])
        ->call('removeFoto', 0)
        ->assertCount('foto', 0)
        ->assertHasNoErrors(['foto'])
        ->call('kirim')
        ->assertHasErrors(['foto']);

    expect(r3laporPhotoMessages($component->html()))->toContain('Tambahkan minimal 1 foto dokumentasi.');
});
