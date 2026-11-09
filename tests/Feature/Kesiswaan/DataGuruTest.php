<?php

declare(strict_types=1);

use App\Actions\Letters\LetterContent;
use App\Actions\Letters\LetterDraft;
use App\Actions\Users\CreateStaffAccount;
use App\Actions\Users\UpdateGuruData;
use App\Enums\Permission;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\AuditLog;
use App\Models\StaffProfile;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Admin\StaffAccountChanged;
use App\Support\Audit\SecurityEventSubscriber;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
 | SV8, D-197 "Data guru": App\Actions\Users\UpdateGuruData (Nama, Pangkat/golongan, NIP, Jabatan of ONE guru account, what
 | the guru letter prints under "Kepada"), CreateStaffAccount::handleGuru() ("Tambah guru", the role guru only), the
 | UserPolicy abilities and the two new grants (guru.update, guru.create: Kesiswaan and Admin). Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    Notification::fake();
    $this->admin = A::admin();
    $this->kesiswaan = A::staff(RoleName::Kesiswaan, ['name' => 'Dra. Wulan Sari']);
});

/** A guru account with a complete staff profile (fictional NIP), the given profile values, or no profile at all (null). */
function dgGuru(?array $profile = [], array $user = []): User
{
    $guru = A::staff(RoleName::Guru, array_merge(['name' => 'Drs. Bambang Hartono, M.Pd.'], $user));

    if ($profile !== null) {
        StaffProfile::query()->create(array_merge([
            'user_id' => $guru->getKey(),
            'honorific' => 'Pak',
            'nip' => '197503152003121004',
            'rank' => 'Pembina, IV/a',
            'position' => 'Guru Mata Pelajaran Fisika',
        ], $profile));
    }

    return $guru->fresh();
}

/** The dialog's input for this guru, with some fields changed. */
function dgInput(User $guru, array $changes = []): array
{
    $profile = StaffProfile::query()->where('user_id', $guru->getKey())->first();

    return array_merge([
        'name' => (string) $guru->name,
        'rank' => (string) $profile?->rank,
        'nip' => (string) $profile?->nip,
        'position' => (string) $profile?->position,
    ], $changes);
}

/** @return array<string, string> the field errors of a refused call */
function dgRefusal(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return array_map(fn (array $m): string => $m[0], $e->errors());
    }

    throw new RuntimeException('The call was not refused.');
}

function dgAudit(): Collection
{
    return Activity::query()->where('event', UpdateGuruData::EVENT)->get();
}

function dgUpdate(User $actor, User $guru, array $changes = [], ?array $shown = null): array
{
    return app(UpdateGuruData::class)->handle($actor, $guru, dgInput($guru, $changes), $shown);
}

// --- Role matrix and policy -------------------------------------------------------------------------------------------

it('grants guru.update and guru.create to Admin and Kesiswaan only', function (RoleName $role): void {
    $may = in_array($role, [RoleName::Admin, RoleName::Kesiswaan], true);
    $seeded = Role::findByName($role->value, 'web');

    foreach ([Permission::GuruUpdate, Permission::GuruCreate] as $permission) {
        expect(in_array($permission, $role->defaultPermissions(), true))->toBe($may)
            ->and($seeded->hasPermissionTo($permission->value))->toBe($may);
    }
})->with(RoleName::cases());

it('lets Admin and Kesiswaan edit and add guru accounts, nobody else', function (): void {
    $guru = dgGuru();

    foreach ([$this->admin, $this->kesiswaan] as $staff) {
        expect(Gate::forUser($staff)->allows('viewGuruData', User::class))->toBeTrue()
            ->and(Gate::forUser($staff)->allows('updateGuruData', $guru))->toBeTrue()
            ->and(Gate::forUser($staff)->allows('createGuru', User::class))->toBeTrue();
    }

    $others = [A::staff(RoleName::TataUsaha), A::staff(RoleName::KepalaSekolah), A::staff(RoleName::Humas), dgGuru(user: ['name' => 'Retno Wulandari, S.Pd.']), Student::factory()->create()->user];

    foreach ($others as $actor) {
        expect(Gate::forUser($actor)->allows('viewGuruData', User::class))->toBeFalse($actor->getRoleNames()->first())
            ->and(Gate::forUser($actor)->allows('updateGuruData', $guru))->toBeFalse()
            ->and(Gate::forUser($actor)->allows('createGuru', User::class))->toBeFalse();
    }

    // A disabled Kesiswaan account acts on nothing (UserPolicy::before).
    $this->kesiswaan->forceFill(['status' => UserStatus::Disabled])->save();
    expect(Gate::forUser($this->kesiswaan->fresh())->allows('updateGuruData', $guru))->toBeFalse();
});

