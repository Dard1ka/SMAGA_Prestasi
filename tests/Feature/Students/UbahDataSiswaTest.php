<?php

declare(strict_types=1);

use App\Actions\Students\UpdateStudentData;
use App\Domain\Accounts\InitialPassword;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Akun\UbahDataSiswa;
use App\Models\Classroom;
use App\Models\Letter;
use App\Models\RegistrationMember;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 | SV6, D-187: the shared dialog "Ubah data siswa" (App\Livewire\Akun\UbahDataSiswa) of Admin, Kesiswaan and Tata Usaha:
 | the password first, one student, an effects box with only what the changed fields cause, stale screens as toasts,
 | D-128 (save() without open() does nothing). Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    Notification::fake();
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00', 'Asia/Jakarta'));
    $this->x1 = Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
    $this->x2 = Classroom::factory()->create(['name' => 'X-2', 'level' => 10]);
    $this->xi1 = Classroom::factory()->create(['name' => 'XI-1', 'level' => 11]);
    $this->tu = A::staff(RoleName::TataUsaha);
    session(A::passwordConfirmed());
});

/** A pending student of X-1 (2026/2027) born 15-05-2010, still on the birth-date password, or the given state. */
function udsdStudent(array $student = [], array $user = []): User
{
    $row = Student::factory()->create(array_merge([
        'classroom_id' => test()->x1->getKey(),
        'nis' => '225226201',
        'nisn' => '0012345670',
        'birth_date' => '2010-05-15',
        'import_school_year' => '2026/2027',
    ], $student));
    $row->user->forceFill(array_merge([
        'name' => 'Nadia Putri Anindya',
        'password' => Hash::make(InitialPassword::forBirthDate('2010-05-15')),
    ], $user))->saveQuietly();

    return User::query()->with('student')->findOrFail($row->user_id);
}

function udsdDialog(User $actor, array $params = ['source' => 'data_siswa']): Testable
{
    return Livewire::actingAs($actor)->test(UbahDataSiswa::class, $params);
}

// --- Who may open it -------------------------------------------------------------------------------------------------

it('is mounted only for an account with student.update', function (RoleName $role, bool $may): void {
    $screen = Livewire::actingAs(A::staff($role))->test(UbahDataSiswa::class);

    $may ? $screen->assertOk() : $screen->assertForbidden();
})->with([
    'Admin' => [RoleName::Admin, true],
    'Kesiswaan' => [RoleName::Kesiswaan, true],
    'Tata Usaha' => [RoleName::TataUsaha, true],
    'Kepala Sekolah' => [RoleName::KepalaSekolah, false],
    'Humas' => [RoleName::Humas, false],
    'Guru' => [RoleName::Guru, false],
]);

it('refuses a staff account id with 404 and says so for a student who left for the Sampah', function (): void {
    $account = udsdStudent();

    udsdDialog($this->tu)->call('open', (int) A::staff(RoleName::Guru)->getKey())->assertNotFound();

    $account->student->delete();
    $account->delete();

    udsdDialog($this->tu)->call('open', (int) $account->getKey())
        ->assertStatus(200)
        ->assertSet('userId', null)
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched(UbahDataSiswa::DONE_EVENT)
        ->assertDispatched('sth-toast', message: UpdateStudentData::MSG_GONE, tone: 'warning');
});

// --- Opening ---------------------------------------------------------------------------------------------------------

it('asks for the password before the dialog opens', function (): void {
    $account = udsdStudent();
    session()->forget('auth.password_confirmed_at');

    udsdDialog($this->tu)->call('open', (int) $account->getKey())
        ->assertRedirect(route('password.confirm'))
        ->assertSet('userId', null)
        ->assertNotDispatched('sth-modal-open');
});

it('opens with the data, the status, the masked email and the cohort line, never a password', function (): void {
    $account = udsdStudent([], [
        'status' => UserStatus::Active,
        'must_change_password' => false,
        'email' => 'nadia.anindya@example.test',
    ]);

    $screen = udsdDialog($this->tu)->call('open', (int) $account->getKey())
        ->assertSet('userId', (int) $account->getKey())
        ->assertSet('dataName', 'Nadia Putri Anindya')
        ->assertSet('dataNis', '225226201')
        ->assertSet('dataNisn', '0012345670')
        ->assertSet('dataBirthDate', '15-05-2010')
        ->assertSet('dataClassroomId', (string) $this->x1->getKey())
        ->assertSet('onInitialPassword', false)
        ->assertDispatched('sth-modal-open', id: UbahDataSiswa::DIALOG)
        ->assertSee('Ubah data siswa')
        ->assertSee('Aktif')
        ->assertSee('Email n•••@example.test')
        // X in 2026/2027 → XII from July 2028 → Angkatan 2026, archived January 2030.
        ->assertSee('Angkatan 2026 · arsip otomatis Januari 2030')
        ->assertSee('Kelas X')
        ->assertSee('XI-1')
        ->assertSee('Simpan perubahan')
        ->assertDontSee('SMAGA_')
        ->assertDontSee('Yang ikut berubah');

    expect($screen->get('shown'))->toBe(UpdateStudentData::snapshot($account));
});

