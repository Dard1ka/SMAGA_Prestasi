<?php

declare(strict_types=1);

use App\Actions\Registrations\ApproveRegistration;
use App\Actions\Registrations\RejectRegistration;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Livewire\Kepsek\Persetujuan;
use App\Models\Registration;
use App\Notifications\Approval\RegistrationApproved;
use App\Notifications\Approval\RegistrationRejected;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    $this->kepsek = F::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
});

// Access ------------------------------------------------------------------------------------------

it('opens the approval queue for the Kepala Sekolah and Tata Usaha only', function (): void {
    $this->actingAs($this->kepsek)->get('/kepsek/persetujuan')->assertOk()->assertSee('Pilih semua');
    // D-150: Tata Usaha opens the same screen under its own address.
    $this->actingAs(F::staff(RoleName::TataUsaha))->get('/tu/persetujuan')->assertOk()->assertSee('Pilih semua');

    foreach ([RoleName::Admin, RoleName::Humas, RoleName::Kesiswaan, RoleName::Guru] as $role) {
        $this->actingAs(F::staff($role))->get('/kepsek/persetujuan')->assertForbidden();
    }

    $siswa = F::activeStudent('Nadia Putri Anindya');
    $this->actingAs($siswa->user)->get('/kepsek/persetujuan')->assertForbidden();
});

it('sends guests to the login page', function (): void {
    $this->get('/kepsek/persetujuan')->assertRedirect(route('login'));
    $this->get('/kepsek')->assertRedirect(route('login'));
});

it('lets only the Kepala Sekolah and Tata Usaha decide a waiting registration (Admin denied, D-01)', function (): void {
    $waiting = F::registration();
    $approved = F::registration(RegistrationStatus::Disetujui);

    expect($this->kepsek->can('decide', $waiting))->toBeTrue()
        ->and($this->kepsek->can('decide', $approved))->toBeFalse()
        ->and(F::staff(RoleName::Admin)->can('decide', $waiting))->toBeFalse()
        ->and(F::staff(RoleName::Admin)->can('viewApprovalQueue', Registration::class))->toBeFalse()
        ->and(F::staff(RoleName::TataUsaha)->can('decide', $waiting))->toBeTrue()
        ->and(F::staff(RoleName::TataUsaha)->can('decide', $approved))->toBeFalse();
});

// Actions -----------------------------------------------------------------------------------------

it('approves a team registration and notifies members, mentor and Tata Usaha', function (): void {
    Notification::fake();
    $mentor = F::mentor();
    $tu = F::staff(RoleName::TataUsaha);
    $registration = F::registration(names: ['Nadia Putri Anindya', 'Dimas Arya Nugroho'], mentor: $mentor);

    app(ApproveRegistration::class)->handle($this->kepsek, $registration);

    $registration->refresh();
    expect($registration->status)->toBe(RegistrationStatus::Disetujui)
        ->and($registration->principal_decision_by)->toBe($this->kepsek->getKey())
        ->and($registration->principal_decided_at)->not->toBeNull();

    $members = ApproveRegistration::memberUsers($registration);
    expect($members)->toHaveCount(2);

    Notification::assertSentTo($members, RegistrationApproved::class, fn (RegistrationApproved $n) => $n->audience === 'siswa');
    Notification::assertSentTo($mentor, RegistrationApproved::class, fn (RegistrationApproved $n) => $n->audience === 'guru');
    Notification::assertSentTo($tu, RegistrationApproved::class, fn (RegistrationApproved $n) => $n->audience === 'tu');

    expect(Activity::query()->where('event', 'registration.approved')->where('subject_id', $registration->getKey())->exists())->toBeTrue();
});

