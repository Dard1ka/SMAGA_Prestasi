<?php

declare(strict_types=1);

use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Trash\RestoreFromTrash;
use App\Actions\Trash\TrashStudents;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Livewire\Kesiswaan\Lomba\Index as KelolaLomba;
use App\Livewire\Siswa\Beranda;
use App\Livewire\Siswa\KonfirmasiTim;
use App\Livewire\Siswa\LombaSaya\Index as LombaSaya;
use App\Livewire\Siswa\Pendaftaran\Form;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use App\Models\TrashEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * smaga_server, the trash of 5 Nov 2026 (Admin › Kelas › "Hapus siswa"): a trashed student (its `students` and `users`
 * rows soft-deleted, App\Actions\Trash\TrashStudents) is hidden everywhere, also from the screens of the students it
 * shares a team with and from the counts of Kelola lomba. Its member rows stay until the purge, so:
 *  - Kelola lomba (catalog count, "Tutup lomba ini?", the tile "Pendaftar 30 hari") does not count it;
 *  - Lomba saya, Pendaftaran lomba and Undangan tim list the team without it (before: a blank "Siswa" row);
 *  - a registration whose ketua is in the trash is hidden from the other members (Lomba saya, Beranda, Pendaftaran
 *    lomba) and its invitation can no longer be answered: the link says it is no longer valid, as an expired one.
 * "Pulihkan" brings everything back. Every flow runs through the real Actions. Fictional data only (DESIGN-SPEC-v2 §5).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    F::withoutStaffTwoFactor();
    Notification::fake();
    $this->withoutVite();
    $this->admin = F::staff(RoleName::Admin, 'Operator Sekolah');
    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Dwi Rahayu');
    $this->guru = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $this->keisha = F::student('Keisha Larasati', 'XI-6');
    $this->lomba = F::teamCompetition(3);
});

/** Nadia's team of three for $this->lomba (Dimas and Keisha invited); Dimas confirms when $dimasConfirms. */
function trashScrTeam(object $t, bool $dimasConfirms = true): Registration
{
    $registration = app(CreateRegistration::class)->handle(
        F::user($t->nadia),
        $t->lomba,
        RegistrationType::Tim,
        [$t->dimas->getKey(), $t->keisha->getKey()],
        $t->guru->id,
    );

    if ($dimasConfirms) {
        app(ConfirmMembership::class)->handle(F::user($t->dimas), $registration->fresh());
    }

    $registration = $registration->fresh();
    expect($registration->status)->toBe(RegistrationStatus::MenungguKonfirmasi);

    return $registration;
}

/** The Admin puts $student in the trash (Admin › Kelas › Hapus). */
function trashScrTrash(object $t, Student $student): TrashEntry
{
    return app(TrashStudents::class)->handle($t->admin, [$student->user_id], 'Siswa pindah sekolah (data contoh).')[0];
}

function trashScrLombaSaya(Student $viewer, ?Registration $registration = null): Testable
{
    return Livewire::actingAs(F::user($viewer))->test(LombaSaya::class, $registration !== null ? ['registration' => $registration] : []);
}

function trashScrPanel(Student $viewer, Competition $lomba): Testable
{
    return Livewire::actingAs(F::user($viewer))->test(Form::class, ['competitionId' => $lomba->getKey()]);
}

function trashScrUndangan(Student $viewer, Registration $registration): Testable
{
    return Livewire::actingAs(F::user($viewer))->test(KonfirmasiTim::class, ['registration' => $registration->fresh()]);
}

/** @return array{0: int, 1: string, 2: int} the catalog count, the tile "Pendaftar 30 hari" and the "Tutup lomba ini?" count */
function trashScrKelolaCounts(object $t): array
{
    $component = Livewire::actingAs($t->kesiswaan)->test(KelolaLomba::class)->call('setTab', 'katalog');
    $row = $component->viewData('rows')->firstWhere('name', $t->lomba->name);
    $tile = collect($component->viewData('stats'))->firstWhere('label', 'Pendaftar 30 hari');
    $closing = $component->call('askClose', $t->lomba->getKey())->viewData('closingLomba');

    return [$row['count'], $tile['value'], $closing['count']];
}

// Kelola lomba --------------------------------------------------------------------------------------------------------

it('leaves a trashed student out of the counts of Kelola lomba', function (): void {
    trashScrTeam($this);

    expect(trashScrKelolaCounts($this))->toBe([3, '3', 3]);

    trashScrTrash($this, $this->keisha);

    expect(trashScrKelolaCounts($this))->toBe([2, '2', 2]);
});

// A trashed teammate ---------------------------------------------------------------------------------------------------

it('lists the team on Lomba saya without a blank row for a trashed teammate', function (): void {
    $registration = trashScrTeam($this);
    trashScrTrash($this, $this->keisha);

    $page = trashScrLombaSaya($this->nadia, $registration);
    $detail = $page->viewData('detail');
    $card = collect($page->viewData('cards'))->firstWhere('id', (string) $registration->getKey());

    expect(collect($detail['members'])->pluck('name')->all())->toBe(['Nadia Putri Anindya', 'Dimas Arya Nugroho'])
        ->and($detail['typeLabel'])->toBe('Tim 2 orang')
        ->and($card['meta'])->toEndWith('Tim 2 orang')
        // Keisha never answered: the team still waits (ConfirmMembership counts her row until the purge).
        ->and($detail['waitingText'])->toBe('Menunggu konfirmasi anggota tim.');
});

