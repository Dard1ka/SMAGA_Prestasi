<?php

declare(strict_types=1);

use App\Actions\Registrations\ApproveRegistration;
use App\Actions\Registrations\ApproveRevision;
use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\RejectRevision;
use App\Actions\Registrations\RequestRevision;
use App\Actions\Registrations\RespondToRevision;
use App\Actions\Registrations\RevisionRules;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RevisionStatus;
use App\Enums\RoleName;
use App\Livewire\Sekolah\RevisiPendaftaran;
use App\Livewire\Siswa\LombaSaya\UbahPendaftaran;
use App\Livewire\Siswa\UndanganRevisi;
use App\Models\Registration;
use App\Models\RegistrationRevision;
use App\Models\Student;
use App\Providers\NavigationServiceProvider;
use App\Support\Navigation;
use App\Support\RevisionQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| SV8, D-199: the screens of the registration revision: the ketua's card on Lomba saya (UbahPendaftaran), the link of
| the Pendaftaran lomba panel, the invited student's page (Siswa › Undangan revisi) and "Revisi pendaftaran" of Tata
| Usaha and Kesiswaan with its count badge. Dialogs ask first (D-128); a stale screen answers with a toast. Fictional
| people only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    F::withoutStaffTwoFactor();
    Notification::fake();
    $this->hendra = F::staff(RoleName::Guru, 'Hendra Kusuma, S.Pd., M.Si.');
    $this->retno = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $this->kepsek = F::staff(RoleName::KepalaSekolah, 'Drs. Bambang Hartono, M.Pd.');
    $this->tu = F::staff(RoleName::TataUsaha, 'Sri Handayani');
    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Siti Rahmawati, S.Pd.');
    $this->admin = F::staff(RoleName::Admin, 'Operator STH');
    $this->humas = F::staff(RoleName::Humas, 'Dewi Kartika');
    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $this->keisha = F::student('Keisha Larasati', 'XI-6');
    $this->lomba = F::teamCompetition(4, ['name' => 'Lomba Karya Tulis Ilmiah Nasional 2026']);
});

/** Nadia + Dimas, both confirmed: waiting for the approval; approved when $approve. */
function rvsTeam(object $t, bool $approve = false): Registration
{
    $registration = app(CreateRegistration::class)->handle(F::user($t->nadia), $t->lomba, RegistrationType::Tim, [(string) $t->dimas->getKey()], $t->hendra->id);
    app(ConfirmMembership::class)->handle(F::user($t->dimas), $registration->fresh());

    if ($approve) {
        app(ApproveRegistration::class)->handle($t->kepsek, $registration->fresh());
    }

    return $registration->fresh();
}

function rvsRevision(object $t, Registration $registration, array $add = [], ?int $mentor = null): RegistrationRevision
{
    return app(RequestRevision::class)->handle(
        F::user($t->nadia),
        $registration,
        array_map(fn (Student $s): string => (string) $s->getKey(), $add),
        [],
        $mentor,
        'Guru pembimbing pindah tugas ke sekolah lain.',
    );
}

// Routes and menus -------------------------------------------------------------------------------------------------------

it('opens Revisi pendaftaran for Tata Usaha and Kesiswaan only, each under its own address', function (): void {
    $this->actingAs($this->tu)->get(route('tu.revisi.index'))->assertOk()->assertSee('Revisi pendaftaran');
    $this->actingAs($this->kesiswaan)->get(route('kesiswaan.revisi.index'))->assertOk()->assertSee('Tidak ada revisi yang menunggu');

    // Admin reaches the Kesiswaan address through role:kesiswaan|admin, and the permission refuses it.
    $this->actingAs($this->admin)->get(route('kesiswaan.revisi.index'))->assertForbidden();
    $this->actingAs($this->kesiswaan)->get(route('tu.revisi.index'))->assertForbidden();
    $this->actingAs($this->tu)->get(route('kesiswaan.revisi.index'))->assertForbidden();

    foreach ([$this->kepsek, $this->humas, $this->hendra, F::user($this->nadia)] as $other) {
        $this->actingAs($other)->get(route('tu.revisi.index'))->assertForbidden();
        $this->actingAs($other)->get(route('kesiswaan.revisi.index'))->assertForbidden();
    }

    Livewire::actingAs($this->admin)->test(RevisiPendaftaran::class)->assertForbidden();
});