it('rejects with a required reason and tells the students and the guru pembimbing', function (): void {
    Notification::fake();
    $mentor = F::mentor();
    $registration = F::registration(mentor: $mentor);

    expect(fn () => app(RejectRegistration::class)->handle($this->kepsek, $registration, '  '))
        ->toThrow(ValidationException::class);
    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);

    app(RejectRegistration::class)->handle($this->kepsek, $registration, 'Jadwal bentrok dengan penilaian tengah semester.');

    $registration->refresh();
    expect($registration->status)->toBe(RegistrationStatus::Ditolak)
        ->and($registration->principal_note)->toBe('Jadwal bentrok dengan penilaian tengah semester.');

    Notification::assertSentTo(ApproveRegistration::memberUsers($registration), RegistrationRejected::class, fn (RegistrationRejected $n) => $n->audience === 'siswa');
    Notification::assertSentTo($mentor, RegistrationRejected::class, fn (RegistrationRejected $n) => $n->audience === 'guru');
    Notification::assertNotSentTo($this->kepsek, RegistrationRejected::class);
    expect(Activity::query()->where('event', 'registration.rejected')->exists())->toBeTrue();
});

it('refuses Admin and other staff in the Actions', function (): void {
    $registration = F::registration();

    foreach ([RoleName::Admin, RoleName::Kesiswaan, RoleName::Humas, RoleName::Guru] as $role) {
        $actor = F::staff($role);
        expect(fn () => app(ApproveRegistration::class)->handle($actor, $registration))->toThrow(AuthorizationException::class);
        expect(fn () => app(RejectRegistration::class)->handle($actor, $registration, 'Alasan yang cukup panjang.'))->toThrow(AuthorizationException::class);
    }

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
});

it('does not decide a registration twice or after the leader cancelled', function (): void {
    $approved = F::registration(RegistrationStatus::Disetujui);
    $cancelled = F::registration(RegistrationStatus::Dibatalkan);
    $unconfirmed = F::registration(RegistrationStatus::MenungguKonfirmasi);

    foreach ([$approved, $cancelled, $unconfirmed] as $registration) {
        expect(fn () => app(ApproveRegistration::class)->handle($this->kepsek, $registration))->toThrow(ValidationException::class);
        expect(fn () => app(RejectRegistration::class)->handle($this->kepsek, $registration, 'Alasan yang cukup panjang.'))->toThrow(ValidationException::class);
    }

    expect($approved->fresh()->status)->toBe(RegistrationStatus::Disetujui)
        ->and($cancelled->fresh()->status)->toBe(RegistrationStatus::Dibatalkan);
});

// Screen ------------------------------------------------------------------------------------------

it('lists waiting registrations with members, mentor and filters by bidang', function (): void {
    $mentor = F::mentor('Retno Wulandari, S.Pd.');
    F::registration(names: ['Nadia Putri Anindya', 'Dimas Arya Nugroho'], mentor: $mentor, competition: ['name' => 'Lomba Peneliti Belia (LPB) 2026', 'bidang' => 'riset']);
    F::registration(names: ['Hana Safira'], competition: ['name' => 'OSN Tingkat Kota Semarang 2026', 'bidang' => 'sains']);
    $approved = F::registration(RegistrationStatus::Disetujui, ['Raka Pradipta'], competition: ['name' => 'NSIF sudah disetujui']);

    $component = Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->assertSee('Lomba Peneliti Belia (LPB) 2026')
        ->assertSee('Nadia Putri Anindya')
        ->assertSee('Pak Retno Wulandari, S.Pd.')
        ->assertSee('OSN Tingkat Kota Semarang 2026')
        ->assertSee('2 menunggu');

    expect($component->viewData('rows')->modelKeys())->not->toContain($approved->getKey());

    $component->set('bidang', 'sains')->assertSee('1 menunggu');
    expect($component->viewData('rows')->map(fn ($r) => $r->competition->name)->all())->toBe(['OSN Tingkat Kota Semarang 2026']);

    $component->set('bidang', 'bukan-bidang')->assertSet('bidang', '');
});

it('approves one row and keeps it visible as decided', function (): void {
    Notification::fake();
    $registration = F::registration(competition: ['name' => 'OSN Tingkat Kota Semarang 2026']);

    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->call('approve', $registration->getKey())
        ->assertDispatched('sth-toast')
        ->assertSee('Disetujui · masuk antrean TU')
        ->assertSet('recent', [$registration->getKey() => 'ok']);

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Disetujui);
});

