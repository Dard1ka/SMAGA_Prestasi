<?php

declare(strict_types=1);

use App\Actions\Students\UpdateStudentData;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\Pengaturan\Pengguna;
use App\Livewire\Akun\UbahDataSiswa;
use App\Livewire\TataUsaha\DataSiswa;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 | SV6, D-187: Tata Usaha › Data siswa (students not in the Sampah, search, class filter, 25 per page, "Ubah data" opening
 | the shared dialog for a listed row) and "Ubah data" on Admin › Pengguna & peran › Bantuan akun siswa. Fictional data.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    $this->withoutVite();
    $this->x1 = Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
    $this->x2 = Classroom::factory()->create(['name' => 'X-2', 'level' => 10]);
    $this->tu = A::staff(RoleName::TataUsaha);
    session(A::passwordConfirmed());
});

function dsStudent(string $name, Classroom $classroom, string $nisn, array $user = []): User
{
    $row = Student::factory()->create(['classroom_id' => $classroom->getKey(), 'nisn' => $nisn]);
    $row->user->forceFill(array_merge(['name' => $name], $user))->saveQuietly();

    return User::query()->with('student')->findOrFail($row->user_id);
}

/** @return array<string, array<string, mixed>> the menu items by key */
function dsMenu(string $role, ?string $route = null): array
{
    return collect(Navigation::for(['name' => 'X', 'roles' => [$role]], $role, [], $route)['sections'])
        ->flatMap(fn (array $section): array => $section['items'])
        ->keyBy('key')
        ->all();
}

// --- Route × role ----------------------------------------------------------------------------------------------------

it('opens Data siswa for Tata Usaha only (role × route)', function (): void {
    dsStudent('Nadia Putri Anindya', $this->x1, '0012345670');

    $this->actingAs($this->tu)->get(route('tu.siswa.index'))
        ->assertOk()
        ->assertSee('Data siswa')
        ->assertSee('Nadia Putri Anindya')
        ->assertSee('Ubah data')
        // One search box per screen (D-90) and the shared dialog exactly once.
        ->assertDontSee('id="cari-atas"', false)
        ->assertSee('id="'.UbahDataSiswa::DIALOG.'"', false);

    foreach ([RoleName::Admin, RoleName::Kesiswaan, RoleName::KepalaSekolah, RoleName::Humas, RoleName::Guru] as $role) {
        $this->actingAs(A::staff($role))->get(route('tu.siswa.index'))->assertForbidden();
    }

    $student = dsStudent('Raka Pratama', $this->x1, '0012345671', [
        'status' => UserStatus::Active,
        'must_change_password' => false,
        'email' => 'raka.pratama@example.test',
        'email_verified_at' => now(),
        'activated_at' => now(),
    ]);
    $this->actingAs($student)->get(route('tu.siswa.index'))->assertForbidden();

    auth()->logout();
    $this->get(route('tu.siswa.index'))->assertRedirect(route('login'));
});

it('renders the dialog once on the page', function (): void {
    dsStudent('Nadia Putri Anindya', $this->x1, '0012345670');

    $html = $this->actingAs($this->tu)->get(route('tu.siswa.index'))->assertOk()->getContent();

    expect(substr_count((string) $html, 'id="'.UbahDataSiswa::DIALOG.'"'))->toBe(1)
        ->and(substr_count((string) $html, 'id="f-ubah-data-nama"'))->toBe(0);
});

