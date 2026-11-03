<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Admin\Pengaturan\Pengguna;
use App\Models\Classroom;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;

uses(RefreshDatabase::class);

/*
 | SV1 browser check (2026-10-30): the Admin's top bar search "Cari nama atau NISN" lands on Pengguna & peran with ?q=,
 | and only a full NISN reached the student lookup: a student was never found by name, although "Bantuan akun siswa"
 | takes a part of the name since D-158. A name now filters the staff table as before and runs the student lookup too.
 | Fictional data only.
 */

beforeEach(function (): void {
    A::seedRoles();
    Notification::fake();
    $this->withoutVite();
    $this->admin = A::admin();
    $this->actingAs($this->admin);
    $this->guru = A::staff(RoleName::Guru, ['name' => 'Pak Hendra Kusuma']);
    $this->class = Classroom::factory()->level(11)->create(['name' => 'XI-D']);
});

function ui2PenggunaStudent(object $t, string $name): Student
{
    $student = Student::factory()->create(['classroom_id' => $t->class->getKey()]);
    $student->user->forceFill(['name' => $name])->saveQuietly();

    return $student->fresh();
}

it('opens the student a top bar name search finds, and says so above the staff table (SV1)', function (): void {
    $nadia = ui2PenggunaStudent($this, 'Nadia Putri Anindya');
    ui2PenggunaStudent($this, 'Raka Pratama');

    $html = Livewire::withQueryParams(['q' => '  Nadia  '])->test(Pengguna::class)
        ->assertHasNoErrors()
        // The staff filter stays: no staff account is called Nadia.
        ->assertSet('search', '  Nadia  ')
        ->assertViewHas('staff', fn ($staff): bool => $staff->isEmpty())
        ->assertSet('studentQuery', 'Nadia')
        ->assertSet('studentUserId', (int) $nadia->user_id)
        ->assertSee('Siswa yang cocok dengan "Nadia"')
        ->assertSee('Akun siswa Nadia Putri Anindya ada di Bantuan akun siswa.')
        ->assertSee('Reset kata sandi & email')
        ->html();

    // The note stands on the first screen, above the (empty) staff table, and links to the answer at the bottom.
    $notice = strpos($html, 'Akun siswa Nadia Putri Anindya ada di Bantuan akun siswa.');

    expect($notice)->toBeInt()
        ->and($notice)->toBeLessThan(strpos($html, 'Tidak ada akun staf yang cocok'))
        ->and($notice)->toBeLessThan(strpos($html, 'id="h-bantuan-siswa"'))
        ->and($html)->toContain('href="#h-bantuan-siswa"')
        ->not->toContain('Hasil NISN')
        // The search found the student: the empty staff table does not ask to change it.
        ->not->toContain('Ganti kata pencarian.');
});

it('lists several students a top bar name search finds, to pick from in "Bantuan akun siswa" (SV1)', function (): void {
    ui2PenggunaStudent($this, 'Dimas Saputra');
    ui2PenggunaStudent($this, 'Bima Saputra');

    Livewire::withQueryParams(['q' => 'saputra'])->test(Pengguna::class)
        ->assertHasNoErrors()
        ->assertSet('studentUserId', null)
        ->assertCount('studentMatches', 2)
        ->assertSee('2 siswa cocok. Pilih salah satu di Bantuan akun siswa.')
        ->assertSee('Lihat siswa yang cocok')
        ->assertSee('Dimas Saputra')
        ->assertSee('Bima Saputra')
        ->assertSee('Tidak ada akun staf yang cocok')
        ->assertDontSee('Ganti kata pencarian.');
});

/*
 | SV1 follow-up: the top bar term may as well be a staff name, username or email. A student lookup that finds nobody
 | leaves "Bantuan akun siswa" as it was (an empty field, no red "Siswa tidak ditemukan." the Admin never asked for).
 */