it('edits guru accounts only: never another role, never an account in the trash', function (): void {
    $kesiswaan = Gate::forUser($this->kesiswaan);
    $student = Student::factory()->create()->user;

    foreach ([RoleName::Kesiswaan, RoleName::TataUsaha, RoleName::KepalaSekolah, RoleName::Humas, RoleName::Admin] as $role) {
        expect($kesiswaan->allows('updateGuruData', A::staff($role)))->toBeFalse($role->value);
    }

    expect($kesiswaan->allows('updateGuruData', $student))->toBeFalse()
        ->and($kesiswaan->allows('updateGuruData', $this->kesiswaan))->toBeFalse();

    $trashed = dgGuru(user: ['name' => 'Retno Wulandari, S.Pd.']);
    $trashed->delete();

    expect($kesiswaan->allows('updateGuruData', $trashed))->toBeFalse();
});

// --- UpdateGuruData ---------------------------------------------------------------------------------------------------

it('saves the changed fields, the NIP without spaces, and audits the field names only', function (): void {
    $guru = dgGuru();
    $user = Activity::query()->where('log_name', 'user')->count();

    $result = dgUpdate($this->kesiswaan, $guru, [
        'name' => '  Dr.  Bambang   Hartono, M.Pd. ',
        'rank' => 'Pembina Tingkat I, IV/b',
        'nip' => '1975 0315 2003 1210 05',
        'position' => 'Wakil Kepala Sekolah Bidang Kesiswaan',
    ]);

    $profile = StaffProfile::query()->where('user_id', $guru->getKey())->firstOrFail();
    $audit = dgAudit();

    expect($result)->toBe(['fields' => ['name', 'rank', 'nip', 'position'], 'profile_created' => false])
        ->and($guru->fresh()->name)->toBe('Dr. Bambang Hartono, M.Pd.')
        ->and($profile->rank)->toBe('Pembina Tingkat I, IV/b')
        ->and($profile->nip)->toBe('197503152003121005')
        ->and($profile->position)->toBe('Wakil Kepala Sekolah Bidang Kesiswaan')
        ->and($profile->honorific)->toBe('Pak')
        ->and($audit)->toHaveCount(1)
        ->and($audit[0]->log_name)->toBe('account')
        ->and((int) $audit[0]->causer_id)->toBe((int) $this->kesiswaan->getKey())
        ->and((int) $audit[0]->subject_id)->toBe((int) $guru->getKey())
        ->and($audit[0]->properties->all())->toBe(['fields' => ['name', 'rank', 'nip', 'position'], 'profile_created' => false])
        // The User model's own log never stores the new name (saveQuietly()).
        ->and(Activity::query()->where('log_name', 'user')->count())->toBe($user);

    $stored = json_encode(Activity::query()->get()->map(fn (Activity $a): array => [$a->properties, $a->description])->all());

    foreach (['Bambang', '197503152003121005', '1975 0315', 'Pembina', 'Wakil Kepala'] as $value) {
        expect($stored)->not->toContain($value);
    }

    Notification::assertNothingSent();
});

it('stores an emptied NIP, rank and position as empty (the letter prints its fallback)', function (): void {
    $guru = dgGuru();

    dgUpdate($this->admin, $guru, ['rank' => ' ', 'nip' => '', 'position' => '']);

    $profile = StaffProfile::query()->where('user_id', $guru->getKey())->firstOrFail();

    expect($profile->nip)->toBeNull()
        ->and($profile->rank)->toBeNull()
        ->and($profile->position)->toBeNull()
        ->and(dgAudit()->first()->properties['fields'])->toBe(['rank', 'nip', 'position']);
});

