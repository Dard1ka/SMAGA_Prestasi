<?php

declare(strict_types=1);

use App\Actions\Users\ApplyPrincipalReplacement;
use App\Actions\Users\CancelPrincipalReplacement;
use App\Actions\Users\ChangeStaffRole;
use App\Actions\Users\CreateStaffAccount;
use App\Actions\Users\RequestPrincipalReplacement;
use App\Actions\Users\ResetStaffAccount;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Livewire\Admin\Pengaturan\Pengguna;
use App\Models\PrincipalReplacement;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Admin\PrincipalReplacementNotice;
use App\Notifications\Admin\StaffAccountChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();
    $this->admin = A::admin();
});

function sthStaffData(array $override = []): array
{
    return array_merge([
        'name' => 'Ibu Dwi Rahayu',
        'username' => 'dwi.rahayu',
        'email' => 'dwi.rahayu@example.test',
        'role' => RoleName::Kesiswaan->value,
        'password' => 'Sementara-2026!x',
        'nip' => '19800101 200501 2 001',
    ], $override);
}

// Staff accounts ------------------------------------------------------------------------------------

it('creates a staff account that must change its temporary password at first login', function (): void {
    $user = app(CreateStaffAccount::class)->handle($this->admin, sthStaffData());

    expect($user->hasRole(RoleName::Kesiswaan->value))->toBeTrue()
        ->and($user->status)->toBe(UserStatus::PendingActivation)
        ->and($user->must_change_password)->toBeTrue()
        ->and(Hash::check('Sementara-2026!x', $user->password))->toBeTrue()
        ->and($user->staffProfile()->first()->nip)->toBe('19800101 200501 2 001')
        ->and(Activity::query()->where('event', 'account.staff_created')->sole()->causer_id)->toBe($this->admin->id);

    Notification::assertSentTo($this->admin, StaffAccountChanged::class);
});

