<?php

declare(strict_types=1);

use App\Actions\Registrations\CancelRegistration;
use App\Actions\Registrations\CreateRegistration;
use App\Enums\CompetitionStatus;
use App\Enums\MemberRole;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Livewire\Siswa\Pendaftaran\Form;
use App\Models\Registration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * FS1 Paket B (D-148, part 1): the student who proposed a lomba may register for it while Kesiswaan has not decided
 * yet. The registration waits as `menunggu_lomba`; nobody is told (no invitation, no approver) until the lomba is
 * published. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->guru = F::staff(RoleName::Guru);
    $this->kepsek = F::staff(RoleName::KepalaSekolah, 'Kepala Sekolah Contoh');
    $this->nadia = F::student();
    $this->nadiaUser = F::user($this->nadia);
    $this->rizky = F::student('Rizky Maulana', 'XI-1');
    $this->dimas = F::student('Dimas Arya Nugroho', 'XI-4');
});

/** Nadia's queued team lomba (max 3) with her waiting team registration: Nadia ketua, Rizky anggota. */
function mlTeam(object $t): Registration
{
    $lomba = F::pendingCompetition($t->nadiaUser, F::usulanAttributes(['name' => 'ISPO 2027', 'participation' => Participation::Tim, 'max_team_size' => 3]));

    return app(CreateRegistration::class)->handle($t->nadiaUser, $lomba, RegistrationType::Tim, [$t->rizky->getKey()], $t->guru->getKey());
}

it('lets the submitter register for the own queued lomba as menunggu_lomba without telling anyone', function (): void {
    $r = mlTeam($this);

    expect($r->status)->toBe(RegistrationStatus::MenungguLomba)
        ->and($r->members()->where('role', MemberRole::Anggota)->sole()->invited_at)->toBeNull()
        ->and($r->members()->where('role', MemberRole::Ketua)->sole()->confirmed_at)->not->toBeNull();
    Notification::assertNothingSent();

    $audit = Activity::query()->where('event', 'registration.created')->sole();
    expect($audit->properties['status'])->toBe('menunggu_lomba');
});

it('registers an individu for the own queued lomba as menunggu_lomba, approvers not told', function (): void {
    $lomba = F::pendingCompetition($this->nadiaUser, F::usulanAttributes());

    $r = app(CreateRegistration::class)->handle($this->nadiaUser, $lomba, RegistrationType::Individu, [], $this->guru->getKey());

    expect($r->status)->toBe(RegistrationStatus::MenungguLomba);
    Notification::assertNothingSent();
});

it('refuses another student and an unpublished lomba of somebody else', function (): void {
    $lomba = F::pendingCompetition($this->nadiaUser, F::usulanAttributes());

    expect(fn () => app(CreateRegistration::class)->handle(F::user($this->dimas), $lomba, RegistrationType::Individu, [], $this->guru->getKey()))
        ->toThrow(AuthorizationException::class);

    $other = F::pendingCompetition(F::user($this->dimas), F::usulanAttributes(['name' => 'Lomba Esai Contoh 2027']));

    expect(fn () => app(CreateRegistration::class)->handle($this->nadiaUser, $other, RegistrationType::Individu, [], $this->guru->getKey()))
        ->toThrow(AuthorizationException::class)
        ->and(Registration::query()->count())->toBe(0);
});

it('refuses the submitter once the own lomba was rejected or its registration window passed', function (array $attributes): void {
    $lomba = F::pendingCompetition($this->nadiaUser, F::usulanAttributes($attributes));

    expect(fn () => app(CreateRegistration::class)->handle($this->nadiaUser, $lomba, RegistrationType::Individu, [], $this->guru->getKey()))
        ->toThrow(AuthorizationException::class);
})->with([
    'rejected' => [['status' => CompetitionStatus::Ditolak]],
    'deadline passed' => [['registration_closes_at' => now()->subDay()]],
    'not open yet' => [['registration_opens_at' => now()->addDays(3)]],
]);

it('lets the leader cancel while the lomba waits (D-33 widened), telling nobody', function (): void {
    $r = mlTeam($this);

    app(CancelRegistration::class)->handle($this->nadiaUser, $r);

    expect($r->fresh()->status)->toBe(RegistrationStatus::Dibatalkan)
        ->and($r->fresh()->cancelled_at)->not->toBeNull();
    Notification::assertNothingSent();
});

it('registers through the panel and shows the waiting state on the panel and on Lomba saya', function (): void {
    $lomba = F::pendingCompetition($this->nadiaUser, F::usulanAttributes(['name' => 'ISPO 2027', 'participation' => Participation::Tim, 'max_team_size' => 3]));
    $sentence = 'Pendaftaran diteruskan setelah Kesiswaan memverifikasi lomba ini. Undangan tim dikirim saat itu.';

    $panel = Livewire::actingAs($this->nadiaUser)->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->assertSee('Kirim pendaftaran')
        ->assertDontSee('Pendaftaran dibuka setelah Kesiswaan memverifikasi lomba ini.')
        ->set('q', 'Rizky')
        ->call('tambah', $this->rizky->getKey())
        ->assertSet('members', [$this->rizky->getKey()])
        ->set('mentorId', $this->guru->getKey())
        ->set('setuju', true)
        ->set('phone', '081234567890')
        ->call('kirim')
        ->assertHasNoErrors();

    $r = Registration::query()->sole();
    expect($r->status)->toBe(RegistrationStatus::MenungguLomba);
    Notification::assertNothingSent();

    $panel->assertSee('Menunggu verifikasi lomba')->assertSee($sentence)->assertSee('Batalkan pendaftaran');

    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba-saya.show', $r))
        ->assertOk()->assertSee('Menunggu verifikasi lomba')->assertSee($sentence);
});

it('keeps a waiting team out of the anggota\'s Lomba saya until the invitation goes out', function (): void {
    $r = mlTeam($this);
    $rizkyUser = F::user($this->rizky);

    $this->actingAs($rizkyUser)->get(route('siswa.lomba-saya.index'))->assertOk()->assertDontSee('ISPO 2027');
    $this->actingAs($rizkyUser)->get(route('siswa.lomba-saya.show', $r))->assertNotFound();
    $this->actingAs($rizkyUser)->get(route('siswa.beranda'))->assertOk()->assertDontSee('ISPO 2027');
});