it('gives a guru without a staff profile one', function (): void {
    $guru = dgGuru(null);

    $result = dgUpdate($this->kesiswaan, $guru, ['nip' => '198206172010012009', 'rank' => 'Penata, III/c']);

    $profile = StaffProfile::query()->where('user_id', $guru->getKey())->firstOrFail();

    expect($result)->toBe(['fields' => ['rank', 'nip'], 'profile_created' => true])
        ->and($profile->nip)->toBe('198206172010012009')
        ->and($profile->rank)->toBe('Penata, III/c')
        ->and($profile->position)->toBeNull()
        ->and(dgAudit()->first()->properties['profile_created'])->toBeTrue();
});

it('checks only the fields that changed: an older NIP with spaces stays as stored', function (): void {
    $guru = dgGuru(['nip' => '1975 0315 2003', 'rank' => null]);

    $result = dgUpdate($this->kesiswaan, $guru, ['rank' => 'Penata Muda, III/a', 'nip' => '197503152003']);

    expect($result['fields'])->toBe(['rank'])
        ->and(StaffProfile::query()->where('user_id', $guru->getKey())->value('nip'))->toBe('1975 0315 2003');
});

it('writes nothing and audits nothing when nothing changed', function (): void {
    $guru = dgGuru();

    expect(dgUpdate($this->kesiswaan, $guru, ['name' => ' Drs. Bambang  Hartono, M.Pd.']))->toBe(['fields' => [], 'profile_created' => false])
        ->and(app(UpdateGuruData::class)->changedFields($guru, dgInput($guru)))->toBe([])
        ->and(dgAudit())->toHaveCount(0);
});

it('refuses invalid values with staff sentences and writes nothing', function (array $changes, string $field, string $message): void {
    $guru = dgGuru();

    expect(app(UpdateGuruData::class)->errors($guru, dgInput($guru, $changes)))->toBe([$field => $message])
        ->and(dgRefusal(fn () => dgUpdate($this->kesiswaan, $guru, $changes)))->toBe([$field => $message])
        ->and($guru->fresh()->name)->toBe('Drs. Bambang Hartono, M.Pd.')
        ->and(StaffProfile::query()->where('user_id', $guru->getKey())->value('nip'))->toBe('197503152003121004')
        ->and(dgAudit())->toHaveCount(0);
})->with([
    'empty name' => [['name' => '   '], 'name', UpdateGuruData::MSG_NAME_REQUIRED],
    'short name' => [['name' => 'Bu'], 'name', UpdateGuruData::MSG_NAME_LENGTH],
    'long name' => [['name' => str_repeat('a', 151)], 'name', UpdateGuruData::MSG_NAME_LENGTH],
    'name with a tag' => [['name' => 'Bambang <b>Hartono</b>'], 'name', UpdateGuruData::MSG_NAME_LETTERS],
    'name with digits' => [['name' => 'Bambang Hartono 2'], 'name', UpdateGuruData::MSG_NAME_LETTERS],
    'NIP too short' => [['nip' => '19750315200312100'], 'nip', UpdateGuruData::MSG_NIP_FORMAT],
    'NIP too long' => [['nip' => '1975031520031210041'], 'nip', UpdateGuruData::MSG_NIP_FORMAT],
    'NIP with a letter' => [['nip' => '19750315200312100A'], 'nip', UpdateGuruData::MSG_NIP_FORMAT],
    'NIP with dots' => [['nip' => '19750315.200312.1.004'], 'nip', UpdateGuruData::MSG_NIP_FORMAT],
    'long rank' => [['rank' => str_repeat('a', 101)], 'rank', UpdateGuruData::MSG_RANK_MAX],
    'long position' => [['position' => str_repeat('a', 151)], 'position', UpdateGuruData::MSG_POSITION_MAX],
]);

it('accepts names with titles, apostrophes and letters outside ASCII', function (string $name): void {
    $guru = dgGuru();

    dgUpdate($this->kesiswaan, $guru, ['name' => $name]);

    expect($guru->fresh()->name)->toBe($name);
})->with(['Dra. Sri Wahyuni, M.Pd.', "Hj. Siti Nur'aini, S.Pd.", 'Agus Setiawan-Putra, S.T., M.Eng.', 'Dr. Nanda Prameswari, S.Si.']);

it('refuses a NIP another staff account holds, also one stored with spaces', function (): void {
    $guru = dgGuru();
    dgGuru(['nip' => '1982 0617 2010 0120 09'], ['name' => 'Retno Wulandari, S.Pd.']);

    expect(dgRefusal(fn () => dgUpdate($this->kesiswaan, $guru, ['nip' => '198206172010012009'])))
        ->toBe(['nip' => UpdateGuruData::MSG_NIP_TAKEN]);
});

