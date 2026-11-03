<?php

declare(strict_types=1);

use App\Actions\Competitions\VerifyCompetition;
use App\Actions\Registrations\CreateRegistration;
use App\Enums\CompetitionStatus;
use App\Enums\Participation;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Livewire\Kesiswaan\Lomba\Index;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| FS1 fix wave (integration review): a team registered for its own queued lomba waits with it (`menunggu_lomba`,
| D-148) and its anggota are not invited yet (`invited_at` null). Kelola lomba counts only the students who know about
| a registration, as every student screen does: the ketua until the lomba is published, every member after it.
| Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    F::withoutStaffTwoFactor();
    Notification::fake();
    $this->withoutVite();
    $this->guru = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Dwi Rahayu');
    $this->nadiaUser = F::user(F::student());
    $this->rizky = F::student('Rizky Maulana', 'XI-A');
    $this->hana = F::student('Hana Safira', 'XI-B');
});

/** @return array{0: int, 1: string} the catalog count of the lomba and the "Pendaftar 30 hari" tile */
function fixWaitingCounts(object $t): array
{
    $component = Livewire::actingAs($t->kesiswaan)->test(Index::class)->call('setTab', 'katalog');
    $row = $component->viewData('rows')->firstWhere('name', 'ISPO 2027');
    $tile = collect($component->viewData('stats'))->firstWhere('label', 'Pendaftar 30 hari');

    return [$row['count'], $tile['value']];
}

it('leaves the anggota of a waiting team out of the counts until they are invited', function (): void {
    $lomba = F::pendingCompetition($this->nadiaUser, F::usulanAttributes(['participation' => Participation::Tim, 'max_team_size' => 3]));
    app(CreateRegistration::class)->handle($this->nadiaUser, $lomba, RegistrationType::Tim, [$this->rizky->getKey(), $this->hana->getKey()], $this->guru->getKey());

    expect(fixWaitingCounts($this))->toBe([1, '1']);

    app(VerifyCompetition::class)->handle($this->kesiswaan, $lomba->fresh(), CompetitionStatus::Dipublikasikan);

    expect(fixWaitingCounts($this))->toBe([3, '3']);
});