it('counts the revisions ready for a decision in both menus', function (): void {
    $waitingForKeisha = rvsRevision($this, rvsTeam($this, true), [$this->keisha]);
    $bayu = F::student('Bayu Pratama', 'XI-2');
    $citra = F::student('Citra Ayu', 'XI-5');
    rvsRevision((object) ['nadia' => $bayu], rvsTeam((object) ['nadia' => $bayu, 'dimas' => $citra, 'hendra' => $this->hendra, 'kepsek' => $this->kepsek, 'lomba' => $this->lomba], true), [], $this->retno->id);

    expect(NavigationServiceProvider::badges($this->tu, 'tata_usaha')[RevisionQueue::BADGE])->toBe(1)
        ->and(NavigationServiceProvider::badges($this->kesiswaan, 'kesiswaan')[RevisionQueue::BADGE])->toBe(1)
        ->and(NavigationServiceProvider::badges($this->admin, 'kesiswaan'))->not->toHaveKey(RevisionQueue::BADGE)
        ->and(collect(Navigation::for($this->tu, 'tata_usaha', [], 'tu.revisi.index')['sections'])->flatMap(fn (array $s) => $s['items'])->firstWhere('key', 'revisi')['badge'])->toBe(1)
        ->and($waitingForKeisha->fresh()->ready_at)->toBeNull();
});

// Revisi pendaftaran (staff) ----------------------------------------------------------------------------------------------

it('approves a revision after the question and says what happens', function (): void {
    $revision = rvsRevision($this, rvsTeam($this, true), [], $this->retno->id);

    $screen = Livewire::actingAs($this->tu)->test(RevisiPendaftaran::class)
        ->assertSee('Lomba Karya Tulis Ilmiah Nasional 2026')
        ->assertSee('Guru pembimbing pindah tugas ke sekolah lain.')
        ->assertSee('Setujui');

    // Without the question nothing happens.
    $screen->call('approve');
    expect($revision->fresh()->status)->toBe(RevisionStatus::Pending);

    $screen->call('askApprove', (string) $revision->getKey())
        ->assertDispatched('sth-modal-open', id: RevisiPendaftaran::APPROVE_DIALOG)
        ->assertSee('Guru pembimbing yang diganti tidak diberi tahu.')
        ->call('approve')
        ->assertDispatched('sth-toast', fn (string $event, array $params): bool => $params['tone'] === 'success' && str_starts_with($params['message'], 'Revisi disetujui.'))
        ->assertDispatched('sth-badges');

    expect($revision->fresh()->status)->toBe(RevisionStatus::Approved)
        ->and((int) $revision->registration()->first()->mentor_id)->toBe((int) $this->retno->id);
});

it('rejects a revision only with a note', function (): void {
    $revision = rvsRevision($this, rvsTeam($this, true), [], $this->retno->id);

    Livewire::actingAs($this->kesiswaan)->test(RevisiPendaftaran::class)
        ->call('askReject', (string) $revision->getKey())
        ->assertDispatched('sth-modal-open', id: RevisiPendaftaran::REJECT_DIALOG)
        ->call('reject')
        ->assertHasErrors(['note'])
        ->set('note', 'Guru pembimbing masih bisa mendampingi.')
        ->call('reject')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', fn (string $event, array $params): bool => $params['message'] === 'Revisi ditolak. Ketua tim diberi tahu beserta alasannya.');

    expect($revision->fresh()->status)->toBe(RevisionStatus::Rejected)
        ->and($revision->fresh()->note)->toBe('Guru pembimbing masih bisa mendampingi.');
});

it('offers only "Tolak" while an invited student has not accepted', function (): void {
    $revision = rvsRevision($this, rvsTeam($this, true), [$this->keisha]);

    Livewire::actingAs($this->tu)->test(RevisiPendaftaran::class)
        ->assertSee('Menunggu siswa yang ditambah menerima undangan.')
        ->assertSee('Keisha Larasati')
        ->assertDontSee('askApprove')
        ->call('askApprove', (string) $revision->getKey())
        ->assertDispatched('sth-toast', fn (string $event, array $params): bool => str_starts_with($params['message'], ApproveRevision::MSG_WAITING))
        ->assertNotDispatched('sth-modal-open');
});