it('refuses an account that is no guru, one in the trash, and actors without guru.update', function (): void {
    $guru = dgGuru();
    $humas = A::staff(RoleName::Humas);

    expect(dgRefusal(fn () => app(UpdateGuruData::class)->handle($this->kesiswaan, $humas, ['name' => 'Ganti Nama Humas'])))
        ->toBe([UpdateGuruData::KEY_GURU => UpdateGuruData::MSG_GONE])
        ->and(fn () => dgUpdate(A::staff(RoleName::TataUsaha), $guru, ['rank' => 'Penata, III/c']))->toThrow(AuthorizationException::class)
        ->and(fn () => dgUpdate($guru, $guru, ['rank' => 'Penata, III/c']))->toThrow(AuthorizationException::class);

    $guru->delete();

    expect(dgRefusal(fn () => dgUpdate($this->kesiswaan, $guru, ['rank' => 'Penata, III/c'])))
        ->toBe([UpdateGuruData::KEY_GURU => UpdateGuruData::MSG_GONE])
        ->and(dgAudit())->toHaveCount(0);
});

it('refuses a save on data that changed meanwhile, or on an account that is no guru any more', function (): void {
    $guru = dgGuru();
    $shown = UpdateGuruData::snapshot($guru);

    StaffProfile::query()->where('user_id', $guru->getKey())->update(['rank' => 'Penata Tingkat I, III/d']);

    expect(dgRefusal(fn () => app(UpdateGuruData::class)->handle($this->kesiswaan, $guru, dgInput($guru, ['nip' => '198206172010012009']), $shown)))
        ->toBe([UpdateGuruData::KEY_GURU => UpdateGuruData::MSG_CHANGED])
        ->and(StaffProfile::query()->where('user_id', $guru->getKey())->value('nip'))->toBe('197503152003121004');

    $shown = UpdateGuruData::snapshot($guru->fresh());
    $guru->syncRoles([RoleName::Humas->value]);

    expect(dgRefusal(fn () => app(UpdateGuruData::class)->handle($this->kesiswaan, $guru->fresh(), dgInput($guru, ['nip' => '198206172010012009']), $shown)))
        ->toBe([UpdateGuruData::KEY_GURU => UpdateGuruData::MSG_GONE])
        ->and(dgAudit())->toHaveCount(0);
});

it('has a Log audit label and lands in the security log', function (): void {
    expect(AuditLog::EVENT_LABELS)->toHaveKey(UpdateGuruData::EVENT)
        ->and(AuditLog::EVENT_LABELS[UpdateGuruData::EVENT])->toBe('Data guru diubah')
        ->and(SecurityEventSubscriber::AUDITED_LOGS)->toContain('account');
});

// --- The guru letter reads the edited values ------------------------------------------------------------------------

it('prints the edited name, rank, NIP and position on the next guru letter, "-" for an emptied NIP', function (): void {
    $guru = dgGuru();
    $registration = F::registration(RegistrationStatus::Disetujui, ['Nadia Putri Anindya'], $guru);
    $draft = new LetterDraft(
        number: '400.3.8/313/MRD/X/2026',
        issuedOn: CarbonImmutable::parse('2026-11-09'),
        basisFrom: 'Panitia Lomba Contoh 2026',
        basisNumber: '012/LC/XI/2026',
        basisDate: CarbonImmutable::parse('2026-11-01'),
        scheduleDay: 'Sabtu',
        scheduleDate: '21 November 2026',
        scheduleTime: '07.00 WIB s.d Selesai',
        place: 'Gedung Serbaguna Universitas Contoh',
    );

    dgUpdate($this->kesiswaan, $guru, [
        'name' => 'Dr. Bambang Hartono, M.Pd.',
        'rank' => 'Pembina Tingkat I, IV/b',
        'nip' => '1975 0315 2003 1210 05',
        'position' => 'Guru Madya',
    ]);

    $mentor = LetterContent::build($registration->fresh(), $draft)->mentor;

    expect($mentor['name'])->toBe('Dr. Bambang Hartono, M.Pd.')
        ->and($mentor['rank'])->toBe('Pembina Tingkat I, IV/b')
        ->and($mentor['nip'])->toBe('197503152003121005')
        ->and($mentor['position'])->toBe('Guru Madya');

    dgUpdate($this->kesiswaan, $guru->fresh(), ['nip' => '']);

    expect(LetterContent::build($registration->fresh(), $draft)->mentor['nip'])->toBe('-');
});