it('refuses bad staff data, the Kepala Sekolah and siswa roles and a fourth Admin', function (array $override, string $field): void {
    if ($field === 'role' && ($override['role'] ?? null) === RoleName::Admin->value) {
        A::admin(['username' => 'operator2']);
        A::admin(['username' => 'operator3']);
    }

    try {
        app(CreateStaffAccount::class)->handle($this->admin, sthStaffData($override));
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
})->with([
    'username with capitals' => [['username' => 'Dwi Rahayu'], 'username'],
    'username taken' => [['username' => 'operator'], 'username'],
    'kepala sekolah role' => [['role' => RoleName::KepalaSekolah->value], 'role'],
    'siswa role' => [['role' => RoleName::Siswa->value], 'role'],
    'fourth admin' => [['role' => RoleName::Admin->value], 'role'],
    'short password' => [['password' => 'pendek'], 'password'],
]);

it('lets only user.manage create staff accounts', function (): void {
    expect(fn () => app(CreateStaffAccount::class)->handle(A::staff(RoleName::Kesiswaan), sthStaffData()))
        ->toThrow(AuthorizationException::class);
});

it('changes a staff role, revokes the sessions and audits it', function (): void {
    $guru = A::staff(RoleName::Guru);
    H::fakeSessions($guru, 2);

    app(ChangeStaffRole::class)->handle($this->admin, $guru, RoleName::Humas, 'Pindah tugas ke humas.');

    expect($guru->fresh()->getRoleNames()->all())->toBe([RoleName::Humas->value])
        ->and(H::sessionCount($guru))->toBe(0)
        ->and(Activity::query()->where('event', 'account.role_changed')->sole()->properties['from'])->toBe([RoleName::Guru->value]);
    Notification::assertSentTo($guru, StaffAccountChanged::class);
});

it('never changes roles of the Kepala Sekolah, students or yourself', function (): void {
    $kepsek = A::staff(RoleName::KepalaSekolah);
    $student = H::activeStudent()->user->fresh();

    expect(fn () => app(ChangeStaffRole::class)->handle($this->admin, $kepsek, RoleName::Guru, 'x'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ChangeStaffRole::class)->handle($this->admin, $student, RoleName::Guru, 'x'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ChangeStaffRole::class)->handle($this->admin, $this->admin, RoleName::Guru, 'x'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ChangeStaffRole::class)->handle($this->admin, A::staff(RoleName::Guru), RoleName::KepalaSekolah, 'x'))->toThrow(ValidationException::class);
});

it('resets a staff account: temporary password, 2FA removed, email to re-verify, sessions revoked', function (): void {
    $tu = A::staff(RoleName::TataUsaha);
    $tu->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_recovery_codes' => encrypt('[]')])->save();
    H::fakeSessions($tu, 2);

    app(ResetStaffAccount::class)->handle($this->admin, $tu, 'Sementara-2026!y', 'HP hilang.');

    $tu->refresh();
    expect(Hash::check('Sementara-2026!y', $tu->password))->toBeTrue()
        ->and($tu->must_change_password)->toBeTrue()
        ->and($tu->two_factor_secret)->toBeNull()
        ->and($tu->two_factor_confirmed_at)->toBeNull()
        ->and($tu->email_verified_at)->toBeNull()
        ->and($tu->status)->toBe(UserStatus::PendingActivation)
        ->and(H::sessionCount($tu))->toBe(0)
        ->and(Activity::query()->where('event', 'account.staff_reset')->sole()->properties['reason'])->toBe('HP hilang.');

    expect(fn () => app(ResetStaffAccount::class)->handle($this->admin, H::activeStudent()->user->fresh(), 'Sementara-2026!y', 'x'))
        ->toThrow(ValidationException::class);
});

// Ganti Kepala Sekolah ------------------------------------------------------------------------------

it('schedules the Kepala Sekolah replacement 24 hours ahead and notifies both accounts and the Admins', function (): void {
    $old = A::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
    $new = A::staff(RoleName::Guru, ['name' => 'Dra. Sri Wahyuni, M.Pd.']);

    $replacement = app(RequestPrincipalReplacement::class)->handle($this->admin, $new, 'SK Kepala Sekolah baru.');

    expect($replacement->isPending())->toBeTrue()
        ->and($replacement->effective_at->greaterThan(now()->addHours(23)))->toBeTrue()
        ->and($old->fresh()->hasRole(RoleName::KepalaSekolah->value))->toBeTrue()
        ->and($new->fresh()->hasRole(RoleName::KepalaSekolah->value))->toBeFalse();
    Notification::assertSentTo([$old, $new, $this->admin], PrincipalReplacementNotice::class);

    // Nothing happens before the delay …
    expect(app(ApplyPrincipalReplacement::class)->applyDue())->toBe(0);

    // … and after it the role moves, the old account (no role left) is disabled, the signature is detached.
    Setting::put(SettingKey::SignatureFileId, '01JTESTSIGNATURE0000000000');
    $this->travel(25)->hours();
    expect(app(ApplyPrincipalReplacement::class)->applyDue())->toBe(1)
        ->and($new->fresh()->hasRole(RoleName::KepalaSekolah->value))->toBeTrue()
        ->and($old->fresh()->hasRole(RoleName::KepalaSekolah->value))->toBeFalse()
        ->and($old->fresh()->status)->toBe(UserStatus::Disabled)
        ->and(Setting::get(SettingKey::SignerName))->toBe('Dra. Sri Wahyuni, M.Pd.')
        ->and(Setting::get(SettingKey::SignatureFileId))->toBeNull()
        ->and($replacement->fresh()->applied_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'principal.applied')->count())->toBe(1);

    // Idempotent.
    expect(app(ApplyPrincipalReplacement::class)->applyDue())->toBe(0);
});

it('applies at once when there is no Kepala Sekolah yet', function (): void {
    $new = A::staff(RoleName::Guru);

    $replacement = app(RequestPrincipalReplacement::class)->handle($this->admin, $new, 'Instalasi baru.');

    expect($replacement->applied_at)->not->toBeNull()
        ->and($new->fresh()->hasRole(RoleName::KepalaSekolah->value))->toBeTrue();
});

it('refuses a second pending request, Admin targets and students', function (): void {
    A::staff(RoleName::KepalaSekolah);
    app(RequestPrincipalReplacement::class)->handle($this->admin, A::staff(RoleName::Guru), 'SK.');

    expect(fn () => app(RequestPrincipalReplacement::class)->handle($this->admin, A::staff(RoleName::Humas), 'SK.'))->toThrow(ValidationException::class)
        ->and(fn () => app(RequestPrincipalReplacement::class)->handle($this->admin, A::admin(['username' => 'operator2']), 'SK.'))->toThrow(ValidationException::class)
        ->and(fn () => app(RequestPrincipalReplacement::class)->handle($this->admin, H::activeStudent()->user->fresh(), 'SK.'))->toThrow(ValidationException::class)
        ->and(fn () => app(RequestPrincipalReplacement::class)->handle(A::staff(RoleName::Kesiswaan), A::staff(RoleName::Guru), 'SK.'))->toThrow(AuthorizationException::class);
});

