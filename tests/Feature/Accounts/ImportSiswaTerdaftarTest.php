<?php

declare(strict_types=1);

use App\Actions\Accounts\ImportStudents;
use App\Actions\Trash\TrashClassroom;
use App\Actions\Trash\TrashStudents;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\Kelas;
use App\Livewire\Akun\ImportSiswa;
use App\Livewire\Akun\UbahDataSiswa;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\TrashEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
 | SV6, D-184 (the lead, 5 Nov 2026: "saya mau import siswa yg sdh ada tapi alesannya begitu, mending jg ditambahin kalo
 | misalnya ada yg gitu bisa langsung ditunjukan siswa mana dan bisa langsung dilihat dari situ"). Import siswa names the
 | registered student under every row that matches or collides with one: "Terdaftar: <nama> · <kelas> · NISN <nisn> ·
 | <status>". "Lihat" sends the row NUMBER (never an id) and opens the shared dialog "Ubah data siswa" (D-187); a student
 | in the Sampah has no "Lihat": the Admin gets a link to the Sampah, Kesiswaan a sentence. The ids stay in the server-side
 | preview cache. Fictional data only (DESIGN-SPEC-v2 §5); the users get chosen ids so a leak can be looked for.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Queue::fake();
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00', 'Asia/Jakarta'));
    Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
    Classroom::factory()->level(11)->create(['name' => 'XI-4']);
    // SV6, D-184: a file holds one tingkat, so the class move of sv6tMixed() stays inside kelas X.
    Classroom::factory()->create(['name' => 'X-2', 'level' => 10]);
    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->admin = F::staff(RoleName::Admin, 'Operator Sekolah');
});

/** A registered student with a chosen users.id (fictional data). */
function sv6tStudent(int $userId, string $name, string $nis, string $nisn, string $kelas = 'X-1', array $user = []): Student
{
    User::factory()->create(['id' => $userId, 'name' => $name, 'email' => str($name)->slug('.').'@example.test'])
        ->forceFill(['status' => UserStatus::Active, 'must_change_password' => false, 'password_changed_at' => now(), ...$user])
        ->save();

    return Student::factory()->create([
        'user_id' => $userId,
        'nis' => $nis,
        'nisn' => $nisn,
        'birth_date' => '2010-03-02',
        'classroom_id' => Classroom::query()->where('name', $kelas)->value('id'),
        'import_school_year' => '2026/2027',
        'grade12_start_date' => '2028-07-01',
        'archive_at' => '2030-01-01',
    ])->refresh();
}

/** @param list<list<string>> $rows */
function sv6tFile(array $rows): UploadedFile
{
    $lines = ['Nama lengkap,NIS,NISN,Tanggal lahir,Kelas,No HP', ...array_map(fn (array $row): string => implode(',', $row), $rows)];

    return UploadedFile::fake()->createWithContent('data-siswa.csv', implode("\n", $lines)."\n");
}

/** The import screen with $rows read for 2026/2027. */
function sv6tScreen(User $actor, array $rows): Testable
{
    return Livewire::actingAs($actor)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->set('schoolYear', '2026/2027')
        ->set('file', sv6tFile($rows))
        ->assertHasNoErrors();
}

/** Nadia moves class, Raka stays, Salsa is an alumna, Citra/Fajar collide, Bima is new. */
function sv6tMixed(): array
{
    sv6tStudent(731001, 'Nadia Putri Anindya', '225226201', '0098765432');
    sv6tStudent(731002, 'Raka Pratama', '225226202', '0098765433', 'X-1', ['status' => UserStatus::PendingActivation, 'must_change_password' => true]);
    sv6tStudent(731003, 'Salsa Maharani', '225226203', '0098765434', 'XI-4', ['status' => UserStatus::Archived]);
    sv6tStudent(731005, 'Citra Ayu', '225226205', '0098765436');
    sv6tStudent(731006, 'Fajar Hidayat', '225226206', '0098765437');

    return [
        ['Nadia Putri Anindya', '225226201', '0098765432', '02-03-2010', 'X-2', ''],  // 2 pindah kelas
        ['Raka Pratama', '225226202', '0098765433', '02-03-2010', 'X-1', ''],         // 3 tidak berubah
        ['Salsa Maharani', '225226203', '0098765434', '02-03-2010', 'XI-4', ''],      // 4 alumni, dilewati
        ['Citra Fajar', '225226205', '0098765437', '02-03-2010', 'X-1', ''],          // 5 NIS of Citra, NISN of Fajar
        ['Bima Saputra', '225226299', '0098765429', '02-03-2010', 'X-1', ''],         // 6 new
    ];
}