// --- "Tambah guru" (CreateStaffAccount::handleGuru) -----------------------------------------------------------------

function dgNewGuru(array $changes = []): array
{
    return array_merge([
        'name' => 'Retno Wulandari, S.Pd.',
        'username' => 'retno.wulandari',
        'email' => '',
        'honorific' => 'Bu',
        'nip' => '1982 0617 2010 0120 09',
        'rank' => 'Penata, III/c',
        'position' => 'Guru Mata Pelajaran Biologi',
        'password' => 'Sementara#2026guru',
    ], $changes);
}

it('adds a guru account with "Tambah guru", always with the role guru, and tells the Admins', function (): void {
    $user = app(CreateStaffAccount::class)->handleGuru($this->kesiswaan, dgNewGuru(['role' => RoleName::Admin->value]));
    $profile = $user->staffProfile()->firstOrFail();
    $audit = Activity::query()->where('event', 'account.staff_created')->sole();

    expect($user->getRoleNames()->all())->toBe([RoleName::Guru->value])
        ->and($user->status)->toBe(UserStatus::PendingActivation)
        ->and((bool) $user->must_change_password)->toBeTrue()
        ->and(Hash::check('Sementara#2026guru', $user->password))->toBeTrue()
        ->and($user->name)->toBe('Retno Wulandari, S.Pd.')
        ->and($profile->nip)->toBe('198206172010012009')
        ->and($profile->rank)->toBe('Penata, III/c')
        ->and($profile->position)->toBe('Guru Mata Pelajaran Biologi')
        ->and($profile->honorific)->toBe('Bu')
        ->and((int) $audit->causer_id)->toBe((int) $this->kesiswaan->getKey())
        ->and($audit->properties['role'])->toBe(RoleName::Guru->value)
        ->and(json_encode($audit->properties))->not->toContain('Sementara#2026guru');

    Notification::assertSentTo($this->admin, StaffAccountChanged::class);
});

it('refuses "Tambah guru" to roles without guru.create', function (RoleName $role): void {
    expect(fn () => app(CreateStaffAccount::class)->handleGuru(A::staff($role), dgNewGuru()))->toThrow(AuthorizationException::class)
        ->and(User::query()->where('username', 'retno.wulandari')->exists())->toBeFalse();
})->with([RoleName::TataUsaha, RoleName::KepalaSekolah, RoleName::Humas, RoleName::Guru]);

it('checks "Tambah guru" with the account rules and the Data guru rules, all at once', function (): void {
    dgGuru();

    $errors = dgRefusal(fn () => app(CreateStaffAccount::class)->handleGuru($this->kesiswaan, dgNewGuru([
        'name' => 'Retno 2',
        'username' => 'Retno Wulandari',
        'nip' => '197503152003121004',
        'password' => 'pendek',
    ])));

    expect($errors)->toHaveKeys(['name', 'username', 'nip', 'password'])
        ->and($errors['name'])->toBe(UpdateGuruData::MSG_NAME_LETTERS)
        ->and($errors['nip'])->toBe(UpdateGuruData::MSG_NIP_TAKEN)
        ->and(dgRefusal(fn () => app(CreateStaffAccount::class)->handleGuru($this->kesiswaan, dgNewGuru(['nip' => '1982061720100120']))))
        ->toBe(['nip' => UpdateGuruData::MSG_NIP_FORMAT])
        ->and(User::query()->where('username', 'retno.wulandari')->exists())->toBeFalse();
});

it('keeps the Admin "Tambah pengguna" as it was: any assignable role, the Admin permission', function (): void {
    $user = app(CreateStaffAccount::class)->handle($this->admin, dgNewGuru(['role' => RoleName::Humas->value, 'nip' => '1982 0617']));

    expect($user->getRoleNames()->all())->toBe([RoleName::Humas->value])
        ->and($user->staffProfile->nip)->toBe('1982 0617')
        ->and(fn () => app(CreateStaffAccount::class)->handle($this->kesiswaan, dgNewGuru(['username' => 'lain', 'role' => RoleName::Guru->value])))
        ->toThrow(AuthorizationException::class);
});
