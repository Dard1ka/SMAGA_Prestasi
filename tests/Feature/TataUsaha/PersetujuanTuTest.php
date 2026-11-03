<?php

declare(strict_types=1);

use App\Actions\Registrations\ApproveRegistration;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\RejectRegistration;
use App\Enums\Permission;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Livewire\Kepsek\Persetujuan;
use App\Models\Registration;
use App\Notifications\Approval\RegistrationApproved;
use App\Notifications\Registrations\RegistrationAwaitingPrincipal;
use App\Notifications\Registrations\RegistrationCancelled;
use App\Providers\NavigationServiceProvider;
use App\Support\ApprovalQueue;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
| D-150 (FS1 Paket B): the Kepala Sekolah OR Tata Usaha approves a registration (registration.approve), "Ganti guru"
| included (D-132). Tata Usaha opens the same screen under its own address tu.persetujuan.index; Admin still never
| approves (D-01).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    $this->kepsek = F::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
    $this->tu = F::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani, S.E.']);
});

it('opens Persetujuan for Kepala Sekolah and Tata Usaha, never Admin (role × route)', function (): void {
    $this->actingAs($this->kepsek)->get('/kepsek/persetujuan')->assertOk();
    $this->actingAs($this->tu)->get('/tu/persetujuan')->assertOk()->assertSee('Persetujuan lomba')->assertSee('Pilih semua');

    // Each approver keeps its own area: the other address is not theirs.
    $this->actingAs($this->kepsek)->get('/tu/persetujuan')->assertForbidden();
    $this->actingAs($this->tu)->get('/kepsek/persetujuan')->assertForbidden();

    foreach ([RoleName::Admin, RoleName::Kesiswaan, RoleName::Humas, RoleName::Guru] as $role) {
        $staff = F::staff($role);
        $this->actingAs($staff)->get('/tu/persetujuan')->assertForbidden();
        $this->actingAs($staff)->get('/kepsek/persetujuan')->assertForbidden();
    }

    $this->actingAs(F::activeStudent('Nadia Putri Anindya')->user)->get('/tu/persetujuan')->assertForbidden();
    auth()->logout();
    $this->get('/tu/persetujuan')->assertRedirect(route('login'));
});

it('lets Tata Usaha approve, reject and replace the guru', function (): void {
    Notification::fake();
    $chosen = F::mentor('Retno Wulandari, S.Pd.');
    $other = F::mentor('Hendra Kusuma, S.Pd., M.Si.');
    $waiting = F::registration(mentor: $chosen);

    expect($this->tu->can('decide', $waiting))->toBeTrue()
        ->and($this->tu->can('changeMentor', $waiting))->toBeTrue();

    app(ApproveRegistration::class)->handle($this->tu, $waiting, null, $other->getKey());

    $waiting->refresh();
    expect($waiting->status)->toBe(RegistrationStatus::Disetujui)
        ->and((int) $waiting->principal_decision_by)->toBe((int) $this->tu->getKey())
        ->and((int) $waiting->mentor_id)->toBe((int) $other->getKey());
    Notification::assertSentTo($other, RegistrationApproved::class, fn (RegistrationApproved $n) => $n->audience === 'guru');
    // The approving Tata Usaha is not told about its own decision.
    Notification::assertNotSentTo($this->tu, RegistrationApproved::class);

    $second = F::registration(names: ['Dimas Arya Nugroho']);
    app(RejectRegistration::class)->handle($this->tu, $second, 'Jadwal bentrok dengan penilaian tengah semester.');

    expect($second->fresh()->status)->toBe(RegistrationStatus::Ditolak)
        ->and((int) $second->fresh()->principal_decision_by)->toBe((int) $this->tu->getKey());
});

it('tells the other Tata Usaha accounts about an approval by one of them', function (): void {
    Notification::fake();
    $colleague = F::staff(RoleName::TataUsaha, ['name' => 'Dewi Lestari, S.Kom.']);

    app(ApproveRegistration::class)->handle($this->tu, F::registration());

    Notification::assertSentTo($colleague, RegistrationApproved::class, fn (RegistrationApproved $n) => $n->audience === 'tu');
    Notification::assertNotSentTo($this->tu, RegistrationApproved::class);
});

