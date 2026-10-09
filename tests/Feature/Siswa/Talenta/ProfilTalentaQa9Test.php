<?php

declare(strict_types=1);

use App\Actions\Talent\MoveTalentStage;
use App\Actions\Talent\RequestTalentReview;
use App\Actions\Talent\RestoreTalentApplication;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Livewire\Siswa\ProfilTalenta;
use App\Models\TalentApplication;
use App\Models\TalentType;
use Database\Seeders\TalentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

/*
| QA9 (2026-10-09), Siswa › Profil talenta and the student's review request:
| 1. The text of "Minta ditinjau ulang" stays out of the sealed audit log (D-63), also when the choice is restored.
| 9. A stale tab: "Batalkan" after Kesiswaan moved the choice or after another tab cancelled it, "Minta ditinjau ulang"
|    after a restore or after the request went out from another tab: a toast and a fresh list, no error page.
| Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    $this->seed(TalentSeeder::class);
    $this->withoutVite();
    Notification::fake();

    $this->nadia = F::student();
    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Ibu Dwi Rahayu');
    $this->osn = TalentType::query()->where('code', TalentTypeCode::Osn)->firstOrFail();
    $this->kimia = $this->osn->branches()->where('name', 'Kimia')->firstOrFail();
});

/** Nadia's choice OSN · Kimia in $status (a rejected one comes from Seleksi). */
function qa9Choice(object $test, TalentStatus $status = TalentStatus::Terdaftar): TalentApplication
{
    $factory = $status === TalentStatus::TidakLolos
        ? TalentApplication::factory()->rejected(TalentStatus::Seleksi, 'Nilai asesmen kurang.')
        : TalentApplication::factory()->status($status);

    return $factory->create([
        'student_id' => $test->nadia->getKey(),
        'talent_type_id' => $test->osn->getKey(),
        'talent_branch_id' => $test->kimia->getKey(),
    ])->fresh();
}

/** Does any row of the audit log hold $text, in any column (properties, attribute changes, description)? */
function qa9AuditHolds(string $text): bool
{
    return DB::table('activity_log')->get()
        ->contains(fn (object $row): bool => str_contains(implode(' ', array_map(fn ($value): string => (string) $value, (array) $row)), $text));
}

// 1. The student's message is not written into the audit log ------------------------------------------------

it('keeps the text of a review request out of the audit log, when it is sent and when the choice is restored (QA9)', function (): void {
    $application = qa9Choice($this, TalentStatus::TidakLolos);
    $message = 'Saya sakit saat asesmen, mohon dijadwalkan ulang.';

    app(RequestTalentReview::class)->handle($this->nadia->user, $application, $message);

    // The text itself lives on the row; the log only knows that there was one (D-63).
    expect($application->fresh()->review_message)->toBe($message)
        ->and(Activity::query()->where('event', 'talent.review_requested')->sole()->properties->get('has_message'))->toBeTrue()
        ->and(qa9AuditHolds('mohon dijadwalkan ulang'))->toBeFalse();

    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application->fresh());

    expect($application->fresh()->review_message)->toBeNull()
        ->and(Activity::query()->where('event', 'talent.restored')->exists())->toBeTrue()
        ->and(qa9AuditHolds('mohon dijadwalkan ulang'))->toBeFalse();
});

// 9. Stale tabs on Profil talenta -------------------------------------------------------------------------

it('says so instead of a 403 when "Batalkan" comes after Kesiswaan moved the choice (QA9)', function (): void {
    $application = qa9Choice($this);

    $component = Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class)
        ->assertSee('Batalkan OSN · Kimia');

    app(MoveTalentStage::class)->handle($this->kesiswaan, $application, TalentStatus::Pemetaan);

    $component->call('batalkan', $application->getKey())
        ->assertStatus(200)
        ->assertDispatched('sth-toast', message: 'Talenta ini sudah diproses Kesiswaan dan tidak bisa dibatalkan.', tone: 'warning')
        ->assertSet('undo', null)
        ->assertSee('Sedang dipetakan Kesiswaan')
        ->assertDontSee('Batalkan OSN · Kimia');

    expect($application->fresh()->trashed())->toBeFalse()
        ->and($application->fresh()->status)->toBe(TalentStatus::Pemetaan);
});

it('says so instead of a 404 when "Batalkan" comes after another tab cancelled the choice (QA9)', function (): void {
    $application = qa9Choice($this);

    $stale = Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class)
        ->assertSee('Batalkan OSN · Kimia');

    Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class)
        ->call('batalkan', $application->getKey())
        ->assertSee('OSN · Kimia dibatalkan.');

    $stale->call('batalkan', $application->getKey())
        ->assertStatus(200)
        ->assertDispatched('sth-toast', message: 'OSN · Kimia sudah kamu batalkan.', tone: 'warning')
        ->assertSet('undo', null)
        ->assertDontSee('Batalkan OSN · Kimia');

    expect($application->fresh()->trashed())->toBeTrue()
        ->and(Activity::query()->where('event', 'talent.cancelled')->count())->toBe(1);
});

it('says so instead of a 403 when "Minta ditinjau ulang" comes after Kesiswaan restored the choice (QA9)', function (): void {
    $application = qa9Choice($this, TalentStatus::TidakLolos);

    $component = Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class)
        ->assertSeeHtml('aria-label="Minta ditinjau ulang OSN · Kimia"');

    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application, null, TalentStatus::Pemetaan);

    $component->call('mintaTinjau', $application->getKey())
        ->assertStatus(200)
        ->assertDispatched('sth-toast', message: 'Pilihan ini sudah dibuka kembali.', tone: 'warning')
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('tinjauId', null)
        ->assertSee('Sedang dipetakan Kesiswaan')
        ->assertDontSeeHtml('aria-label="Minta ditinjau ulang OSN · Kimia"');

    expect($application->fresh()->review_requested_at)->toBeNull();
});

it('says so instead of a 403 when "Minta ditinjau ulang" comes after the request went out from another tab (QA9)', function (): void {
    $application = qa9Choice($this, TalentStatus::TidakLolos);

    $stale = Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class)
        ->assertSeeHtml('aria-label="Minta ditinjau ulang OSN · Kimia"');

    Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class)
        ->call('mintaTinjau', $application->getKey())
        ->call('kirimTinjau')
        ->assertHasNoErrors();

    $stale->call('mintaTinjau', $application->getKey())
        ->assertStatus(200)
        ->assertDispatched('sth-toast', message: 'Permintaanmu sudah terkirim. Tunggu kabar dari Kesiswaan.', tone: 'warning')
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('tinjauId', null)
        ->assertSee('permintaan tinjau ulang terkirim')
        ->assertDontSeeHtml('aria-label="Minta ditinjau ulang OSN · Kimia"');

    expect(Activity::query()->where('event', 'talent.review_requested')->count())->toBe(1);
});
