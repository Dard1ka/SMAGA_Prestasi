<?php

declare(strict_types=1);

use App\Actions\Registrations\CreateRegistration;
use App\Enums\MemberRole;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Livewire\Siswa\Pendaftaran\Form;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| FS1 B1: one team order on every screen that lists a team (Pendaftaran lomba panel, Lomba saya, Undangan tim, Guru ›
| detail binaan): the ketua first, then by invitation time with "not invited yet" last, then by name, then by id
| (RegistrationMember::inTeamOrder()). Lomba saya sorted in SQL (role, invited_at), which leaves members with the same
| or no invitation time in the database's order, and MySQL puts NULL first. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    K::boot();
    Notification::fake();
    $this->withoutVite();
    $this->guru = F::staff(RoleName::Guru);
    $this->nadia = F::student();
    $this->nadiaUser = F::user($this->nadia);
});

/** The rendered HTML without Livewire's block markers. */
function fs1toPlain(string $html): string
{
    return (string) preg_replace('/<!--\[if (?:END)?BLOCK\]><!\[endif\]-->/', '', $html);
}

/** The <ul> whose opening tag carries $marker, or else the first <ul> after $marker, with everything inside it. */
function fs1toList(string $html, string $marker): string
{
    $start = strpos($html, $marker);
    expect($start)->not->toBeFalse("{$marker} is not on the page");

    $before = substr($html, 0, (int) $start);
    $open = strrpos($before, '<ul');
    $inTag = $open !== false && strrpos($before, '>') < $open;
    preg_match('/<ul[^>]*>.*?<\/ul>/s', substr($html, $inTag ? (int) $open : (int) $start), $list);

    return $list[0] ?? '';
}

/**
 * The given names in the order they appear in $html; fails when one is missing.
 *
 * @param  list<string>  $names
 * @return list<string>
 */
function fs1toOrder(string $html, array $names): array
{
    $at = [];

    foreach ($names as $name) {
        $position = strpos($html, $name);
        expect($position)->not->toBeFalse("{$name} is not in the list");
        $at[$name] = $position;
    }

    asort($at);

    return array_keys($at);
}

/** A member row in memory, for the order rule alone (no database). */
function fs1toMember(string $id, MemberRole $role, ?string $invitedAt, string $name): RegistrationMember
{
    $member = new RegistrationMember;
    $member->forceFill(['id' => $id, 'role' => $role, 'invited_at' => $invitedAt]);
    $user = new User;
    $user->forceFill(['name' => $name]);
    $student = new Student;
    $student->setRelation('user', $user);

    return $member->setRelation('student', $student);
}

it('orders a team: ketua first, then by invitation time with none last, then by name, then by id', function (): void {
    $members = collect([
        fs1toMember('m-f', MemberRole::Anggota, null, 'Zaki Pratama'),
        fs1toMember('m-e', MemberRole::Anggota, null, 'ayu Lestari'),
        fs1toMember('m-d', MemberRole::Anggota, '2026-10-01 08:00:00', 'Dimas Arya'),
        fs1toMember('m-c', MemberRole::Anggota, '2026-10-01 07:00:00', 'Zahra Amelia'),
        fs1toMember('m-b', MemberRole::Anggota, '2026-10-01 07:00:00', 'Bunga Citra'),
        // The ketua was invited last and its name sorts last: the role still decides first.
        fs1toMember('m-a', MemberRole::Ketua, '2026-10-02 09:00:00', 'Zulfa Ketua'),
        // Same time and name as Bunga: the id decides, so the order never depends on the database.
        fs1toMember('m-1', MemberRole::Anggota, '2026-10-01 07:00:00', 'Bunga Citra'),
    ]);

    $ordered = RegistrationMember::inTeamOrder($members);

    expect($ordered->map(fn (RegistrationMember $m): string => $m->getKey().' '.$m->student->user->name)->all())->toBe([
        'm-a Zulfa Ketua',
        'm-1 Bunga Citra',
        'm-b Bunga Citra',
        'm-c Zahra Amelia',
        'm-d Dimas Arya',
        'm-e ayu Lestari',
        'm-f Zaki Pratama',
    ])->and($ordered->keys()->all())->toBe(range(0, 6));

    // The input order does not matter.
    expect(RegistrationMember::inTeamOrder($members->reverse())->map->getKey()->all())->toBe($ordered->map->getKey()->all());
});

it('lists a waiting team (no invitation times yet) in the same order on the panel and on Lomba saya', function (): void {
    $own = F::pendingCompetition($this->nadiaUser, F::usulanAttributes([
        'name' => 'Lomba Tim Usulan Fiktif', 'participation' => Participation::Tim, 'max_team_size' => 4,
    ]));
    // Zaki is added before Ayu: the database order is the opposite of the name order.
    $zaki = F::student('Qa Talenta Zaki Pratama', 'XI-B');
    $ayu = F::student('Qa Talenta Ayu Lestari', 'XI-C');
    $registration = app(CreateRegistration::class)
        ->handle($this->nadiaUser, $own, RegistrationType::Tim, [$zaki->getKey(), $ayu->getKey()], $this->guru->getKey());

    expect($registration->status)->toBe(RegistrationStatus::MenungguLomba)
        ->and($registration->members()->where('role', 'anggota')->whereNull('invited_at')->count())->toBe(2);

    $names = ['Nadia Putri Anindya', 'Qa Talenta Zaki Pratama', 'Qa Talenta Ayu Lestari'];
    $expected = ['Nadia Putri Anindya', 'Qa Talenta Ayu Lestari', 'Qa Talenta Zaki Pratama'];

    $form = Livewire::actingAs($this->nadiaUser)->test(Form::class, ['competitionId' => $own->getKey()]);
    $panel = fs1toOrder(fs1toList(fs1toPlain($form->html()), 'aria-labelledby="lbl-tim-kamu"'), $names);

    $page = $this->actingAs($this->nadiaUser)->get(route('siswa.lomba-saya.show', $registration))->assertOk()->getContent();
    $card = fs1toOrder(fs1toList(fs1toPlain((string) $page), 'id="tim-judul"'), $names);

    expect($panel)->toBe($expected)
        ->and($card)->toBe($expected);
});