/** The rendered HTML without Livewire's block markers. */
function sv6tHtml(Testable $screen): string
{
    return (string) preg_replace('/<!--\[if (?:END)?BLOCK\]><!\[endif\]-->/', '', $screen->html());
}

it('names the registered student under every matching row and offers "Lihat" (SV6, D-184)', function (): void {
    $screen = sv6tScreen($this->kesiswaan, sv6tMixed())
        ->assertSee('Terdaftar: Nadia Putri Anindya · X-1 · NISN 0098765432 · Aktif')
        ->assertSee('Terdaftar: Raka Pratama · X-1 · NISN 0098765433 · Belum aktivasi')
        ->assertSee('Terdaftar: Salsa Maharani · XI-4 · NISN 0098765434 · Diarsipkan')
        // A row colliding with two students names both, each with its own "Lihat".
        ->assertSee('Terdaftar: Citra Ayu · X-1 · NISN 0098765436 · Aktif')
        ->assertSee('Terdaftar: Fajar Hidayat · X-1 · NISN 0098765437 · Aktif')
        ->assertSeeHtml('wire:click="showRegistered(2, 0)"')
        ->assertSeeHtml('wire:click="showRegistered(3, 0)"')
        ->assertSeeHtml('wire:click="showRegistered(4, 0)"')
        ->assertSeeHtml('wire:click="showRegistered(5, 0)"')
        ->assertSeeHtml('wire:click="showRegistered(5, 1)"')
        ->assertDontSeeHtml('showRegistered(6,')
        ->assertDontSeeHtml('showRegistered(5, 2)')
        ->assertSeeHtml('<span class="sr-only"> data Nadia Putri Anindya</span>')
        ->assertDontSee(ImportSiswa::ASK_ADMIN_RESTORE);

    // Five students named, the new row none; never a birth date or a phone number.
    expect(substr_count(sv6tHtml($screen), 'Terdaftar: '))->toBe(5)
        ->and(sv6tHtml($screen))->not->toContain('02-03-2010')
        ->and(sv6tHtml($screen))->not->toContain('2010-03-02');
});

it('opens "Ubah data siswa" for the student of the row number, also the second of two (SV6, D-184)', function (): void {
    sv6tScreen($this->kesiswaan, sv6tMixed())
        ->call('showRegistered', 2)
        ->assertDispatchedTo(UbahDataSiswa::class, UbahDataSiswa::OPEN_EVENT, userId: 731001)
        ->assertNotDispatched('sth-toast')
        ->call('showRegistered', 5, 1)
        ->assertDispatchedTo(UbahDataSiswa::class, UbahDataSiswa::OPEN_EVENT, userId: 731006);
});

it('opens it for the Admin too (SV6, D-184)', function (): void {
    sv6tScreen($this->admin, sv6tMixed())
        ->assertSeeHtml('wire:click="showRegistered(2, 0)"')
        ->call('showRegistered', 4)
        ->assertDispatchedTo(UbahDataSiswa::class, UbahDataSiswa::OPEN_EVENT, userId: 731003);
});

it('does nothing for a stale or forged row number or place (SV6, D-184)', function (string $case): void {
    $screen = sv6tScreen($this->kesiswaan, sv6tMixed());

    match ($case) {
        'row not in the file' => $screen->call('showRegistered', 99),
        'row without a registered student' => $screen->call('showRegistered', 6),
        'place beyond the students of the row' => $screen->call('showRegistered', 2, 1),
        'negative place' => $screen->call('showRegistered', 5, -1),
        // "Ditolak" lists only row 5: row 2 is in the preview but not on the screen.
        'row not listed by the filter' => $screen->set('filter', 'bad')->call('showRegistered', 2),
        'preview gone' => $screen->call('startOver')->call('showRegistered', 2),
    };

    $screen->assertOk()
        ->assertHasNoErrors()
        ->assertNotDispatched(UbahDataSiswa::OPEN_EVENT)
        ->assertNotDispatched('sth-toast');
})->with([
    'row not in the file',
    'row without a registered student',
    'place beyond the students of the row',
    'negative place',
    'row not listed by the filter',
    'preview gone',
]);