it('answers a revision decided elsewhere with a toast, and a forged id with 404', function (): void {
    $revision = rvsRevision($this, rvsTeam($this, true), [], $this->retno->id);
    $screen = Livewire::actingAs($this->tu)->test(RevisiPendaftaran::class);

    app(ApproveRevision::class)->handle($this->kesiswaan, $revision);

    $screen->call('askReject', (string) $revision->getKey())
        ->assertDispatched('sth-toast', fn (string $event, array $params): bool => $params['tone'] === 'warning'
            && $params['message'] === 'Revisi ini sudah disetujui oleh Siti Rahmawati, S.Pd. (Kesiswaan). Daftar sudah diperbarui.')
        ->assertNotDispatched('sth-modal-open');

    // The question open while another account decided: the confirm step gets the same sentence.
    $other = rvsRevision((object) ['nadia' => $b = F::student('Bayu Pratama', 'XI-2')], rvsTeam((object) ['nadia' => $b, 'dimas' => F::student('Citra Ayu', 'XI-5'), 'hendra' => $this->hendra, 'kepsek' => $this->kepsek, 'lomba' => $this->lomba], true), [], $this->retno->id);
    $screen = Livewire::actingAs($this->tu)->test(RevisiPendaftaran::class)->call('askApprove', (string) $other->getKey());
    app(RejectRevision::class)->handle($this->kesiswaan, $other, 'Tidak perlu diganti.');
    $screen->call('approve')
        ->assertDispatched('sth-toast', fn (string $event, array $params): bool => str_starts_with($params['message'], 'Revisi ini sudah ditolak oleh Siti Rahmawati, S.Pd. (Kesiswaan).'));

    Livewire::actingAs($this->tu)->test(RevisiPendaftaran::class)
        ->call('askReject', '01JZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertStatus(404);
});

it('lists decided revisions under Riwayat', function (): void {
    $revision = rvsRevision($this, rvsTeam($this, true), [], $this->retno->id);
    app(RejectRevision::class)->handle($this->tu, $revision, 'Guru masih bisa mendampingi.');

    Livewire::actingAs($this->kesiswaan)->withQueryParams(['tab' => 'riwayat'])->test(RevisiPendaftaran::class)
        ->assertSee('Ditolak')
        ->assertSee('Sri Handayani (Tata Usaha)')
        ->assertSee('Guru masih bisa mendampingi.');
});

// Lomba saya (the ketua) --------------------------------------------------------------------------------------------------

it('lets the ketua change the team directly before the approval', function (): void {
    $registration = rvsTeam($this);

    $this->actingAs(F::user($this->nadia))->get(route('siswa.lomba-saya.show', $registration))->assertOk()->assertSee('Ubah pendaftaran');
    // An anggota does not get the card.
    $this->actingAs(F::user($this->dimas))->get(route('siswa.lomba-saya.show', $registration))->assertOk()->assertDontSee('Ubah pendaftaran');
    // The Pendaftaran lomba panel links to it.
    $this->actingAs(F::user($this->nadia))->get(route('siswa.lomba.show', $this->lomba))->assertOk()->assertSee('Ubah pendaftaran');

    Livewire::actingAs(F::user($this->nadia))->test(UbahPendaftaran::class, ['registrationId' => (string) $registration->getKey()])
        ->call('open')
        ->assertDispatched('sth-modal-open', id: UbahPendaftaran::DIALOG)
        ->set('q', 'Keisha')
        ->call('tambahAnggota', (string) $this->keisha->getKey())
        ->assertSee('Siswa yang ditambah langsung diundang dan perlu konfirmasi.')
        ->assertSee('Pendaftaran kembali menunggu konfirmasi anggota baru')
        ->call('simpan')
        ->assertHasNoErrors()
        ->assertDispatched(UbahPendaftaran::CHANGED)
        ->assertDispatched('sth-toast', fn (string $event, array $params): bool => $params['message'] === 'Pendaftaran diubah. Pendaftaran menunggu konfirmasi anggota baru.');

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKonfirmasi)
        ->and($registration->members()->where('student_id', $this->keisha->getKey())->exists())->toBeTrue();

    Livewire::actingAs(F::user($this->dimas))->test(UbahPendaftaran::class, ['registrationId' => (string) $registration->getKey()])
        ->assertStatus(404);
});

it('shows the refusal of a team rule in the form', function (): void {
    $registration = rvsTeam($this);

    Livewire::actingAs(F::user($this->nadia))->test(UbahPendaftaran::class, ['registrationId' => (string) $registration->getKey()])
        ->call('open')
        ->set('keluarkan', [(string) $this->dimas->getKey()])
        ->call('simpan')
        ->assertHasErrors(['tambah'])
        ->assertSee(RevisionRules::MSG_TOO_FEW)
        ->set('keluarkan', [])
        ->call('simpan')
        ->assertHasErrors(['changes']);
});

it('sends a revision after the approval and lets the ketua cancel it', function (): void {
    $registration = rvsTeam($this, true);

    $card = Livewire::actingAs(F::user($this->nadia))->test(UbahPendaftaran::class, ['registrationId' => (string) $registration->getKey()])
        ->assertSee('Ajukan revisi')
        ->call('open')
        ->set('guru', (string) $this->retno->id)
        ->set('alasan', 'Pendek')
        ->call('simpan')
        ->assertHasErrors(['alasan'])
        ->set('alasan', 'Pak Hendra pindah tugas ke sekolah lain.')
        ->call('simpan')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', fn (string $event, array $params): bool => $params['message'] === 'Revisi terkirim ke Tata Usaha dan Kesiswaan.')
        ->assertSee('Revisi menunggu keputusan')
        ->assertSee('Pak Hendra pindah tugas ke sekolah lain.');

    $revision = RegistrationRevision::query()->sole();
    expect($revision->mentorTo())->toBe((int) $this->retno->id)
        ->and((int) $registration->fresh()->mentor_id)->toBe((int) $this->hendra->id);

    $card->call('cancelRevision');
    expect($revision->fresh()->status)->toBe(RevisionStatus::Pending);

    $card->call('askCancelRevision')
        ->assertDispatched('sth-modal-open', id: UbahPendaftaran::CANCEL_DIALOG)
        ->call('cancelRevision')
        ->assertDispatched('sth-toast', fn (string $event, array $params): bool => $params['message'] === 'Revisi dibatalkan. Tim yang sudah disetujui tetap berlaku.')
        ->assertSee('Revisi terakhir dibatalkan');

    expect($revision->fresh()->status)->toBe(RevisionStatus::Cancelled);
});

