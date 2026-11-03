<?php

declare(strict_types=1);

use App\Actions\Competitions\UpdateCompetition;
use App\Actions\Competitions\VerifyCompetition;
use App\Enums\CompetitionStatus;
use App\Enums\EventStatus;
use App\Enums\OrganizerType;
use App\Enums\RoleName;
use App\Enums\WeightCategory;
use App\Livewire\Forms\CompetitionFields;
use App\Livewire\Kesiswaan\Lomba\Form;
use App\Models\Competition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;
use Tests\Feature\Kesiswaan\LombaChangeFixtures;

/*
| FS1 Paket B (D-146, D-147): the Kelola lomba drawer draws the shared CompetitionFields form. The jenis penyelenggara
| fills kategori bobot and status ajang from the map, which Kesiswaan may override (audited at the decision); "Durasi
| lomba" writes one date or two; the juknis takes PDF, JPG, PNG or WEBP. "Today" is fixed at 6 Oct 2026.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00:00'));
    F::boot();
    Notification::fake();
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
});

/** A complete queued lomba with fixed dates (registration closes 20 Oct, lomba 7 Nov 2026). */
function drawerQueued(array $attributes = []): Competition
{
    return Competition::factory()->create(array_merge([
        'name' => 'Lomba Karya Tulis Ilmiah Fiktif 2026',
        'registration_opens_at' => '2026-09-15 00:00:00',
        'registration_closes_at' => '2026-10-20 23:59:59',
        'starts_on' => '2026-11-07',
        'final_on' => '2026-11-07',
    ], $attributes))->fresh();
}

it('fills kategori and status ajang from the jenis penyelenggara and lets Kesiswaan override them', function (): void {
    $c = drawerQueued(['organizer_type' => null, 'weight_category' => null, 'event_status' => null]);

    Livewire::actingAs($this->kesiswaan)->test(Form::class)->call('open', $c->getKey())
        ->set('form.organizer_type', 'ptn')->assertSet('form.weight_category', 'C')->assertSet('form.event_status', 'non_pemerintah')
        ->assertSee('Kategori C · bobot 0,60')
        ->assertDontSee('Diubah dari hasil peta.')
        ->set('form.puspresnas', true)->assertSet('form.weight_category', 'A')->assertSet('form.event_status', 'puspresnas')
        ->set('form.weight_category', 'B')->assertSee('Diubah dari hasil peta.')
        // Another jenis penyelenggara takes the map again.
        ->set('form.puspresnas', false)->set('form.organizer_type', 'bumn_bumd')
        ->assertSet('form.weight_category', 'B')->assertSet('form.event_status', 'pemerintah_non_puspresnas')
        ->assertDontSee('Diubah dari hasil peta.');
});

it('reads the Puspresnas tick from the stored status ajang', function (): void {
    $c = drawerQueued(['organizer_type' => OrganizerType::Ptn, 'weight_category' => WeightCategory::A, 'event_status' => EventStatus::Puspresnas]);

    Livewire::actingAs($this->kesiswaan)->test(Form::class)->call('open', $c->getKey())
        ->assertSet('form.puspresnas', true)
        ->assertDontSee('Diubah dari hasil peta.');
});

it('writes one date for a one-day lomba and two for a longer one', function (): void {
    $c = drawerQueued();

    Livewire::actingAs($this->kesiswaan)->test(Form::class)->call('open', $c->getKey())
        ->assertSet('form.duration', CompetitionFields::DURATION_ONE_DAY)
        ->set('form.duration', CompetitionFields::DURATION_ONE_DAY)->set('form.final_on', '2026-11-08')->call('save')
        ->assertHasNoErrors();

    expect($c->fresh()->starts_on->toDateString())->toBe('2026-11-08')->and($c->fresh()->final_on->toDateString())->toBe('2026-11-08');

    Livewire::actingAs($this->kesiswaan)->test(Form::class)->call('open', $c->getKey())
        ->set('form.duration', CompetitionFields::DURATION_MULTI_DAY)->set('form.starts_on', '2026-11-08')->set('form.final_on', '2026-11-10')->call('save')
        ->assertHasNoErrors();

    expect($c->fresh()->starts_on->toDateString())->toBe('2026-11-08')->and($c->fresh()->final_on->toDateString())->toBe('2026-11-10');

    Livewire::actingAs($this->kesiswaan)->test(Form::class)->call('open', $c->getKey())
        ->assertSet('form.duration', CompetitionFields::DURATION_MULTI_DAY)
        ->assertSee('Tanggal mulai')->assertSee('Tanggal selesai');
});

it('puts the date-order error of a one-day lomba on its one date', function (): void {
    $c = drawerQueued();

    Livewire::actingAs($this->kesiswaan)->test(Form::class)->call('open', $c->getKey())
        ->set('form.final_on', '2026-10-10')->call('save')
        ->assertHasErrors('form.final_on')
        ->assertSee('Lomba tidak boleh dimulai sebelum pendaftaran ditutup.');

    expect($c->fresh()->final_on->toDateString())->toBe('2026-11-07');
});