it('lets the current Kepala Sekolah cancel a pending replacement, but not other staff', function (): void {
    $old = A::staff(RoleName::KepalaSekolah);
    $replacement = app(RequestPrincipalReplacement::class)->handle($this->admin, A::staff(RoleName::Guru), 'SK.');

    expect(fn () => app(CancelPrincipalReplacement::class)->handle(A::staff(RoleName::Kesiswaan), $replacement, 'Bukan saya.'))
        ->toThrow(AuthorizationException::class);

    app(CancelPrincipalReplacement::class)->handle($old, $replacement, 'Saya belum pensiun.');

    $this->travel(25)->hours();
    expect($replacement->fresh()->cancelled_at)->not->toBeNull()
        ->and(app(ApplyPrincipalReplacement::class)->applyDue())->toBe(0)
        ->and($old->fresh()->hasRole(RoleName::KepalaSekolah->value))->toBeTrue()
        ->and(Activity::query()->where('event', 'principal.cancelled')->sole()->causer_id)->toBe($old->id);
});

it('cancels a due replacement whose target is no longer eligible', function (): void {
    A::staff(RoleName::KepalaSekolah);
    $new = A::staff(RoleName::Guru);
    $replacement = app(RequestPrincipalReplacement::class)->handle($this->admin, $new, 'SK.');
    $new->forceFill(['status' => UserStatus::Disabled])->save();

    $this->travel(25)->hours();

    expect(app(ApplyPrincipalReplacement::class)->applyDue())->toBe(0)
        ->and($replacement->fresh()->cancelled_at)->not->toBeNull()
        ->and($new->fresh()->hasRole(RoleName::KepalaSekolah->value))->toBeFalse();
});

// Screen --------------------------------------------------------------------------------------------

it('creates staff and finds students by exact NIS/NISN on the Pengguna tab', function (): void {
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());
    $student = H::activeStudent();

    Livewire::test(Pengguna::class)
        ->set('new', ['name' => 'Mas Galih Prasetyo', 'username' => 'galih.p', 'email' => '', 'role' => 'humas', 'honorific' => 'Mas', 'nip' => '', 'rank' => '', 'position' => '', 'password' => 'Sementara-2026!z', 'password_confirmation' => 'Sementara-2026!z'])
        ->call('createStaff')
        ->assertHasNoErrors()
        ->assertSee('Mas Galih Prasetyo')
        ->set('studentQuery', substr(H::NIS, 0, 6))
        ->call('findStudent')
        ->assertHasErrors('studentQuery')
        ->set('studentQuery', H::NISN)
        ->call('findStudent')
        ->assertHasNoErrors()
        ->assertSet('studentUserId', $student->user_id)
        ->assertSee($student->user->name)
        ->assertDontSee(H::NIS);

    expect(User::query()->where('username', 'galih.p')->sole()->hasRole('humas'))->toBeTrue();
});

it('shows field errors of the create form under the new.* inputs', function (): void {
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(Pengguna::class)
        ->set('new.name', 'X')
        ->set('new.username', 'operator')
        ->set('new.password', 'Sementara-2026!z')
        ->set('new.password_confirmation', 'Sementara-2026!z')
        ->call('createStaff')
        ->assertHasErrors(['new.name', 'new.username']);
});

it('resets a student account from the Pengguna tab with a reason', function (): void {
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());
    $student = H::activeStudent();

    Livewire::test(Pengguna::class)
        ->set('studentQuery', H::NIS)
        ->call('findStudent')
        ->call('openStudent', 'siswa-reset')
        ->assertDispatched('sth-modal-open', id: 'siswa-reset')
        ->call('resetStudent')
        ->assertHasErrors('studentReason')
        ->set('studentReason', 'Siswa datang ke TU.')
        ->call('resetStudent')
        ->assertHasNoErrors();

    expect($student->user->fresh()->status)->toBe(UserStatus::PendingActivation)
        ->and($student->user->fresh()->email)->toBeNull();
});

it('keeps the target ids locked against tampering', function (): void {
    $this->actingAs($this->admin);

    expect(fn () => Livewire::test(Pengguna::class)->set('targetId', $this->admin->id))
        ->toThrow(Exception::class);
    expect(fn () => Livewire::test(Pengguna::class)->set('studentUserId', $this->admin->id))
        ->toThrow(Exception::class);
});

it('requests the Kepala Sekolah replacement from the Pengguna tab', function (): void {
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());
    A::staff(RoleName::KepalaSekolah);
    $new = A::staff(RoleName::Guru, ['name' => 'Dra. Sri Wahyuni, M.Pd.']);

    Livewire::test(Pengguna::class)
        ->call('openPrincipal')
        ->set('principalTarget', (string) $new->id)
        ->set('principalReason', 'SK Kepala Sekolah baru.')
        ->call('requestPrincipal')
        ->assertHasNoErrors()
        ->assertSee('Penggantian Kepala Sekolah dijadwalkan');

    expect(PrincipalReplacement::query()->pending()->count())->toBe(1);
});
