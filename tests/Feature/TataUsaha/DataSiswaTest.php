<?php

declare(strict_types=1);

use App\Actions\Students\UpdateStudentData;
use App\Enums\RoleName;
use App\Livewire\Admin\Pengaturan\Pengguna;
use App\Livewire\Akun\UbahDataSiswa;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 | SV6, D-187: "Ubah data" on Admin › Pengguna & peran › Bantuan akun siswa. Tata Usaha › Data siswa of SV6 became TU ›
 | Kelas on 2026-11-05 (the lead): its old address redirects there (the screen itself: tests/Feature/Admin/KelasAreaTest.php).
 | Fictional data.
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

// --- TU › Data siswa became TU › Kelas (2026-11-05) -----------------------------------------------------------------

it('sends the old Data siswa address of Tata Usaha to TU › Kelas', function (): void {
    $this->actingAs($this->tu)->get(route('tu.siswa.index'))->assertRedirect('/tu/kelas');
    $this->actingAs($this->tu)->get('/tu/data-siswa')->assertRedirect('/tu/kelas');

    // Still the TU area: another role is refused, a guest goes to the login page.
    $this->actingAs(A::staff(RoleName::Kesiswaan))->get('/tu/data-siswa')->assertForbidden();
    auth()->logout();
    $this->get('/tu/data-siswa')->assertRedirect(route('login'));
});

it('puts Kelas instead of Data siswa in the Tata Usaha menu, also current on the old address', function (): void {
    $items = dsMenu('tata_usaha', 'tu.kelas.index');

    expect($items)->toHaveKey('kelas')
        ->and($items)->not->toHaveKey('siswa')
        ->and($items['kelas']['label'])->toBe('Kelas')
        ->and($items['kelas']['href'])->toBe(route('tu.kelas.index'))
        ->and($items['kelas']['current'])->toBeTrue()
        ->and(collect($items)->where('current', true))->toHaveCount(1)
        ->and(dsMenu('tata_usaha', 'tu.siswa.index')['kelas']['current'])->toBeTrue();
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