it('lists the team in Pendaftaran lomba and Undangan tim without a blank row for a trashed teammate', function (): void {
    $registration = trashScrTeam($this);
    trashScrTrash($this, $this->keisha);

    $status = trashScrPanel($this->nadia, $this->lomba)->viewData('status');
    $undangan = trashScrUndangan($this->dimas, $registration)->viewData('members');

    expect(collect($status['team'])->pluck('name')->all())->toBe(['Nadia Putri Anindya', 'Dimas Arya Nugroho'])
        ->and($status['membersText'])->toBe('1 dari 1 anggota sudah konfirmasi')
        // Her row still holds its place until the purge (InviteMembers counts it), so no slot opens.
        ->and($status['slotsLeft'])->toBe(0)
        ->and($status['teamShort'])->toBeFalse()
        ->and(collect($undangan)->pluck('name')->all())->toBe(['Nadia Putri Anindya', 'Dimas Arya Nugroho']);
});

it('counts a trashed teammate out of "Tim N" on the Beranda', function (): void {
    trashScrTeam($this);
    trashScrTrash($this, $this->keisha);

    $mine = Livewire::actingAs(F::user($this->nadia))->test(Beranda::class)->viewData('mine');

    expect($mine)->toHaveCount(1)
        ->and($mine[0]['meta'])->toEndWith('Tim 2')
        ->and($mine[0]['label'])->toBe(RegistrationStatus::MenungguKonfirmasi->label());
});

// A trashed ketua ------------------------------------------------------------------------------------------------------

it('hides the registration of a trashed ketua from Lomba saya and brings it back on "Pulihkan"', function (): void {
    $registration = trashScrTeam($this);

    expect(trashScrLombaSaya($this->dimas)->viewData('counts'))->toBe(['berjalan' => 1, 'selesai' => 0]);

    $entry = trashScrTrash($this, $this->nadia);

    $page = trashScrLombaSaya($this->dimas);
    expect($page->viewData('cards'))->toBe([])
        ->and($page->viewData('counts'))->toBe(['berjalan' => 0, 'selesai' => 0])
        ->and($page->viewData('detail'))->toBeNull();
    $this->actingAs(F::user($this->dimas))->get(route('siswa.lomba-saya.show', $registration))->assertNotFound();

    app(RestoreFromTrash::class)->handle($this->admin, $entry);

    expect(trashScrLombaSaya($this->dimas)->viewData('counts'))->toBe(['berjalan' => 1, 'selesai' => 0]);
});

it('hides the invitation and the registration of a trashed ketua from the Beranda', function (): void {
    trashScrTeam($this);

    $invites = fn (): array => collect(Livewire::actingAs(F::user($this->keisha))->test(Beranda::class)->viewData('actions'))
        ->filter(fn (array $a): bool => str_starts_with($a['key'], 'invite-'))->values()->all();
    $dimasMine = fn (): array => Livewire::actingAs(F::user($this->dimas))->test(Beranda::class)->viewData('mine');

    expect($invites())->toHaveCount(1)->and($dimasMine())->toHaveCount(1);

    trashScrTrash($this, $this->nadia);

    expect($invites())->toBe([])->and($dimasMine())->toBe([]);
});

it('hides the registration of a trashed ketua from the Pendaftaran lomba panel', function (): void {
    trashScrTeam($this);

    expect(trashScrPanel($this->keisha, $this->lomba)->viewData('status')['pendingInvite'])->toBeTrue();

    trashScrTrash($this, $this->nadia);

    $panel = trashScrPanel($this->keisha, $this->lomba);
    expect($panel->viewData('status'))->toBeNull()
        ->and($panel->viewData('current'))->toBeNull()
        ->and(trashScrPanel($this->dimas, $this->lomba)->viewData('status'))->toBeNull();
});

it('answers the invitation link of a trashed ketua as no longer valid and accepts no answer', function (): void {
    $registration = trashScrTeam($this);
    trashScrTrash($this, $this->nadia);
    $row = fn (): RegistrationMember => RegistrationMember::query()
        ->where('registration_id', $registration->getKey())->where('student_id', $this->keisha->getKey())->firstOrFail();

    $page = trashScrUndangan($this->keisha, $registration);
    $page->assertViewHas('canRespond', false)
        ->assertViewHas('expired', false)
        ->assertSee('Undangan ini sudah tidak berlaku.')
        ->assertDontSee('Ikut tim ini?')
        ->assertDontSee('karena batas pendaftaran lewat');
    expect(collect($page->viewData('members'))->pluck('name')->all())->toBe(['Dimas Arya Nugroho', 'Keisha Larasati']);

    $page->set('phone', '081234567890')->call('ikut')->assertHasErrors(['registration']);
    trashScrUndangan($this->keisha, $registration)->call('tolak')->assertHasErrors(['registration']);

    expect($row()->confirmed_at)->toBeNull()
        ->and($row()->declined_at)->toBeNull()
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKonfirmasi);
});