it('shows the new labels and the report hint', function (): void {
    Livewire::actingAs($this->kesiswaan)->test(Form::class)->call('open', drawerQueued()->getKey())
        ->assertSee('Pendaftaran dibuka')->assertSee('Pendaftaran ditutup')->assertSee('Durasi lomba')
        ->assertSee('1 hari')->assertSee('Lebih dari 1 hari')->assertSee('Tanggal lomba')
        ->assertSee('Laporan hasil dibuka setelah tanggal ini.')->assertSee('Juknis / brosur / undangan')
        ->assertSee('Ajang Puspresnas')->assertSee('Perkiraan poin jika Juara I')
        ->assertDontSee('Dasar batas laporan hasil.')->assertDontSee('Tanggal final');
});

it('accepts a WEBP juknis in the drawer like everywhere else', function (): void {
    Livewire::actingAs($this->kesiswaan)->test(Form::class)->call('open', drawerQueued()->getKey())
        ->set('form.juknis', UploadedFile::fake()->image('undangan.webp', 600, 800))
        ->call('save')
        ->assertHasNoErrors('form.juknis');
});

it('refuses a juknis that is no PDF or picture under the field', function (): void {
    $c = drawerQueued();
    $before = $c->juknis_file_id;

    Livewire::actingAs($this->kesiswaan)->test(Form::class)->call('open', $c->getKey())
        ->set('form.juknis', UploadedFile::fake()->create('juknis.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
        ->call('save')
        ->assertHasErrors('form.juknis')
        ->assertSee('Pilih PDF, JPG, PNG, atau WEBP.')
        ->assertDispatched('sth-form-invalid', form: Form::DIALOG);

    expect($c->fresh()->juknis_file_id)->toBe($before);
});

it('audits a weight that differs from the map when the lomba is published', function (): void {
    // Map: PTN → C · non-pemerintah.
    $c = drawerQueued(['organizer_type' => OrganizerType::Ptn, 'weight_category' => WeightCategory::C, 'event_status' => EventStatus::NonPemerintah]);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $c, [...LombaChangeFixtures::input($c), 'weight_category' => 'B']);
    app(VerifyCompetition::class)->handle($this->kesiswaan, $c->fresh(), CompetitionStatus::Dipublikasikan, null, true);

    expect(Activity::query()->where('event', 'competition.published')->sole()->properties['weight_override'])
        ->toBe(['computed' => ['weight_category' => 'C', 'event_status' => 'non_pemerintah'], 'chosen' => ['weight_category' => 'B', 'event_status' => 'non_pemerintah']]);
});

it('audits the override on a rejection too', function (): void {
    $c = drawerQueued(['organizer_type' => OrganizerType::YayasanLembaga, 'weight_category' => WeightCategory::B, 'event_status' => EventStatus::NonPemerintah]);

    app(VerifyCompetition::class)->handle($this->kesiswaan, $c, CompetitionStatus::Ditolak);

    expect(Activity::query()->where('event', 'competition.rejected')->sole()->properties['weight_override'])
        ->toBe(['computed' => ['weight_category' => 'D', 'event_status' => 'non_pemerintah'], 'chosen' => ['weight_category' => 'B', 'event_status' => 'non_pemerintah']]);
});

it('audits no override when the weight follows the map', function (): void {
    $c = drawerQueued(['organizer_type' => OrganizerType::Ptn, 'weight_category' => WeightCategory::C, 'event_status' => EventStatus::NonPemerintah]);

    app(VerifyCompetition::class)->handle($this->kesiswaan, $c, CompetitionStatus::Dipublikasikan, null, true);

    expect(Activity::query()->where('event', 'competition.published')->sole()->properties->has('weight_override'))->toBeTrue()
        ->and(Activity::query()->where('event', 'competition.published')->sole()->properties['weight_override'])->toBeNull();
});

it('asks for "Tanggal mulai" when the lomba lasts more than one day', function (): void {
    $c = drawerQueued();

    Livewire::actingAs($this->kesiswaan)->test(Form::class)->call('open', $c->getKey())
        ->set('form.duration', CompetitionFields::DURATION_MULTI_DAY)->set('form.starts_on', '')->set('form.final_on', '2026-11-10')
        ->call('save')
        ->assertHasErrors(['form.starts_on' => 'Wajib diisi.'])
        ->assertSee('Wajib diisi.');

    expect($c->fresh()->starts_on->toDateString())->toBe('2026-11-07')
        ->and($c->fresh()->final_on->toDateString())->toBe('2026-11-07');
});

it('audits no override when no weight was chosen yet (an incomplete entry rejected)', function (): void {
    $c = drawerQueued(['organizer_type' => OrganizerType::Ptn, 'weight_category' => null, 'event_status' => null]);

    app(VerifyCompetition::class)->handle($this->kesiswaan, $c, CompetitionStatus::Ditolak);

    expect(Activity::query()->where('event', 'competition.rejected')->sole()->properties['weight_override'])->toBeNull();
});
