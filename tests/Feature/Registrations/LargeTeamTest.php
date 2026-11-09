<?php

declare(strict_types=1);

use App\Actions\Competitions\CreateCompetition;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\InviteMembers;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Livewire\Siswa\Lomba\Usulkan;
use App\Livewire\Siswa\Pendaftaran\Form;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * SV8, D-196 (lead, 2026-11-09: "Siswa tim bisa lebih dari 3 orang, jangan dibatasi 3"): a lomba may set "Maks anggota"
 * up to Competition::MAX_TEAM_SIZE (30, was 20); its own limit still holds on registering, inviting and in the panel,
 * and no team passes 30 even on a lomba without a limit. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    K::boot();
    Notification::fake();
    $this->guru = F::staff(RoleName::Guru);
    $this->ketua = F::student();
});

/** @return list<Student> active students with fictional names, spread over the XI classes */
function d196Students(int $count): array
{
    return array_map(
        fn (int $i): Student => F::student('Anggota Tim Fiktif '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'XI-'.(($i % 12) + 1)),
        range(1, $count),
    );
}

/** @param list<Student> $students */
function d196Ids(array $students): array
{
    return array_map(fn (Student $s): string => $s->getKey(), $students);
}

/** @param list<Student> $members */
function d196Register(object $t, Competition $lomba, array $members): Registration
{
    return app(CreateRegistration::class)->handle(F::user($t->ketua), $lomba, RegistrationType::Tim, d196Ids($members), $t->guru->id);
}

function d196Error(Closure $call, string $key = 'members'): string
{
    try {
        $call();
    } catch (ValidationException $e) {
        return (string) ($e->errors()[$key][0] ?? 'other: '.json_encode($e->errors()));
    }

    throw new RuntimeException('No validation error.');
}

// The bound ----------------------------------------------------------------------------------------

it('lets a lomba set "Maks anggota" above 3, up to 30, and refuses 31', function (): void {
    $admin = K::staff(RoleName::Admin, 'Operator STH');
    $create = app(CreateCompetition::class);

    expect(Competition::MAX_TEAM_SIZE)->toBe(30)
        ->and($create->handle($admin, K::form(['name' => 'Lomba Paduan Suara Fiktif 2026', 'max_team_size' => 30]), K::pdf())->max_team_size)->toBe(30)
        ->and($create->handle($admin, K::form(['name' => 'Lomba Debat Fiktif 2026', 'max_team_size' => 4]), K::pdf())->max_team_size)->toBe(4);

    expect(d196Error(fn () => $create->handle($admin, K::form(['name' => 'Lomba Tari Kolosal Fiktif 2026', 'max_team_size' => 31]), K::pdf()), 'max_team_size'))
        ->toBe('Maksimal 30 anggota.')
        ->and(d196Error(fn () => $create->handle($admin, K::form(['name' => 'Lomba Tari Kolosal Fiktif 2026', 'max_team_size' => 1]), K::pdf()), 'max_team_size'))
        ->toBe('Minimal 2 anggota.')
        ->and(Competition::query()->where('name', 'Lomba Tari Kolosal Fiktif 2026')->exists())->toBeFalse();
});

it('offers up to 30 on the "Usulkan lomba" form, refuses 31 and keeps a student usulan of 30', function (): void {
    $page = Livewire::actingAs(F::user($this->ketua))->test(Usulkan::class)
        ->set(K::fields(F::usulan(['name' => 'Lomba Paduan Suara Pelajar Fiktif 2027', 'participation' => 'tim', 'max_team_size' => 31])))
        ->set('form.juknis', K::pdf())
        ->assertSeeHtml('max="30"')
        ->call('simpan');

    expect($page->errors()->first('form.max_team_size'))->toBe('Maksimal 30 anggota.')
        ->and(Competition::query()->where('name', 'Lomba Paduan Suara Pelajar Fiktif 2027')->exists())->toBeFalse();

    $page->set('form.max_team_size', 30)->call('simpan')->assertHasNoErrors();

    expect(Competition::query()->where('name', 'Lomba Paduan Suara Pelajar Fiktif 2027')->value('max_team_size'))->toBe(30);
});

// The lomba's own limit ---------------------------------------------------------------------------