it('refuses a Tata Usaha account without student.update', function (): void {
    Role::findByName(RoleName::TataUsaha->value, 'web')->revokePermissionTo(Permission::StudentUpdate->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($this->tu->fresh())->get(route('tu.siswa.index'))->assertForbidden();
});

it('puts Data siswa in the Tata Usaha menu only', function (): void {
    $items = dsMenu('tata_usaha', 'tu.siswa.index');

    expect($items)->toHaveKey('siswa')
        ->and($items['siswa']['label'])->toBe('Data siswa')
        ->and($items['siswa']['href'])->toBe(route('tu.siswa.index'))
        ->and($items['siswa']['current'])->toBeTrue()
        ->and(collect($items)->where('current', true))->toHaveCount(1);

    foreach (['kesiswaan', 'admin', 'kepala_sekolah', 'humas', 'guru'] as $role) {
        expect(collect(dsMenu($role))->pluck('route')->flatten()->all())->not->toContain('tu.siswa.index');
    }
});

// --- The list --------------------------------------------------------------------------------------------------------

it('lists every student outside the Sampah with name, NISN, NIS, class and status', function (): void {
    $nadia = dsStudent('Nadia Putri Anindya', $this->x1, '0012345670');
    $alumni = dsStudent('Bima Satria Wicaksono', $this->x2, '0012345672', ['status' => UserStatus::Archived]);
    $trashed = dsStudent('Raka Pratama', $this->x1, '0012345671');
    $trashed->student->delete();
    $trashed->delete();

    $screen = Livewire::actingAs($this->tu)->test(DataSiswa::class)
        ->assertSee('Nadia Putri Anindya')
        ->assertSee('0012345670')
        ->assertSee($nadia->student->nis)
        ->assertSee('X-1')
        ->assertSee('Belum aktivasi')
        ->assertSee('Bima Satria Wicaksono')
        ->assertSee('Diarsipkan')
        ->assertDontSee('Raka Pratama');

    expect($screen->get('shownIds'))->toBe([(int) $alumni->getKey(), (int) $nadia->getKey()]);
});

it('searches by name or NISN and filters by class', function (): void {
    $nadia = dsStudent('Nadia Putri Anindya', $this->x1, '0012345670');
    $raka = dsStudent('Raka Pratama', $this->x2, '0098765432');

    $screen = Livewire::actingAs($this->tu)->test(DataSiswa::class);

    $screen->set('q', ' raka ')->assertSet('q', ' raka ');
    expect($screen->get('shownIds'))->toBe([(int) $raka->getKey()]);

    $screen->set('q', '00123');
    expect($screen->get('shownIds'))->toBe([(int) $nadia->getKey()]);

    $screen->set('q', '')->set('kelas', (string) $this->x2->getKey());
    expect($screen->get('shownIds'))->toBe([(int) $raka->getKey()]);

    $screen->set('kelas', 'tidak-ada')->assertSet('kelas', '');

    $screen->set('q', 'Tidak ada siswa ini')->assertSee('Tidak ada siswa yang cocok');
});

it('shows 25 students a page', function (): void {
    foreach (range(1, 26) as $i) {
        dsStudent('Siswa Contoh '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), $this->x1, '00'.str_pad((string) $i, 8, '0', STR_PAD_LEFT));
    }

    $screen = Livewire::actingAs($this->tu)->test(DataSiswa::class);

    expect($screen->get('shownIds'))->toHaveCount(DataSiswa::PER_PAGE)
        ->and($screen->viewData('students')->total())->toBe(26);
});

// --- "Ubah data" -----------------------------------------------------------------------------------------------------

it('opens the shared dialog for a listed student only', function (): void {
    $nadia = dsStudent('Nadia Putri Anindya', $this->x1, '0012345670');
    $other = dsStudent('Raka Pratama', $this->x2, '0098765432');

    $screen = Livewire::actingAs($this->tu)->test(DataSiswa::class)->set('kelas', (string) $this->x1->getKey());

    $screen->call('edit', (int) $nadia->getKey())
        ->assertDispatched(UbahDataSiswa::OPEN_EVENT, userId: (int) $nadia->getKey());

    // Not on this page (another class, a staff account): 404.
    foreach ([(int) $other->getKey(), (int) $this->tu->getKey()] as $id) {
        Livewire::actingAs($this->tu)->test(DataSiswa::class)->set('kelas', (string) $this->x1->getKey())
            ->call('edit', $id)
            ->assertNotFound();
    }
});