it('warns instead of opening when the student went into the Sampah meanwhile, and redraws the line (SV6, D-184)', function (): void {
    $screen = sv6tScreen($this->kesiswaan, sv6tMixed())->assertSeeHtml('wire:click="showRegistered(2, 0)"');

    app(TrashStudents::class)->handle($this->admin, [731001], 'Siswa pindah sekolah (data contoh).');

    $screen->call('showRegistered', 2)
        ->assertNotDispatched(UbahDataSiswa::OPEN_EVENT)
        ->assertDispatched('sth-toast', message: ImportSiswa::REGISTERED_TRASHED, tone: 'warning')
        ->assertDontSeeHtml('wire:click="showRegistered(2, 0)"')
        ->assertSee('Terdaftar: Nadia Putri Anindya · X-1 · NISN 0098765432 · Di Sampah, terhapus permanen')
        // Kesiswaan holds classroom.manage since 2026-11-05: the link to the Sampah of its own Kelas page.
        ->assertSeeHtml('href="'.route('kesiswaan.kelas.index').'#'.ImportSiswa::TRASH_ANCHOR.'"');
});

it('warns when the student was deleted for good meanwhile (SV6, D-184)', function (): void {
    $screen = sv6tScreen($this->kesiswaan, sv6tMixed());

    Student::query()->where('user_id', 731001)->forceDelete();

    $screen->call('showRegistered', 2)
        ->assertNotDispatched(UbahDataSiswa::OPEN_EVENT)
        ->assertDispatched('sth-toast', message: ImportSiswa::REGISTERED_GONE, tone: 'warning')
        ->assertDontSee('Terdaftar: Nadia Putri Anindya');
});

it('shows a student in the Sampah without "Lihat": a link to the own Sampah, a sentence without classroom.manage (SV6, D-184)', function (): void {
    sv6tStudent(731004, 'Dimas Arya Nugroho', '225226204', '0098765435', 'XI-4');
    // The class goes into the Sampah with its student; the line still prints the class name.
    app(TrashClassroom::class)->handle($this->admin, Classroom::query()->where('name', 'XI-4')->sole(), 'Kelas digabung (data contoh).');
    $purge = TrashEntry::purgeAfterFor(now())->translatedFormat('j F Y');
    $rows = [['Dimas Arya Nugroho', '225226204', '0098765435', '02-03-2010', 'X-1', '']];
    $line = 'Terdaftar: Dimas Arya Nugroho · XI-4 · NISN 0098765435 · Di Sampah, terhapus permanen '.$purge;
    $link = 'href="'.route('admin.kelas.index').'#'.ImportSiswa::TRASH_ANCHOR.'"';

    expect($purge)->toBe('13 November 2026');

    // Kesiswaan: the Sampah of its own Kelas page (classroom.manage since 2026-11-05), never the Admin address.
    sv6tScreen($this->kesiswaan, $rows)
        ->assertSee('Dilewati')
        ->assertSee(ImportStudents::NOTE_IN_TRASH)
        ->assertSee($line)
        ->assertSeeHtml('href="'.route('kesiswaan.kelas.index').'#'.ImportSiswa::TRASH_ANCHOR.'"')
        ->assertDontSee(ImportSiswa::ASK_ADMIN_RESTORE)
        ->assertDontSeeHtml($link)
        ->assertDontSeeHtml('showRegistered(');

    // Without classroom.manage: the sentence instead of a link.
    Role::findByName(RoleName::Kesiswaan->value)->revokePermissionTo(Permission::ClassroomManage->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    sv6tScreen(User::query()->findOrFail($this->kesiswaan->getKey()), $rows)
        ->assertSee(ImportSiswa::ASK_ADMIN_RESTORE)
        ->assertDontSeeHtml(ImportSiswa::TRASH_ANCHOR.'"');

    sv6tScreen($this->admin, $rows)
        ->assertSee($line)
        ->assertSeeHtml($link)
        ->assertSeeHtml('target="_blank"')
        ->assertDontSee(ImportSiswa::ASK_ADMIN_RESTORE)
        ->assertDontSeeHtml('showRegistered(')
        // Forged: a student in the Sampah never opens the dialog.
        ->call('showRegistered', 2)
        ->assertNotDispatched(UbahDataSiswa::OPEN_EVENT)
        ->assertDispatched('sth-toast', message: ImportSiswa::REGISTERED_TRASHED, tone: 'warning');
});

it('links to the heading of the Sampah that Admin › Kelas really has (SV6, D-184)', function (): void {
    Livewire::actingAs($this->admin)->test(Kelas::class)
        ->assertSeeHtml('id="'.ImportSiswa::TRASH_ANCHOR.'"');
});

it('keeps the line but drops "Lihat" and the dialog for an account without student.update (SV6, D-184)', function (): void {
    Role::findByName(RoleName::Kesiswaan->value)->revokePermissionTo(Permission::StudentUpdate->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $kesiswaan = User::query()->findOrFail($this->kesiswaan->getKey());

    Livewire::actingAs($kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)->assertDontSeeLivewire(UbahDataSiswa::class);

    sv6tScreen($kesiswaan, sv6tMixed())
        ->assertSee('Terdaftar: Nadia Putri Anindya · X-1 · NISN 0098765432 · Aktif')
        ->assertDontSeeHtml('showRegistered(')
        ->call('showRegistered', 2)
        ->assertForbidden()
        ->assertNotDispatched(UbahDataSiswa::OPEN_EVENT);
});

it('puts the shared dialog "Ubah data siswa" on the page once for Kesiswaan and Admin (SV6, D-184)', function (): void {
    foreach ([$this->kesiswaan, $this->admin] as $actor) {
        $screen = Livewire::actingAs($actor)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)->assertSeeLivewire(UbahDataSiswa::class);

        expect(substr_count($screen->html(), '&quot;name&quot;:&quot;akun.ubah-data-siswa&quot;'))->toBe(1);
    }
});

it('opens Import siswa to Kesiswaan and Admin only, so nobody else reads the line (SV6, D-184)', function (RoleName $role): void {
    Livewire::actingAs(F::staff($role, 'Staf Contoh'))->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)->assertForbidden();
})->with([RoleName::TataUsaha, RoleName::Guru, RoleName::Humas, RoleName::KepalaSekolah]);