it('locks the class of an archived student in the dialog', function (): void {
    $account = udsdStudent([], ['status' => UserStatus::Archived, 'must_change_password' => false]);

    udsdDialog($this->tu)->call('open', (int) $account->getKey())
        ->assertSee('Diarsipkan')
        ->assertSee('Kelas siswa alumni tidak diubah.');
});

// --- Effects box -----------------------------------------------------------------------------------------------------

it('lists only what the changed fields cause', function (): void {
    $account = udsdStudent();
    $screen = udsdDialog($this->tu)->call('open', (int) $account->getKey())
        ->assertSet('onInitialPassword', true)
        ->assertDontSee('Yang ikut berubah');

    $screen->set('dataNisn', '0012345679')
        ->assertSee('Yang ikut berubah')
        ->assertSee(UbahDataSiswa::EFFECT_NISN)
        ->assertSee(UbahDataSiswa::EFFECT_EXCEL)
        ->assertDontSee(UbahDataSiswa::EFFECT_PASSWORD);

    $screen->set('dataNisn', '0012345670')->set('dataNis', '225226299')
        ->assertDontSee(UbahDataSiswa::EFFECT_NISN)
        ->assertSee(UbahDataSiswa::EFFECT_EXCEL);

    $screen->set('dataNis', '225226201')->set('dataBirthDate', '01-06-2010')
        ->assertSee(UbahDataSiswa::EFFECT_PASSWORD)
        ->assertDontSee(UbahDataSiswa::EFFECT_EXCEL)
        ->assertDontSee('SMAGA_');

    // Another tingkat: the new archive month; the same tingkat: nothing about dates.
    $screen->set('dataBirthDate', '15-05-2010')->set('dataClassroomId', (string) $this->xi1->getKey())
        ->assertSee('Tanggal arsip otomatis menjadi Januari 2029.');
    $screen->set('dataClassroomId', (string) $this->x2->getKey())
        ->assertDontSee('Tanggal arsip otomatis menjadi')
        ->assertDontSee('Yang ikut berubah');
});

it('says nothing about the initial password of an active student and warns about an issued letter', function (): void {
    $account = udsdStudent([], ['status' => UserStatus::Active, 'must_change_password' => false]);
    $letter = Letter::factory()->create();
    RegistrationMember::factory()->confirmed()->create(['registration_id' => $letter->registration_id, 'student_id' => $account->student->getKey()]);

    udsdDialog($this->tu)->call('open', (int) $account->getKey())
        ->assertSet('hasLetter', true)
        ->set('dataBirthDate', '01-06-2010')
        ->assertDontSee(UbahDataSiswa::EFFECT_PASSWORD)
        ->assertDontSee(UbahDataSiswa::EFFECT_LETTER)
        ->set('dataName', 'Nadia Putri Anindita')
        ->assertSee(UbahDataSiswa::EFFECT_LETTER);

    // A revoked letter does not count.
    $letter->forceFill(['revoked_at' => now()])->save();
    expect(UbahDataSiswa::hasIssuedLetter($account->student))->toBeFalse();
});

// --- Saving ----------------------------------------------------------------------------------------------------------

it('saves, closes, tells the host to redraw and audits the screen it stood on', function (): void {
    $account = udsdStudent();

    udsdDialog($this->tu)->call('open', (int) $account->getKey())
        ->set('dataName', 'Nadia Putri Anindita')
        ->set('dataNisn', '0012345679')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('userId', null)
        ->assertDispatched('sth-modal-close', id: UbahDataSiswa::DIALOG)
        ->assertDispatched(UbahDataSiswa::DONE_EVENT)
        ->assertDispatched('sth-toast', message: UbahDataSiswa::MSG_SAVED, tone: 'success');

    $row = Activity::query()->where('event', UpdateStudentData::EVENT)->sole();

    expect($account->fresh()->name)->toBe('Nadia Putri Anindita')
        ->and($account->student->fresh()->nisn)->toBe('0012345679')
        ->and($row->properties['fields'])->toBe(['name', 'nisn'])
        ->and($row->properties['source'])->toBe('data_siswa');
    Notification::assertNothingSent();
});

