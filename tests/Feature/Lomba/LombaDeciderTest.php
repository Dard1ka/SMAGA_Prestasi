<?php

declare(strict_types=1);

use App\Actions\Competitions\VerifyCompetition;
use App\Actions\Registrations\CreateRegistration;
use App\Enums\CompetitionStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Livewire\Guru\Lomba as GuruLomba;
use App\Livewire\Siswa\Lomba\Katalog;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\Competitions\CompetitionPublished;
use App\Notifications\Competitions\CompetitionRejected;
use App\Support\StaffRoleLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| FS1 fix wave (integration review): one rule names who decided a lomba (StaffRoleLabel::forLomba()): the staff role of
| its verifier, "Kesiswaan" when there is none (no verifier, a deleted account, an account without a staff role). Every
| text about a published or rejected lomba uses it: the two notices, Cari lomba › Usulan saya, Lomba saya, Detail lomba
| and its Pendaftaran lomba panel, and the Guru drawer. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    F::withoutStaffTwoFactor();
    Notification::fake();
    $this->withoutVite();
    $this->guru = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Dwi Rahayu');
    $this->admin = F::staff(RoleName::Admin, 'Operator STH');
    $this->nadia = F::student();
    $this->nadiaUser = F::user($this->nadia);
});

/** Who decides in each case, and the role the texts must name. @return array<string, array{0: string, 1: string}> */
function fixDeciders(): array
{
    return [
        'Kesiswaan' => ['kesiswaan', 'Kesiswaan'],
        'Admin' => ['admin', 'Admin'],
        'an account deleted later' => ['deleted', 'Kesiswaan'],
    ];
}

/** Rejects the lomba as that decider; "deleted" = an Admin whose account is deleted afterwards (no verifier left). */
function fixReject(object $t, Competition $lomba, string $decider, ?string $note = null): void
{
    $actor = $decider === 'kesiswaan' ? $t->kesiswaan : $t->admin;
    app(VerifyCompetition::class)->handle($actor, $lomba, CompetitionStatus::Ditolak, $note);

    if ($decider === 'deleted') {
        $t->admin->delete();
    }
}

it('names the decider of a rejected usulan the same way on every student screen', function (string $decider, string $role): void {
    $lomba = F::pendingCompetition($this->nadiaUser, F::usulanAttributes());
    $registration = app(CreateRegistration::class)->handle($this->nadiaUser, $lomba, RegistrationType::Individu, [], $this->guru->getKey());
    fixReject($this, $lomba, $decider, 'Juknis tidak terbaca.');

    $others = array_values(array_diff(['Kesiswaan', 'Admin', 'sekolah'], [$role]));

    $detail = $this->actingAs($this->nadiaUser)->get(route('siswa.lomba.show', $lomba))->assertOk()
        ->assertSee($role.' menolak usulan lomba ini, jadi lomba ini tidak tampil di katalog dan tidak menerima pendaftaran.')
        ->assertSee('Usulan lomba ini ditolak '.$role.', jadi tidak menerima pendaftaran.');
    $saya = $this->actingAs($this->nadiaUser)->get(route('siswa.lomba-saya.show', Registration::query()->findOrFail($registration->getKey())))->assertOk()
        ->assertSee('Usulan lomba ini ditolak '.$role.', jadi pendaftarannya ikut dibatalkan.');
    $usulan = Livewire::actingAs($this->nadiaUser)->test(Katalog::class)->set('tab', 'usulan')
        ->assertSee('Catatan '.$role.': Juknis tidak terbaca.');

    foreach ($others as $other) {
        $detail->assertDontSee($other.' menolak usulan')->assertDontSee('ditolak '.$other.',');
        $saya->assertDontSee('ditolak '.$other.',');
        $usulan->assertDontSee('Catatan '.$other.':');
    }
})->with(fixDeciders());

it('names the decider in the Guru drawer of a rejected lomba the guru sent, with or without a reason', function (string $decider, string $role): void {
    $lomba = Competition::factory()->proposal()->create(['name' => 'Lomba Inovasi Guru 2026', 'submitted_by' => $this->guru->getKey()]);
    fixReject($this, $lomba, $decider);

    Livewire::actingAs($this->guru)->test(GuruLomba::class)->set('lihat', $lomba->getKey())
        ->assertSee('Ditolak '.$role)
        ->assertSee($role.' tidak menulis alasan.');
})->with(fixDeciders());

it('builds the two lomba notices from the same rule', function (?string $decider, string $role): void {
    $lomba = F::pendingCompetition($this->nadiaUser, F::usulanAttributes());
    $user = match ($decider) {
        'kesiswaan' => $this->kesiswaan,
        'admin' => $this->admin,
        'no staff role' => User::factory()->create(),
        default => null,
    };

    expect(StaffRoleLabel::forLomba($user))->toBe($role)
        ->and(CompetitionRejected::for($lomba, null, false, $user)->body)->toBe($role.' tidak menulis alasan. Tanyakan ke Kesiswaan bila perlu.')
        ->and(CompetitionRejected::for($lomba, 'Juknis tidak terbaca.', false, $user)->body)->toBe('Alasan '.$role.': Juknis tidak terbaca.')
        ->and(CompetitionPublished::for($lomba, true, null, $user)->body)->toBe($role.' sudah memverifikasi lomba yang dikirim. Lomba ini sekarang tampil di katalog.');
})->with([
    'Kesiswaan' => ['kesiswaan', 'Kesiswaan'],
    'Admin' => ['admin', 'Admin'],
    'no decider' => [null, 'Kesiswaan'],
    'an account without a staff role' => ['no staff role', 'Kesiswaan'],
]);

it('keeps "sekolah" as the fallback of the other decisions', function (): void {
    expect(StaffRoleLabel::forDecision(null))->toBe('sekolah')
        ->and(StaffRoleLabel::forLomba(null))->toBe('Kesiswaan');
});
