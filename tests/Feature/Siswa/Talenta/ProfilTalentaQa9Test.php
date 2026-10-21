<?php

declare(strict_types=1);

use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Livewire\Siswa\ProfilTalenta;
use App\Models\TalentApplication;
use App\Models\TalentType;
use Database\Seeders\TalentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

/*
| QA9 (2026-10-09), Siswa › Profil talenta: a stale tab. "Hapus" after another tab deleted the choice gets a toast and
| a fresh list, no error page. (The review request and the stage checks of QA9 left with D-142.) Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    $this->seed(TalentSeeder::class);
    $this->withoutVite();
    Notification::fake();

    $this->nadia = F::student(talent: false);
    $this->osn = TalentType::query()->where('code', TalentTypeCode::Osn)->firstOrFail();
    $this->kimia = $this->osn->branches()->where('name', 'Kimia')->firstOrFail();
});

/** Nadia's choice OSN · Kimia in $status. */
function qa9Choice(object $test, TalentStatus $status = TalentStatus::Terdaftar): TalentApplication
{
    return TalentApplication::factory()->status($status)->create([
        'student_id' => $test->nadia->getKey(),
        'talent_type_id' => $test->osn->getKey(),
        'talent_branch_id' => $test->kimia->getKey(),
    ])->fresh();
}

it('says so instead of a 404 when "Hapus" comes after another tab deleted the choice (QA9)', function (): void {
    $application = qa9Choice($this);

    $stale = Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class)
        ->assertSee('Hapus OSN · Kimia');

    Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class)
        ->call('batalkan', $application->getKey())
        ->assertSee('OSN · Kimia dihapus.');

    $stale->call('batalkan', $application->getKey())
        ->assertStatus(200)
        ->assertDispatched('sth-toast', message: 'OSN · Kimia sudah kamu hapus.', tone: 'warning')
        ->assertSet('undo', null)
        ->assertDontSee('Hapus OSN · Kimia');

    expect($application->fresh()->trashed())->toBeTrue()
        ->and(Activity::query()->where('event', 'talent.cancelled')->count())->toBe(1);
});