it('puts every refusal under its field and saves nothing', function (): void {
    $account = udsdStudent();
    udsdStudent(['nis' => '225226202', 'nisn' => '0012345671'], ['name' => 'Raka Pratama']);

    udsdDialog($this->tu)->call('open', (int) $account->getKey())
        ->set('dataName', 'Nadia 2')
        ->set('dataNisn', '0012345671')
        ->set('dataBirthDate', '31-02-2010')
        ->call('save')
        ->assertHasErrors(['dataName', 'dataNisn', 'dataBirthDate'])
        ->assertSee('NISN sudah dipakai siswa lain.')
        ->assertSee('Tanggal lahir tidak valid (pakai DD-MM-YYYY).')
        ->assertSet('userId', (int) $account->getKey())
        ->assertNotDispatched(UbahDataSiswa::DONE_EVENT);

    expect(Activity::query()->where('event', UpdateStudentData::EVENT)->count())->toBe(0);
});

it('says when nothing was changed and writes nothing', function (): void {
    $account = udsdStudent();

    udsdDialog($this->tu)->call('open', (int) $account->getKey())
        ->set('dataName', ' Nadia  Putri Anindya ')
        ->call('save')
        ->assertDispatched('sth-toast', message: UbahDataSiswa::MSG_NOTHING, tone: 'info')
        ->assertSet('userId', (int) $account->getKey());

    expect(Activity::query()->where('event', UpdateStudentData::EVENT)->count())->toBe(0);
});

it('checks the password again on save', function (): void {
    $account = udsdStudent();
    $screen = udsdDialog($this->tu)->call('open', (int) $account->getKey())->set('dataName', 'Nadia Putri Anindita');

    session()->forget('auth.password_confirmed_at');
    $screen->call('save')->assertRedirect(route('password.confirm'));

    expect($account->fresh()->name)->toBe('Nadia Putri Anindya');
});

it('does nothing on save without a question (D-128)', function (): void {
    udsdStudent();

    udsdDialog($this->tu)
        ->set('dataName', 'Nadia Putri Anindita')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotDispatched('sth-toast')
        ->assertNotDispatched(UbahDataSiswa::DONE_EVENT);

    expect(Activity::query()->where('event', UpdateStudentData::EVENT)->count())->toBe(0);
});

it('forgets the question when the dialog closes', function (): void {
    $account = udsdStudent();

    udsdDialog($this->tu)->call('open', (int) $account->getKey())
        ->set('dataName', 'Nadia Putri Anindita')
        ->call('forget')
        ->assertSet('userId', null)
        ->assertSet('dataName', '')
        ->call('save')
        ->assertNotDispatched('sth-toast');

    expect($account->fresh()->name)->toBe('Nadia Putri Anindya');
});

// --- Stale screens ---------------------------------------------------------------------------------------------------

it('reloads the fields with a warning when the data changed meanwhile', function (): void {
    $account = udsdStudent();
    $screen = udsdDialog($this->tu)->call('open', (int) $account->getKey())->set('dataName', 'Nadia Putri Anindita');

    // Another tab corrected the NISN meanwhile.
    Student::query()->whereKey($account->student->getKey())->update(['nisn' => '0012345678']);

    $screen->call('save')
        ->assertDispatched('sth-toast', message: UpdateStudentData::MSG_CHANGED, tone: 'warning')
        ->assertSet('dataNisn', '0012345678')
        ->assertSet('dataName', 'Nadia Putri Anindya')
        ->assertSet('userId', (int) $account->getKey())
        ->assertNotDispatched('sth-modal-close');

    expect(Activity::query()->where('event', UpdateStudentData::EVENT)->count())->toBe(0)
        ->and($account->fresh()->name)->toBe('Nadia Putri Anindya');
});

it('closes with a warning when the student was moved to the Sampah meanwhile', function (): void {
    $account = udsdStudent();
    $screen = udsdDialog($this->tu)->call('open', (int) $account->getKey())->set('dataName', 'Nadia Putri Anindita');

    $account->student->delete();
    $account->delete();

    $screen->call('save')
        ->assertStatus(200)
        ->assertSet('userId', null)
        ->assertDispatched('sth-modal-close', id: UbahDataSiswa::DIALOG)
        ->assertDispatched(UbahDataSiswa::DONE_EVENT)
        ->assertDispatched('sth-toast', message: UpdateStudentData::MSG_GONE, tone: 'warning');
});

// --- Source of the audit row -----------------------------------------------------------------------------------------

it('names the screen the dialog stands on', function (?string $route, string $source): void {
    expect(UbahDataSiswa::sourceFor($route))->toBe($source)
        ->and(UpdateStudentData::SOURCES)->toContain($source);
})->with([
    ['admin.kelas.index', 'kelas'],
    ['kesiswaan.aktivasi.index', 'aktivasi'],
    ['admin.aktivasi.index', 'aktivasi'],
    ['kesiswaan.import', 'import'],
    ['admin.import', 'import'],
    ['admin.pengguna.index', 'pengguna'],
    ['admin.pengaturan', 'pengguna'],
    ['tu.siswa.index', 'data_siswa'],
    ['tu.surat.show', 'surat'],
    ['tu.beranda', 'surat'],
    [null, 'lainnya'],
    ['kesiswaan.beranda', 'lainnya'],
]);