it('says so when a listed student left for the Sampah meanwhile', function (): void {
    $nadia = dsStudent('Nadia Putri Anindya', $this->x1, '0012345670');
    $screen = Livewire::actingAs($this->tu)->test(DataSiswa::class);

    $nadia->student->delete();
    $nadia->delete();

    $screen->call('edit', (int) $nadia->getKey())
        ->assertNotDispatched(UbahDataSiswa::OPEN_EVENT)
        ->assertDispatched('sth-toast', message: DataSiswa::MSG_GONE, tone: 'warning')
        ->assertDontSee('Nadia Putri Anindya');
});

it('redraws the list after the dialog saved', function (): void {
    $nadia = dsStudent('Nadia Putri Anindya', $this->x1, '0012345670');
    $screen = Livewire::actingAs($this->tu)->test(DataSiswa::class)->assertSee('Nadia Putri Anindya');

    app(UpdateStudentData::class)->handle($this->tu, $nadia, [
        'name' => 'Nadia Putri Anindita',
        'nis' => $nadia->student->nis,
        'nisn' => '0012345670',
        'birth_date' => $nadia->student->birth_date->format('d-m-Y'),
        'classroom_id' => (string) $this->x1->getKey(),
    ], 'data_siswa');

    $screen->dispatch(UbahDataSiswa::DONE_EVENT)->assertSee('Nadia Putri Anindita');
});

// --- Admin › Pengguna & peran › Bantuan akun siswa -------------------------------------------------------------------

it('offers "Ubah data" for the chosen student on Bantuan akun siswa', function (): void {
    $admin = A::admin();
    $nadia = dsStudent('Nadia Putri Anindya', $this->x1, '0012345670');

    $this->actingAs($admin)->withSession(A::passwordConfirmed())->get(route('admin.pengguna.index', ['q' => '0012345670']))
        ->assertOk()
        ->assertSee('Ubah data')
        ->assertSee('id="'.UbahDataSiswa::DIALOG.'"', false);

    Livewire::actingAs($admin)->test(Pengguna::class)
        ->call('openStudentData')
        ->assertNotFound();

    Livewire::actingAs($admin)->test(Pengguna::class)
        ->set('studentQuery', '0012345670')
        ->call('findStudent')
        ->assertSee('Ubah data')
        ->call('openStudentData')
        ->assertDispatched(UbahDataSiswa::OPEN_EVENT, userId: (int) $nadia->getKey());
});

it('says so on Bantuan akun siswa when the student left for the Sampah meanwhile', function (): void {
    $admin = A::admin();
    $nadia = dsStudent('Nadia Putri Anindya', $this->x1, '0012345670');
    $screen = Livewire::actingAs($admin)->test(Pengguna::class)->set('studentQuery', '0012345670')->call('findStudent');

    $nadia->student->delete();
    $nadia->delete();

    $screen->call('openStudentData')
        ->assertNotDispatched(UbahDataSiswa::OPEN_EVENT)
        ->assertDispatched('sth-toast', message: UpdateStudentData::MSG_GONE, tone: 'warning')
        ->assertSet('studentUserId', null);
});

it('refreshes the name matches of Bantuan akun siswa after a save', function (): void {
    $admin = A::admin();
    $nadia = dsStudent('Nadia Putri Anindya', $this->x1, '0012345670');
    dsStudent('Nadia Kirana', $this->x2, '0012345671');

    $screen = Livewire::actingAs($admin)->test(Pengguna::class)->set('studentQuery', 'Nadia')->call('findStudent');
    expect($screen->get('studentMatches'))->toHaveCount(2);

    app(UpdateStudentData::class)->handle($admin, $nadia, [
        'name' => 'Nadia Putri Anindita',
        'nis' => $nadia->student->nis,
        'nisn' => '0012345670',
        'birth_date' => $nadia->student->birth_date->format('d-m-Y'),
        'classroom_id' => (string) $this->x1->getKey(),
    ], 'pengguna');

    $screen->dispatch(UbahDataSiswa::DONE_EVENT)->assertSee('Nadia Putri Anindita');
});