it('draws the form again when the registration changed after it was opened', function (): void {
    $registration = rvsTeam($this);

    $card = Livewire::actingAs(F::user($this->nadia))->test(UbahPendaftaran::class, ['registrationId' => (string) $registration->getKey()])
        ->call('open')
        ->set('guru', (string) $this->retno->id);

    // Approved in another tab meanwhile: the direct change is gone, the form becomes a revision.
    app(ApproveRegistration::class)->handle($this->kepsek, $registration->fresh());

    $card->call('simpan')
        ->assertDispatched('sth-toast', fn (string $event, array $params): bool => $params['tone'] === 'warning' && $params['message'] === UbahPendaftaran::MSG_STALE)
        ->assertSee('Kirim revisi');

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->hendra->id)
        ->and(RegistrationRevision::query()->count())->toBe(0);
});

it('shows the last decision with its note to the ketua', function (): void {
    $registration = rvsTeam($this, true);
    $revision = rvsRevision($this, $registration, [], $this->retno->id);
    app(RejectRevision::class)->handle($this->tu, $revision, 'Guru pembimbing masih bisa mendampingi.');

    Livewire::actingAs(F::user($this->nadia))->test(UbahPendaftaran::class, ['registrationId' => (string) $registration->getKey()])
        ->assertSee('Revisi terakhir ditolak Tata Usaha')
        ->assertSee('Alasan: Guru pembimbing masih bisa mendampingi.')
        ->assertSee('Ajukan revisi');
});

// Undangan revisi (the invited student) -----------------------------------------------------------------------------------

it('lets only the invited student open and answer the invitation', function (): void {
    $revision = rvsRevision($this, rvsTeam($this, true), [$this->keisha]);
    $rizky = F::student('Rizky Maulana', 'XI-1');

    $this->actingAs(F::user($this->keisha))->get(route('siswa.undangan.revisi', $revision))->assertOk()->assertSee('Terima undangan')
        ->assertSee('Guru pembimbing pindah tugas ke sekolah lain.');
    $this->actingAs(F::user($rizky))->get(route('siswa.undangan.revisi', $revision))->assertNotFound();
    $this->actingAs(F::user($this->dimas))->get(route('siswa.undangan.revisi', $revision))->assertNotFound();

    Livewire::actingAs(F::user($this->keisha))->test(UndanganRevisi::class, ['revision' => $revision])
        ->set('phone', '081234567890')
        ->call('terima')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast')
        ->assertSee('Kamu sudah menerima undangan ini.');

    expect($revision->fresh()->answerOf((string) $this->keisha->getKey()))->toBe('confirmed')
        ->and($revision->fresh()->ready_at)->not->toBeNull();
});

it('ends the revision when the invited student declines on the page', function (): void {
    $revision = rvsRevision($this, rvsTeam($this, true), [$this->keisha]);

    Livewire::actingAs(F::user($this->keisha))->test(UndanganRevisi::class, ['revision' => $revision])
        ->call('tolak')
        ->assertDispatched('sth-toast', fn (string $event, array $params): bool => $params['message'] === 'Undangan ditolak. Ketua tim diberi tahu.')
        ->assertSee('Kamu sudah menolak undangan ini.');

    expect($revision->fresh()->status)->toBe(RevisionStatus::Cancelled);
});

it('lists an open revision invitation under "Perlu tindakan" on the Beranda until it is answered', function (): void {
    $revision = rvsRevision($this, rvsTeam($this, true), [$this->keisha]);

    $this->actingAs(F::user($this->keisha))->get(route('siswa.beranda'))->assertOk()
        ->assertSee('Undangan tim · Lomba Karya Tulis Ilmiah Nasional 2026')
        ->assertSee(route('siswa.undangan.revisi', $revision), false);

    app(RespondToRevision::class)->confirm(F::user($this->keisha), $revision);

    $this->actingAs(F::user($this->keisha))->get(route('siswa.beranda'))->assertOk()
        ->assertDontSee(route('siswa.undangan.revisi', $revision), false);
});