it('keeps every id out of the Livewire payload and the download, and in the preview cache (SV6, D-184)', function (): void {
    $screen = sv6tScreen($this->kesiswaan, sv6tMixed());
    $ids = ['731001', '731002', '731003', '731005', '731006'];
    $payload = json_encode($screen->snapshot).' '.json_encode($screen->effects).' '.$screen->html();

    $base = (string) tempnam(sys_get_temp_dir(), 'sth');
    $path = $base.'.xlsx';
    file_put_contents($path, base64_decode((string) $screen->call('downloadInvalid')->effects['download']['content']));
    $cells = SimpleExcelReader::create($path)->getRows()->map(fn (array $r): string => implode('|', array_map(strval(...), $r)))->implode("\n");
    @unlink($path);
    @unlink($base);

    $cached = Cache::get('import-siswa:'.$this->kesiswaan->getKey().':'.$screen->get('previewToken'));

    foreach ($ids as $id) {
        expect($payload)->not->toContain($id)
            ->and($cells)->not->toContain($id);
    }

    expect($cells)->toContain('NIS dan NISN ini milik dua siswa yang berbeda.')
        ->and(collect($screen->viewData('list'))->every(fn (array $r): bool => ! array_key_exists('matches', $r)))->toBeTrue()
        ->and(array_column($cached['rows'], 'matches'))->toBe([[731001], [731002], [731003], [731005, 731006], []]);
});

it('keeps the preview after "Ubah data siswa" saved, redraws the line and imports nothing (SV6, D-184)', function (): void {
    $screen = sv6tScreen($this->kesiswaan, sv6tMixed());
    $token = $screen->get('previewToken');
    $students = Student::query()->count();

    // The dialog saved a new name (UbahDataSiswa writes it; here directly).
    User::query()->whereKey(731001)->update(['name' => 'Nadia Putri Anindita']);

    $screen->dispatch(UbahDataSiswa::DONE_EVENT, userId: 731001)
        ->assertDispatched('sth-toast', message: ImportSiswa::STUDENT_DATA_SAVED, tone: 'info')
        ->assertSet('previewToken', $token)
        ->assertSet('imported', null)
        ->assertSee('Terdaftar: Nadia Putri Anindita · X-1 · NISN 0098765432 · Aktif')
        ->assertSeeHtml('wire:click="showRegistered(2, 0)"');

    expect(Student::query()->count())->toBe($students)
        ->and(Activity::query()->where('event', 'student.import')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'student.class_changed')->count())->toBe(0);
});

it('says nothing after "Ubah data siswa" when no file is on screen (SV6, D-184)', function (): void {
    Livewire::actingAs($this->kesiswaan)->withQueryParams([ImportSiswa::LEVEL_QUERY => '10'])->test(ImportSiswa::class)
        ->dispatch(UbahDataSiswa::DONE_EVENT, userId: 731001)
        ->assertOk()
        ->assertNotDispatched('sth-toast');
});