it('tells every holder of registration.approve about a waiting registration, with a link to their own screen', function (): void {
    $admin = F::staff(RoleName::Admin);
    $registration = F::registration();

    $approvers = CreateRegistration::principals()->modelKeys();
    expect($approvers)->toContain($this->kepsek->getKey())
        ->toContain($this->tu->getKey())
        ->not->toContain($admin->getKey());

    $awaiting = new RegistrationAwaitingPrincipal($registration);
    expect($awaiting->toArray($this->tu)['url'])->toEndWith('/tu/persetujuan')
        ->and($awaiting->toArray($this->kepsek)['url'])->toEndWith('/kepsek/persetujuan')
        ->and($awaiting->toMail($this->tu)->actionUrl)->toEndWith('/tu/persetujuan');

    $cancelled = RegistrationCancelled::for($registration, 'kepsek');
    expect($cancelled->toArray($this->tu)['url'])->toEndWith('/tu/persetujuan')
        ->and($cancelled->toArray($this->kepsek)['url'])->toEndWith('/kepsek/persetujuan')
        ->and($cancelled->toMail($this->tu)->actionUrl)->toEndWith('/tu/persetujuan');
});

it('lets the first of Kepala Sekolah and Tata Usaha decide and warns the second', function (): void {
    Notification::fake();
    $r = F::registration();

    $kepsek = Livewire::actingAs($this->kepsek)->test(Persetujuan::class);
    $tu = Livewire::actingAs($this->tu)->test(Persetujuan::class);

    // Each screen acts as its own account (Livewire's test client uses whoever is signed in at the call).
    $this->actingAs($this->kepsek);
    $kepsek->call('approve', $r->getKey())->assertDispatched('sth-toast', message: 'Pendaftaran disetujui. Masuk antrean TU.');
    $this->actingAs($this->tu);
    $tu->call('approve', $r->getKey())
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Pendaftaran ini sudah diputuskan. Daftar sudah diperbarui.');

    expect(Activity::query()->where('event', 'registration.approved')->count())->toBe(1)
        ->and((int) $r->fresh()->principal_decision_by)->toBe((int) $this->kepsek->getKey());
    Notification::assertSentToTimes($r->leader->user, RegistrationApproved::class, 1);
});

it('keeps Admin without registration.approve (D-01)', function (): void {
    expect(F::staff(RoleName::Admin)->can(Permission::RegistrationApprove->value))->toBeFalse()
        ->and(RoleName::TataUsaha->defaultPermissions())->toContain(Permission::RegistrationApprove)
        ->and(RoleName::Admin->defaultPermissions())->not->toContain(Permission::RegistrationApprove);
});

it('shows the Persetujuan lomba count in the TU menu', function (): void {
    F::registration();
    F::registration(RegistrationStatus::Disetujui, ['Dimas Arya Nugroho']);

    expect(Navigation::badgeKeys('tata_usaha'))->toContain('kepsek.persetujuan')
        ->and(NavigationServiceProvider::badges($this->tu, 'tata_usaha')['kepsek.persetujuan'])->toBe(1);

    $this->actingAs($this->tu)->get(route('tu.beranda'))->assertOk()->assertSee('Persetujuan lomba')
        ->assertSee(route('tu.persetujuan.index'), false);
});

it('sends each approver to its own queue', function (): void {
    $both = F::staff(RoleName::KepalaSekolah);
    $both->assignRole(RoleName::TataUsaha->value);

    expect(ApprovalQueue::routeName($this->tu))->toBe('tu.persetujuan.index')
        ->and(ApprovalQueue::routeName($this->kepsek))->toBe('kepsek.persetujuan.index')
        ->and(ApprovalQueue::routeName($both->fresh()))->toBe('kepsek.persetujuan.index')
        ->and(ApprovalQueue::url($this->tu))->toEndWith('/tu/persetujuan');
});