it('lists a team invited at the same moment in the same order on the panel, Lomba saya, Undangan tim and the guru detail', function (): void {
    $lomba = F::teamCompetition(4, ['name' => 'Lomba Tim Terbuka Fiktif']);
    $zaki = F::student('Qa Talenta Zaki Pratama', 'XI-B');
    $ayu = F::student('Qa Talenta Ayu Lestari', 'XI-C');
    $registration = app(CreateRegistration::class)
        ->handle($this->nadiaUser, $lomba, RegistrationType::Tim, [$zaki->getKey(), $ayu->getKey()], $this->guru->getKey());

    // One moment for the whole team, the ketua's own row included: role, then name decide.
    $registration->members()->update(['invited_at' => CarbonImmutable::parse('2026-10-01 07:00:00')]);
    expect($registration->status)->toBe(RegistrationStatus::MenungguKonfirmasi);

    $names = ['Nadia Putri Anindya', 'Qa Talenta Zaki Pratama', 'Qa Talenta Ayu Lestari'];
    $expected = ['Nadia Putri Anindya', 'Qa Talenta Ayu Lestari', 'Qa Talenta Zaki Pratama'];

    $form = Livewire::actingAs($this->nadiaUser)->test(Form::class, ['competitionId' => $lomba->getKey()]);
    $panel = fs1toOrder(fs1toList(fs1toPlain($form->html()), 'aria-labelledby="lbl-tim-kamu"'), $names);

    $page = $this->actingAs($this->nadiaUser)->get(route('siswa.lomba-saya.show', $registration))->assertOk()->getContent();
    $card = fs1toOrder(fs1toList(fs1toPlain((string) $page), 'id="tim-judul"'), $names);

    $invite = $this->actingAs(F::user($zaki))->get(route('siswa.undangan.show', $registration))->assertOk()->getContent();
    $undangan = fs1toOrder(fs1toList(fs1toPlain((string) $invite), 'id="h-tim"'), $names);

    $detail = $this->actingAs($this->guru)->get(route('guru.binaan.show', $registration))->assertOk()->getContent();
    $guru = fs1toOrder(fs1toList(fs1toPlain((string) $detail), 'id="h-tim"'), $names);

    expect($panel)->toBe($expected)
        ->and($card)->toBe($expected)
        ->and($undangan)->toBe($expected)
        ->and($guru)->toBe($expected);
});

it('puts a member invited later after the ones invited first on every team screen', function (): void {
    $lomba = F::teamCompetition(4, ['name' => 'Lomba Tim Terbuka Fiktif']);
    $ayu = F::student('Qa Talenta Ayu Lestari', 'XI-C');
    $zaki = F::student('Qa Talenta Zaki Pratama', 'XI-B');
    $registration = app(CreateRegistration::class)
        ->handle($this->nadiaUser, $lomba, RegistrationType::Tim, [$ayu->getKey(), $zaki->getKey()], $this->guru->getKey());

    // Ayu was invited after Zaki (a replacement): time beats name.
    $registration->members()->update(['invited_at' => CarbonImmutable::parse('2026-10-01 07:00:00')]);
    $registration->members()->where('student_id', $ayu->getKey())->update(['invited_at' => CarbonImmutable::parse('2026-10-02 07:00:00')]);

    $names = ['Nadia Putri Anindya', 'Qa Talenta Zaki Pratama', 'Qa Talenta Ayu Lestari'];

    $form = Livewire::actingAs($this->nadiaUser)->test(Form::class, ['competitionId' => $lomba->getKey()]);
    $page = $this->actingAs($this->nadiaUser)->get(route('siswa.lomba-saya.show', $registration))->assertOk()->getContent();
    $invite = $this->actingAs(F::user($ayu))->get(route('siswa.undangan.show', $registration))->assertOk()->getContent();
    $detail = $this->actingAs($this->guru)->get(route('guru.binaan.show', $registration))->assertOk()->getContent();

    expect(fs1toOrder(fs1toList(fs1toPlain($form->html()), 'aria-labelledby="lbl-tim-kamu"'), $names))->toBe($names)
        ->and(fs1toOrder(fs1toList(fs1toPlain((string) $page), 'id="tim-judul"'), $names))->toBe($names)
        ->and(fs1toOrder(fs1toList(fs1toPlain((string) $invite), 'id="h-tim"'), $names))->toBe($names)
        ->and(fs1toOrder(fs1toList(fs1toPlain((string) $detail), 'id="h-tim"'), $names))->toBe($names);
});

it('keeps the team order of Registration members after the order helper (an Eloquent collection stays one)', function (): void {
    $lomba = F::teamCompetition(4);
    $registration = app(CreateRegistration::class)
        ->handle($this->nadiaUser, $lomba, RegistrationType::Tim, [F::student('Qa Talenta Zaki Pratama', 'XI-B')->getKey()], $this->guru->getKey());

    $members = Registration::query()->with('members.student.user')->findOrFail($registration->getKey())->members;

    expect(RegistrationMember::inTeamOrder($members))->toBeInstanceOf(EloquentCollection::class)
        ->and(RegistrationMember::inTeamOrder($members)->first()->role)->toBe(MemberRole::Ketua);
});