it('leaves "Bantuan akun siswa" untouched when a top bar search for a staff member finds no student (SV1 follow-up)', function (string $term, ?string $staffSeen): void {
    $this->guru->forceFill(['username' => 'hendra.k', 'email' => 'hendra.kusuma@example.test'])->save();
    ui2PenggunaStudent($this, 'Raka Pratama');

    $screen = Livewire::withQueryParams(['q' => $term])->test(Pengguna::class)
        ->assertHasNoErrors()
        ->assertSet('studentQuery', '')
        ->assertSet('nameFromSearch', null)
        ->assertSet('studentUserId', null)
        ->assertSet('studentMatches', [])
        ->assertDontSee('Siswa tidak ditemukan.')
        // Nothing found, so no note above the table.
        ->assertDontSee('Siswa yang cocok dengan')
        ->assertDontSee('Hasil NISN');

    if ($staffSeen !== null) {
        $screen->assertSee($staffSeen);
    }
})->with([
    'staff name' => ['Hendra', 'Pak Hendra Kusuma'],
    'username' => ['hendra.k', 'Pak Hendra Kusuma'],
    'email' => ['hendra.kusuma@example.test', null],
]);

it('keeps the general empty state and no student error for a top bar name nobody has (SV1 follow-up)', function (): void {
    ui2PenggunaStudent($this, 'Raka Pratama');

    Livewire::withQueryParams(['q' => 'Citra Wulandari'])->test(Pengguna::class)
        ->assertHasNoErrors()
        ->assertSet('studentQuery', '')
        ->assertViewHas('studentFoundBySearch', false)
        ->assertSee('Tidak ada akun yang cocok')
        ->assertSee('Ganti kata pencarian.')
        ->assertDontSee('Siswa tidak ditemukan.')
        // Asked by hand in "Bantuan akun siswa", the field still answers.
        ->set('studentQuery', 'Citra Wulandari')
        ->call('findStudent')
        ->assertHasErrors(['studentQuery' => 'Siswa tidak ditemukan.']);
});

it('says only that no staff account matched while the staff search holds the term that found a student (SV1 follow-up)', function (): void {
    ui2PenggunaStudent($this, 'Nadia Putri Anindya');

    $screen = Livewire::withQueryParams(['q' => 'Nadia'])->test(Pengguna::class)
        ->assertViewHas('studentFoundBySearch', true)
        ->assertSee('Tidak ada akun staf yang cocok')
        ->assertSee('Siswa yang cocok ada di Bantuan akun siswa di bawah.')
        ->assertDontSee('Ganti kata pencarian.')
        ->assertDontSee('Tidak ada akun yang cocok');

    // The same words in "Cari staf", in other letters and with a space: still the search that found her.
    $screen->set('search', 'nadia ')
        ->assertViewHas('studentFoundBySearch', true)
        ->assertDontSee('Ganti kata pencarian.');

    // A new term typed into "Cari staf" was not looked up among the students: the general empty state. The note about
    // "Nadia" names its own term and stays with the student it found.
    $screen->set('search', 'Zaskia')
        ->assertViewHas('studentFoundBySearch', false)
        ->assertSee('Tidak ada akun yang cocok')
        ->assertSee('Ganti kata pencarian.')
        ->assertSee('Siswa yang cocok dengan "Nadia"');
});

it('runs no student lookup for a word too short for a name or digits that are no NISN', function (string $term): void {
    ui2PenggunaStudent($this, 'Nadia Putri Anindya');

    Livewire::withQueryParams(['q' => $term])->test(Pengguna::class)
        ->assertHasNoErrors()
        ->assertSet('studentQuery', '')
        ->assertSet('studentUserId', null)
        ->assertSet('studentMatches', [])
        ->assertDontSee('Siswa yang cocok dengan');
})->with(['two letters' => 'Na', 'part of a number' => '12345']);

it('drops the note once "Bantuan akun siswa" searches for something else', function (): void {
    ui2PenggunaStudent($this, 'Nadia Putri Anindya');
    ui2PenggunaStudent($this, 'Raka Pratama');

    Livewire::withQueryParams(['q' => 'Nadia'])->test(Pengguna::class)
        ->assertSee('Siswa yang cocok dengan "Nadia"')
        ->set('studentQuery', 'Raka')
        ->call('findStudent')
        ->assertDontSee('Siswa yang cocok dengan');
});

it('keeps the name search away from every role but Admin', function (): void {
    ui2PenggunaStudent($this, 'Nadia Putri Anindya');

    foreach ([RoleName::Kesiswaan, RoleName::TataUsaha, RoleName::Humas, RoleName::KepalaSekolah, RoleName::Guru] as $role) {
        Livewire::actingAs(A::staff($role, ['name' => 'Staf '.$role->value]))->withQueryParams(['q' => 'Nadia'])
            ->test(Pengguna::class)
            ->assertForbidden();
    }
});
