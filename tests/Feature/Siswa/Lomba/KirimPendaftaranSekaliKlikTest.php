<?php

declare(strict_types=1);

use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Livewire\Siswa\Pendaftaran\Form as PendaftaranForm;
use App\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * FS1 QA-04: the first click on "Kirim pendaftaran" right after ticking "Data yang saya isi benar." did nothing. The
 * button was disabled until the round trip of the tick came back, so a second click was needed. The button is never
 * disabled by the form state now: the click sends the tick with it, and the server answers a missing tick (or a team
 * without anggota) with the error under the field. The same panel is step 2 of Usulkan lomba. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->guru = F::staff(RoleName::Guru);
    $this->nadia = F::student();
    $this->nadia->forceFill(['phone' => '081211112222'])->save();
    $this->nadiaUser = F::user($this->nadia);
});

/** The opening tag of the panel's submit button. */
function qa04SubmitTag(Testable $form): string
{
    expect(preg_match('/<button type="submit"[^>]*>/', $form->html(), $match))->toBe(1);

    return $match[0];
}

/** The tick and the submit in one request, as the browser sends them when the click follows the tick at once. */
function qa04TickAndSend(Testable $form): Testable
{
    return $form->update(calls: [['method' => 'kirim', 'params' => [], 'path' => '']], updates: ['setuju' => true]);
}

it('keeps "Kirim pendaftaran" clickable before the tick and for a team without anggota', function (): void {
    $form = Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => F::teamCompetition()->getKey()])
        ->assertSet('setuju', false);

    expect(qa04SubmitTag($form))->not->toMatch('/\sdisabled[\s=>]/');

    $form->set('type', 'tim');

    expect(qa04SubmitTag($form))->not->toMatch('/\sdisabled[\s=>]/');
    $form->assertSee('Tambahkan minimal 1 anggota tim.');
});

it('registers with one click that carries the tick', function (): void {
    $form = Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => F::openCompetition()->getKey()])
        ->set('mentorId', $this->guru->getKey());

    qa04TickAndSend($form)->assertHasNoErrors()->assertDispatched('sth-toast', message: 'Pendaftaran terkirim.', tone: 'success');

    expect(Registration::query()->sole()->status)->toBe(RegistrationStatus::MenungguKepsek);
});

it('answers a click without the tick, or a team without anggota, with the error under the field', function (): void {
    $form = Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => F::teamCompetition()->getKey()])
        ->set('mentorId', $this->guru->getKey());

    $form->call('kirim')->assertHasErrors(['setuju' => 'Centang dulu pernyataan data benar.'])
        ->assertSee('Centang dulu pernyataan data benar.');

    $form->set('type', 'tim');
    qa04TickAndSend($form)->assertHasErrors(['members' => 'Tambahkan minimal 1 anggota tim.']);

    expect(Registration::query()->exists())->toBeFalse();
});

it('sends the panel of step 2 of Usulkan lomba with one click too', function (): void {
    $lomba = F::pendingCompetition($this->nadiaUser, F::usulanAttributes());
    $form = Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => $lomba->getKey()])
        ->set('mentorId', $this->guru->getKey());

    expect(qa04SubmitTag($form))->not->toMatch('/\sdisabled[\s=>]/');

    qa04TickAndSend($form)->assertHasNoErrors();

    expect(Registration::query()->sole()->status)->toBe(RegistrationStatus::MenungguLomba);
});
