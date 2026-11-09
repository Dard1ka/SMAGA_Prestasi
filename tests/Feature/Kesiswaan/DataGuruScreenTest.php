<?php

declare(strict_types=1);

use App\Actions\Users\UpdateGuruData;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Kesiswaan\DataGuru;
use App\Models\StaffProfile;
use App\Models\Student;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Title;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 | SV8, D-197: the page Kesiswaan › Data › Data guru (App\Livewire\Kesiswaan\DataGuru, `kesiswaan.guru.index`), also in
 | the Admin menu: the guru list with its markers, search and filter, the "Ubah data" dialog (password first, D-128, stale
 | screens as toasts) and "Tambah guru". Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    Notification::fake();
    $this->withoutVite();
    config(['fortify.sth.require_staff_2fa' => false]);
    $this->admin = A::admin();
    $this->kesiswaan = A::staff(RoleName::Kesiswaan, ['name' => 'Dra. Wulan Sari']);
    session(A::passwordConfirmed());
});

/** A guru account with the given staff profile values (fictional NIP), or no profile at all (null). */
function dgsGuru(string $name, ?array $profile = []): User
{
    $guru = A::staff(RoleName::Guru, ['name' => $name]);

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

function dgsScreen(User $actor): Testable
{
    return Livewire::actingAs($actor)->test(DataGuru::class);
}

// --- Access and menu --------------------------------------------------------------------------------------------------

it('opens Data guru for Kesiswaan and Admin, nobody else', function (): void {
    dgsGuru('Drs. Bambang Hartono, M.Pd.');

    foreach ([$this->kesiswaan, $this->admin] as $staff) {
        $this->actingAs($staff)->get(route('kesiswaan.guru.index'))
            ->assertOk()
            ->assertSee('Data guru')
            ->assertSee('Drs. Bambang Hartono, M.Pd.')
            ->assertSee('Tambah guru');
    }

    foreach ([RoleName::TataUsaha, RoleName::KepalaSekolah, RoleName::Humas, RoleName::Guru] as $role) {
        $this->actingAs(A::staff($role))->get(route('kesiswaan.guru.index'))->assertForbidden();
    }

    // An active student who finished the activation: the role check answers.
    $student = Student::factory()->create()->user;
    $student->forceFill(['status' => UserStatus::Active, 'must_change_password' => false, 'password_changed_at' => now(), 'email' => 'nadia.siswa@example.test', 'email_verified_at' => now()])->save();
    $this->actingAs($student->fresh())->get(route('kesiswaan.guru.index'))->assertForbidden();

    auth()->logout();
    $this->get(route('kesiswaan.guru.index'))->assertRedirect(route('login'));
});

it('is refused to a Kesiswaan account whose guru.update was taken away', function (): void {
    Role::findByName(RoleName::Kesiswaan->value)->revokePermissionTo(Permission::GuruUpdate->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    dgsScreen($this->kesiswaan->fresh())->assertForbidden();
});

it('lists Data guru in the Data menu of Kesiswaan and Admin, named as its page', function (string $role): void {
    $nav = Navigation::for(['name' => 'X', 'roles' => [$role]], $role, [], 'kesiswaan.guru.index');
    $items = collect($nav['sections'])->firstWhere('label', 'Data')['items'];
    $item = collect($items)->firstWhere('key', 'guru');
    $keys = array_column($items, 'key');
    $title = (new ReflectionClass(DataGuru::class))->getAttributes(Title::class)[0]->getArguments()[0];

    expect($nav['role'])->toBe($role)
        ->and($item['label'])->toBe($title)
        ->and($item['href'])->toBe(route('kesiswaan.guru.index'))
        ->and($item['current'])->toBeTrue()
        ->and(collect($nav['sections'])->flatMap(fn (array $s) => $s['items'])->where('current', true))->toHaveCount(1)
        // Admin › Kelas stays right before Import siswa.
        ->and(array_search('kelas', $keys, true) + 1)->toBe(array_search('import', $keys, true));
})->with(['kesiswaan', 'admin']);

// --- The list ---------------------------------------------------------------------------------------------------------

it('lists guru accounts only, with a marker for the empty letter fields', function (): void {
    dgsGuru('Drs. Bambang Hartono, M.Pd.');
    dgsGuru('Retno Wulandari, S.Pd.', ['nip' => null, 'rank' => 'Penata, III/c', 'position' => '']);
    dgsGuru('Agus Setiawan, S.Kom.', null);
    $trashed = dgsGuru('Guru Di Tempat Sampah');
    $trashed->delete();
    A::staff(RoleName::Humas, ['name' => 'Staf Humas Contoh']);

    $screen = dgsScreen($this->kesiswaan)
        ->assertSee('Drs. Bambang Hartono, M.Pd.')
        ->assertSee('197503152003121004')
        ->assertSee('Pembina, IV/a')
        ->assertSee('Guru Mata Pelajaran Fisika')
        ->assertSee('Retno Wulandari, S.Pd.')
        ->assertSee('Belum ada: NIP, Jabatan')
        ->assertSee('Agus Setiawan, S.Kom.')
        ->assertSee('Belum ada: NIP, Pangkat/golongan, Jabatan')
        ->assertSee('2 guru belum lengkap NIP, pangkat/golongan, atau jabatannya.')
        ->assertSee('Data guru, 3 guru')
        ->assertDontSee('Guru Di Tempat Sampah')
        ->assertDontSee('Staf Humas Contoh')
        ->assertDontSee('Dra. Wulan Sari');

    expect($screen->get('shownIds'))->toHaveCount(3);
});

it('finds a guru by name or by NIP (spaces typed too) and filters the incomplete ones', function (): void {
    dgsGuru('Drs. Bambang Hartono, M.Pd.');
    dgsGuru('Retno Wulandari, S.Pd.', ['nip' => '198206172010012009', 'position' => null]);

    dgsScreen($this->kesiswaan)
        ->set('q', 'retno')
        ->assertSee('Retno Wulandari, S.Pd.')
        ->assertDontSee('Drs. Bambang Hartono, M.Pd.')
        ->set('q', '1975 0315')
        ->assertSee('Drs. Bambang Hartono, M.Pd.')
        ->assertDontSee('Retno Wulandari, S.Pd.')
        ->set('q', '')
        ->set('filter', DataGuru::FILTER_INCOMPLETE)
        ->assertSee('Retno Wulandari, S.Pd.')
        ->assertDontSee('Drs. Bambang Hartono, M.Pd.')
        ->set('filter', 'apa-saja')
        ->assertSet('filter', '')
        ->set('q', 'tidak ada yang cocok')
        ->assertSee('Tidak ada guru yang cocok');
});

it('shows PER_PAGE gurus a page', function (): void {
    foreach (range(1, DataGuru::PER_PAGE + 1) as $i) {
        dgsGuru(sprintf('Guru Contoh %s', str_repeat('a', $i)), ['nip' => null]);
    }

    $screen = dgsScreen($this->kesiswaan);

    expect($screen->get('shownIds'))->toHaveCount(DataGuru::PER_PAGE);
});

// --- "Ubah data" ------------------------------------------------------------------------------------------------------

it('asks for the password before the dialog opens', function (): void {
    $guru = dgsGuru('Drs. Bambang Hartono, M.Pd.');
    session()->forget('auth.password_confirmed_at');

    dgsScreen($this->kesiswaan)->call('edit', (int) $guru->getKey())
        ->assertRedirect(route('password.confirm'))
        ->assertSet('editId', null)
        ->assertNotDispatched('sth-modal-open');
});

it('opens the dialog only for a guru the page listed', function (): void {
    $guru = dgsGuru('Drs. Bambang Hartono, M.Pd.');
    $humas = A::staff(RoleName::Humas);

    dgsScreen($this->kesiswaan)->call('edit', (int) $humas->getKey())->assertNotFound();
    dgsScreen($this->kesiswaan)->call('edit', (int) $this->kesiswaan->getKey())->assertNotFound();

    $screen = dgsScreen($this->kesiswaan)->call('edit', (int) $guru->getKey())
        ->assertSet('editId', (int) $guru->getKey())
        ->assertSet('dataName', 'Drs. Bambang Hartono, M.Pd.')
        ->assertSet('dataRank', 'Pembina, IV/a')
        ->assertSet('dataNip', '197503152003121004')
        ->assertSet('dataPosition', 'Guru Mata Pelajaran Fisika')
        ->assertDispatched('sth-modal-open', id: DataGuru::DIALOG)
        ->assertSee('Ubah data guru')
        ->assertSee('Simpan perubahan');

    expect($screen->get('shown'))->toBe(UpdateGuruData::snapshot($guru));
});

it('saves the dialog and says so', function (): void {
    $guru = dgsGuru('Drs. Bambang Hartono, M.Pd.', ['nip' => null, 'position' => null]);

    dgsScreen($this->kesiswaan)->call('edit', (int) $guru->getKey())
        ->set('dataNip', '1975 0315 2003 1210 04')
        ->set('dataPosition', 'Guru Mata Pelajaran Fisika')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editId', null)
        ->assertDispatched('sth-modal-close', id: DataGuru::DIALOG)
        ->assertDispatched('sth-toast', message: DataGuru::MSG_SAVED, tone: 'success')
        ->assertSee('197503152003121004')
        ->assertDontSee('Belum ada:');

    $profile = StaffProfile::query()->where('user_id', $guru->getKey())->firstOrFail();

    expect($profile->nip)->toBe('197503152003121004')
        ->and($profile->position)->toBe('Guru Mata Pelajaran Fisika')
        ->and(Activity::query()->where('event', UpdateGuruData::EVENT)->sole()->properties['fields'])->toBe(['nip', 'position']);
});

it('shows the field errors, says when nothing changed, and does nothing without an open question (D-128)', function (): void {
    $guru = dgsGuru('Drs. Bambang Hartono, M.Pd.');

    dgsScreen($this->kesiswaan)->call('edit', (int) $guru->getKey())
        ->set('dataNip', '12345')
        ->set('dataName', '')
        ->call('save')
        ->assertHasErrors(['dataNip' => UpdateGuruData::MSG_NIP_FORMAT, 'dataName' => UpdateGuruData::MSG_NAME_REQUIRED])
        ->assertSet('editId', (int) $guru->getKey())
        ->set('dataNip', '197503152003121004')
        ->set('dataName', 'Drs. Bambang Hartono, M.Pd.')
        ->call('save')
        ->assertDispatched('sth-toast', message: DataGuru::MSG_NOTHING, tone: 'info')
        ->assertSet('editId', (int) $guru->getKey())
        ->call('forget')
        ->set('dataRank', 'Penata, III/c')
        ->call('save')
        ->assertNotDispatched('sth-modal-close');

    expect(Activity::query()->where('event', UpdateGuruData::EVENT)->count())->toBe(0)
        ->and(StaffProfile::query()->where('user_id', $guru->getKey())->value('rank'))->toBe('Pembina, IV/a');
});

it('reloads the fields when the data changed meanwhile and closes when the account is no guru any more', function (): void {
    $guru = dgsGuru('Drs. Bambang Hartono, M.Pd.');

    $screen = dgsScreen($this->kesiswaan)->call('edit', (int) $guru->getKey());
    StaffProfile::query()->where('user_id', $guru->getKey())->update(['rank' => 'Pembina Tingkat I, IV/b']);

    $screen->set('dataPosition', 'Guru Madya')
        ->call('save')
        ->assertDispatched('sth-toast', message: UpdateGuruData::MSG_CHANGED, tone: 'warning')
        ->assertSet('dataRank', 'Pembina Tingkat I, IV/b')
        ->assertSet('dataPosition', 'Guru Mata Pelajaran Fisika')
        ->assertSet('editId', (int) $guru->getKey());

    $guru->syncRoles([RoleName::Humas->value]);

    $screen->set('dataPosition', 'Guru Madya')
        ->call('save')
        ->assertDispatched('sth-toast', message: UpdateGuruData::MSG_GONE, tone: 'warning')
        ->assertDispatched('sth-modal-close', id: DataGuru::DIALOG)
        ->assertSet('editId', null)
        ->assertDontSee('Drs. Bambang Hartono, M.Pd.');

    expect(Activity::query()->where('event', UpdateGuruData::EVENT)->count())->toBe(0);
});

// --- "Tambah guru" ----------------------------------------------------------------------------------------------------

it('adds a guru from the page, asking for the password first', function (): void {
    session()->forget('auth.password_confirmed_at');

    dgsScreen($this->kesiswaan)->call('openCreate')
        ->assertRedirect(route('password.confirm'))
        ->assertNotDispatched('sth-modal-open');

    session(A::passwordConfirmed());

    dgsScreen($this->kesiswaan)->call('openCreate')
        ->assertDispatched('sth-modal-open', id: DataGuru::DIALOG_NEW)
        ->set('new.name', 'Retno Wulandari, S.Pd.')
        ->set('new.username', 'retno.wulandari')
        ->set('new.nip', '198206172010012009')
        ->set('new.rank', 'Penata, III/c')
        ->set('new.position', 'Guru Mata Pelajaran Biologi')
        ->set('new.password', 'Sementara#2026guru')
        ->set('new.password_confirmation', 'Sementara#2026guru')
        ->call('createGuru')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-close', id: DataGuru::DIALOG_NEW)
        ->assertDispatched('sth-toast', message: DataGuru::MSG_CREATED, tone: 'success')
        ->assertSet('new.password', '')
        ->assertSee('Retno Wulandari, S.Pd.');

    $guru = User::query()->where('username', 'retno.wulandari')->sole();

    expect($guru->getRoleNames()->all())->toBe([RoleName::Guru->value])
        ->and($guru->staffProfile->nip)->toBe('198206172010012009');
});

it('shows every problem of "Tambah guru" at once and never takes a role from the browser', function (): void {
    dgsScreen($this->kesiswaan)
        ->set('new.name', 'Retno Wulandari, S.Pd.')
        ->set('new.username', 'retno.wulandari')
        ->set('new.nip', '1982')
        ->set('new.password', 'Sementara#2026guru')
        ->set('new.password_confirmation', 'Sementara#2026lain')
        ->call('createGuru')
        ->assertHasErrors(['new.password' => DataGuru::MSG_PASSWORD_MISMATCH, 'new.nip' => UpdateGuruData::MSG_NIP_FORMAT]);

    dgsScreen($this->kesiswaan)
        ->set('new', ['name' => 'Retno Wulandari, S.Pd.', 'username' => 'retno.wulandari', 'role' => RoleName::Admin->value, 'password' => 'Sementara#2026guru', 'password_confirmation' => 'Sementara#2026guru'])
        ->call('createGuru')
        ->assertHasNoErrors();

    expect(User::query()->where('username', 'retno.wulandari')->sole()->getRoleNames()->all())->toBe([RoleName::Guru->value]);
});

it('hides and refuses "Tambah guru" without guru.create', function (): void {
    Role::findByName(RoleName::Kesiswaan->value)->revokePermissionTo(Permission::GuruCreate->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $kesiswaan = $this->kesiswaan->fresh();

    dgsScreen($kesiswaan)->assertOk()
        ->assertDontSee('Tambah guru')
        ->assertDontSeeHtml('wire:click="openCreate"')
        ->call('openCreate')
        ->assertForbidden();
    dgsScreen($kesiswaan)->call('createGuru')->assertForbidden();
});
