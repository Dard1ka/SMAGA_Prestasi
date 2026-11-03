<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\RegistrationStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Livewire\Siswa\LombaSaya\LaporHasil;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

/*
 * SV1 (browser check of 2026-10-30): the laporan hasil form showed "Pilih hasil yang diraih." twice, under the result
 * tiles (<x-sth.radio-pick> prints the error of its field) and again beside the authenticity tick. It stands once now,
 * under its field; the tick keeps only its own message.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    F::boot();
    F::today('2026-10-01');
    $this->withoutVite();

    F::staff(RoleName::Kesiswaan, 'Ibu Dwi Rahayu');
    $this->nadia = F::student();
    $this->wice = F::registration($this->nadia, members: [F::student('Dimas Arya Nugroho')]);
});

afterEach(fn () => F::resetToday());

it('shows "Pilih hasil yang diraih." once, under the result tiles (SV1)', function (bool $inline): void {
    $form = Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => $inline])
        ->call('kirim')
        ->assertHasErrors(['hasil', 'asli']);

    $html = $form->html();

    expect(substr_count($html, 'Pilih hasil yang diraih.'))->toBe(1)
        // The one message is the radio-pick's own, tied to its fieldset (aria-describedby).
        ->and($html)->toMatch('~<p id="f-hasil-error" class="field-error mt-2">.*?Pilih hasil yang diraih\.</p>~s')
        ->and($html)->toContain('aria-describedby="f-hasil-error"')
        // The tick next to the send button keeps its own message, once.
        ->and(substr_count($html, 'Centang pernyataan keaslian dokumen dulu.'))->toBe(1);
})->with(['inline (Lomba saya)' => [true], 'full page' => [false]]);

it('drops the message once a result is picked', function (): void {
    $form = Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => true])
        ->call('kirim')
        ->set('hasil', ResultType::Juara2->value)
        ->call('kirim')
        ->assertHasNoErrors('hasil');

    expect($form->html())->not->toContain('Pilih hasil yang diraih.');
});

/*
 * SV1: with the message only under the result tiles, a click on "Kirim laporan" at the end of the form without a result
 * looked dead: the error stood far above the button. A refused send now asks app.js (sth-form-invalid) to bring the first
 * marked field of the form on the screen into view.
 */
it('brings the first problem of the form in use into view when the send is refused (SV1)', function (bool $inline, string $form): void {
    $component = Livewire::actingAs($this->nadia->user)
        ->test(LaporHasil::class, ['registration' => $this->wice, 'inline' => $inline])
        ->call('kirim')
        ->assertHasErrors(['hasil', 'asli'])
        ->assertDispatched('sth-form-invalid', form: $form);

    // app.js looks the id up: it is the id of the form the screen draws, and the result tiles in it are marked.
    expect($component->html())->toContain('<form id="'.$form.'" wire:submit="kirim"')
        ->toContain('aria-invalid="true"');
})->with([
    'inline (Lomba saya)' => [true, LaporHasil::FORM_INLINE],
    'full page' => [false, LaporHasil::FORM_PAGE],
]);

it('keeps the form ids app.js scrolls in', function (): void {
    expect(LaporHasil::FORM_INLINE)->toBe('lapor-form-inline')
        ->and(LaporHasil::FORM_PAGE)->toBe('lapor-form');
});

it('brings a refusal of SubmitReport into view too, and asks nothing after a sent report', function (): void {
    $o2sn = F::registration(F::student('Yoga Pratama', 'XII-E'), competition: F::competition(['name' => 'O2SN Kota Semarang 2026', 'bidang' => Bidang::Olahraga]));

    $component = Livewire::actingAs($o2sn->leader->user)
        ->test(LaporHasil::class, ['registration' => $o2sn, 'inline' => true])
        ->set('hasil', ResultType::Juara1->value)
        // A PDF by its name only: the screen's rules let it through, the content check in the Action (StoreUpload) refuses it.
        ->set('sertifikat', UploadedFile::fake()->createWithContent('piagam-o2sn.pdf', 'Bukan isi PDF.'))
        ->set('fotoBaru', [F::jpg('foto-podium.jpg')])
        ->set('asli', true)
        ->call('kirim')
        ->assertHasErrors(['sertifikat'])
        ->assertDispatched('sth-form-invalid', form: LaporHasil::FORM_INLINE)
        ->assertNoRedirect();

    expect(Report::query()->where('registration_id', $o2sn->getKey())->exists())->toBeFalse()
        ->and($o2sn->fresh()->status)->toBe(RegistrationStatus::MenungguLaporan);

    $component->call('removeFile', 'sertifikat')
        ->set('sertifikat', F::pdf('piagam-o2sn.pdf'))
        ->call('kirim')
        ->assertHasNoErrors()
        ->assertNotDispatched('sth-form-invalid')
        ->assertRedirect(route('siswa.lomba-saya.show', $o2sn));

    expect($o2sn->fresh()->status)->toBe(RegistrationStatus::LaporanDikirim);
});