it('bulk-approves only ticked rows that are still waiting', function (): void {
    Notification::fake();
    $a = F::registration(names: ['Nadia Putri Anindya']);
    $b = F::registration(names: ['Hana Safira']);
    $c = F::registration(names: ['Fajar Ramadhan']);
    $done = F::registration(RegistrationStatus::Ditolak, ['Laras Wening Pangestu']);

    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->set('selected', [$a->getKey(), $b->getKey(), $done->getKey(), 'bukan-ulid'])
        ->call('approveSelected')
        ->assertSet('selected', []);

    expect($a->fresh()->status)->toBe(RegistrationStatus::Disetujui)
        ->and($b->fresh()->status)->toBe(RegistrationStatus::Disetujui)
        ->and($c->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek)
        ->and($done->fresh()->status)->toBe(RegistrationStatus::Ditolak);
});

it('requires a reason before sending a rejection from the screen', function (): void {
    Notification::fake();
    $registration = F::registration();

    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->call('openReject', $registration->getKey())
        ->assertSet('rejecting', $registration->getKey())
        ->call('reject')
        ->assertHasErrors(['rejectNote'])
        ->set('rejectNote', 'Jadwal bentrok dengan penilaian tengah semester.')
        ->call('reject')
        ->assertHasNoErrors()
        ->assertSee('Ditolak · siswa diberi tahu')
        ->assertSee('Jadwal bentrok dengan penilaian tengah semester.');

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Ditolak);
});

it('answers 404 for rows that are not in the queue', function (): void {
    $approved = F::registration(RegistrationStatus::Disetujui);

    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->call('approve', $approved->getKey())
        ->assertNotFound();
});

// QA5 (2026-10-07, QA Q1-27): an online lomba says "Daring" once, not "Daring · Daring" -------------------------

it('names an online lomba once, and keeps the place of an offline one', function (): void {
    F::registration(names: ['Farhan Aditya'], competition: ['name' => 'Lomba Peneliti Belia (LPB) 2026', 'mode' => 'daring', 'location' => 'Daring']);
    F::registration(names: ['Hana Safira'], competition: ['name' => 'OSN Tingkat Kota Semarang 2026', 'mode' => 'luring', 'location' => 'Kota Semarang']);

    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->assertDontSee('Daring · Daring')
        ->assertSee('Daring')
        ->assertSee('Luring · Kota Semarang');
});

// QA6: explore round 3 after PR 14 (2026-10-07) ----------------------------------------------------

it('asks before a bulk approve and lists what it will approve (QA6, #65 Q1-10)', function (): void {
    Notification::fake();
    $a = F::registration(names: ['Nadia Putri Anindya']);
    $b = F::registration(names: ['Hana Safira']);

    $component = Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->assertSeeHtml('data-sth-open="setujui-terpilih"')
        ->assertDontSeeHtml('wire:click="approveSelected"')
        ->set('selected', [$a->getKey(), $b->getKey()])
        ->assertSee('Setujui 2 pendaftaran?')
        ->assertSeeHtml('wire:click="approveSelected"');

    expect($component->viewData('selectedRows')->modelKeys())->toEqualCanonicalizing([$a->getKey(), $b->getKey()]);

    $component->call('approveSelected')->assertDispatched('sth-modal-close', id: 'setujui-terpilih');

    expect($a->fresh()->status)->toBe(RegistrationStatus::Disetujui)
        ->and($b->fresh()->status)->toBe(RegistrationStatus::Disetujui);
});

it('names the student on every checkbox, so two rows of one lomba are told apart (QA6)', function (): void {
    F::registration(names: ['Nadia Putri Anindya']);
    F::registration(names: ['Hana Safira']);

    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->assertSeeHtml('aria-label="Pilih Nadia Putri Anindya, National Science and Invention Fair (NSIF) 2026"')
        ->assertSeeHtml('aria-label="Pilih Hana Safira, National Science and Invention Fair (NSIF) 2026"');
});