it('accepts the 10th member and refuses the 11th on a lomba with "Maks anggota" 10', function (): void {
    $lomba = F::teamCompetition(10);
    $first = d196Students(10);

    // Ketua + 10 invited = 11 people: refused, nothing stored.
    expect(d196Error(fn () => d196Register($this, $lomba, $first)))->toBe('Tim maksimal 10 orang, termasuk kamu.')
        ->and(Registration::query()->count())->toBe(0);

    // Ketua + 9 invited = 10 people: the 10th member is in.
    $registration = d196Register($this, $lomba, array_slice($first, 0, 9));

    expect($registration->members()->count())->toBe(10)
        ->and($registration->members()->whereNull('declined_at')->count())->toBe(10);

    // The ketua cannot invite an 11th one later either.
    expect(d196Error(fn () => app(InviteMembers::class)->handle(F::user($this->ketua), $registration, [$first[9]->getKey()])))
        ->toBe('Tim maksimal 10 orang, termasuk kamu.')
        ->and($registration->members()->count())->toBe(10);
});

it('lets the ketua invite up to the 10th member, not the 11th', function (): void {
    $lomba = F::teamCompetition(10);
    $students = d196Students(10);
    $registration = d196Register($this, $lomba, array_slice($students, 0, 8));
    $invite = fn (array $more): Registration => app(InviteMembers::class)->handle(F::user($this->ketua), $registration->fresh(), d196Ids($more));

    // 9 people + 2 = 11: refused as a whole.
    expect(d196Error(fn () => $invite([$students[8], $students[9]])))->toBe('Tim maksimal 10 orang, termasuk kamu.')
        ->and($registration->members()->count())->toBe(9);

    // 9 people + 1 = 10.
    $invite([$students[8]]);
    expect($registration->members()->whereNull('declined_at')->count())->toBe(10);

    expect(d196Error(fn () => $invite([$students[9]])))->toBe('Tim maksimal 10 orang, termasuk kamu.');
});

it('holds a team to 30 on a lomba without its own "Maks anggota"', function (): void {
    $lomba = F::teamCompetition(10, ['max_team_size' => null]);
    $students = d196Students(30);

    expect($lomba->teamLimit())->toBe(30)
        ->and(F::teamCompetition(12)->teamLimit())->toBe(12)
        ->and(d196Error(fn () => d196Register($this, $lomba, $students)))->toBe('Tim maksimal 30 orang, termasuk kamu.');

    $registration = d196Register($this, $lomba, array_slice($students, 0, 29));

    expect($registration->members()->count())->toBe(30);
});

// The registration panel --------------------------------------------------------------------------

it('fills the registration panel up to "Maks anggota" 10 and sends a team of 10, not of 11', function (): void {
    $lomba = F::teamCompetition(10);
    $students = d196Students(10);

    $panel = Livewire::actingAs(F::user($this->ketua))->test(Form::class, ['competitionId' => $lomba->getKey()])
        ->set('type', 'tim')
        ->set('members', d196Ids(array_slice($students, 0, 8)))
        ->assertSeeText('Anggota tim (maks 10)')
        ->assertSee('9 orang')
        ->assertSee('Tambah anggota')
        ->assertDontSee('Tim sudah 10 orang');

    // The 10th person fills the team: the search gives way to the note, and "Tambah" adds nobody.
    $panel->set('members', d196Ids(array_slice($students, 0, 9)))
        ->assertSee('10 orang')
        ->assertSee('Tim sudah 10 orang. Hapus anggota untuk mengganti.')
        ->assertDontSee('Tambah anggota')
        ->set('hasil', [['id' => $students[9]->getKey(), 'name' => 'Anggota Tim Fiktif 10', 'kelas' => 'XI-11', 'busy' => false, 'declined' => false, 'pending' => false]])
        ->call('tambah', $students[9]->getKey());

    expect($panel->get('members'))->toHaveCount(9);

    // A list of 11 people sent anyway (a stale or edited request) is refused by CreateRegistration.
    $panel->set('members', d196Ids($students))
        ->set('mentorId', $this->guru->id)
        ->set('phone', '0812 9876 5432')
        ->set('setuju', true)
        ->call('kirim')
        ->assertHasErrors(['members' => 'Tim maksimal 10 orang, termasuk kamu.']);

    expect(Registration::query()->count())->toBe(0);

    $panel->set('members', d196Ids(array_slice($students, 0, 9)))
        ->set('setuju', true)
        ->call('kirim')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Pendaftaran terkirim.');

    expect(Registration::query()->sole()->members()->count())->toBe(10);
});
