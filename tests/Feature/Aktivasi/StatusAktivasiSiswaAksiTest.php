<?php

declare(strict_types=1);

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\ExportActivationReport;
use App\Actions\Trash\TrashStudents;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\HapusSiswa;
use App\Livewire\Admin\UbahStatusSiswa;
use App\Livewire\Akun\StatusAktivasi;
use App\Livewire\Akun\UbahDataSiswa;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\TrashEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 * SV6, D-185 (the lead, 5 Nov 2026, requests 4 and 6): on Status aktivasi both student lists ("Daftar siswa" and the class
 * dialog of "Per kelas") offer the student actions of Admin › Kelas › Lihat siswa: ticks with "Pilih semua", "Ubah status"
 * (Admin, user.archive), "Hapus" (student.trash: Admin and Kesiswaan, the shared dialog HapusSiswa) and "Ubah data"
 * (student.update, the shared dialog UbahDataSiswa, D-187). Every id is checked against the rows the screen listed.
 * "Download Excel" stands on both tabs and in the class dialog; no student = a warning toast, no file. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();
    $this->withoutVite();

    $this->admin = A::admin();
    $this->kesiswaan = A::staff(RoleName::Kesiswaan);
    $this->x1 = Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
    $this->xi2 = Classroom::factory()->level(11)->create(['name' => 'XI-2']);

    $make = function (Classroom $class, string $name, array $user = []): Student {
        $student = Student::factory()->create(['classroom_id' => $class->getKey()]);
        $student->user->forceFill(array_merge(['name' => $name], $user))->saveQuietly();

        return $student->fresh();
    };

    $this->nadia = $make($this->x1, 'Nadia Putri Anindya', ['initial_password_expires_at' => now()->addDays(5)]);
    $this->raka = $make($this->x1, 'Raka Pratama', ['status' => UserStatus::Disabled]);
    $this->sinta = $make($this->xi2, 'Sinta Ayu Lestari', ['initial_password_expires_at' => now()->addDays(5)]);
});

/** The screen as $user with a password confirmation from just now, on "Daftar siswa". */
function sv6List(User $user): Testable
{
    test()->actingAs($user);
    session(A::passwordConfirmed());

    return Livewire::test(StatusAktivasi::class)->call('setView', 'siswa');
}

/** The Excel file of a Livewire download, written to a temp .xlsx. */
function sv6Download(Testable $component): string
{
    $path = tempnam(sys_get_temp_dir(), 'sth-sv6-').'.xlsx';
    file_put_contents($path, base64_decode((string) ($component->effects['download']['content'] ?? '')));

    return $path;
}

/** @return list<string> sheet names of an .xlsx, in order */
function sv6Sheets(string $xlsx): array
{
    $zip = new ZipArchive;
    $zip->open($xlsx);
    $book = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
    $zip->close();
    $book->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    return array_map(fn (SimpleXMLElement $sheet): string => (string) $sheet['name'], $book->xpath('//m:sheets/m:sheet'));
}

// --- Who sees what ---------------------------------------------------------------------------------------------------

it('gives Admin ticks, "Pilih semua", Ubah data, Ubah status and Hapus on both lists, with the three shared dialogs', function (): void {
    $list = sv6List($this->admin)
        ->assertSee('Pilih semua yang tampil')
        ->assertSeeHtml('Ubah status terpilih (<span class="num">0</span>)')
        ->assertSeeHtml('Hapus terpilih (<span class="num">0</span>)');
    $dialog = Livewire::test(StatusAktivasi::class)->call('showClass', $this->x1->getKey())->assertSee('Pilih semua yang tampil');

    foreach ([$this->nadia, $this->raka] as $student) {
        foreach ([$list, $dialog] as $screen) {
            $screen->assertSeeHtml('wire:click="askData('.$student->user_id.')"')
                ->assertSeeHtml('wire:click="askStatus('.$student->user_id.')"')
                ->assertSeeHtml('wire:click="askTrashStudent('.$student->user_id.')"');
        }

        $list->assertSeeHtml('id="aktivasi-siswa-pilih-'.$student->user_id.'" value="'.$student->user_id.'" wire:model.live="selectedStudents"');
        $dialog->assertSeeHtml('id="aktivasi-kelas-pilih-'.$student->user_id.'" value="'.$student->user_id.'" wire:model.live="selectedStudents"');
    }

    $this->get(route('admin.aktivasi.index', ['tampil' => 'siswa']))
        ->assertOk()
        ->assertSee('id="'.UbahStatusSiswa::DIALOG.'"', false)
        ->assertSee('id="'.HapusSiswa::DIALOG.'"', false)
        ->assertSee('wire:name="akun.ubah-data-siswa"', false);
});

it('gives Kesiswaan ticks, Ubah data and Hapus but no Ubah status (no user.archive)', function (): void {
    foreach ([sv6List($this->kesiswaan), Livewire::test(StatusAktivasi::class)->call('showClass', $this->x1->getKey())] as $screen) {
        $screen->assertSee('Pilih semua yang tampil')
            ->assertSeeHtml('Hapus terpilih (<span class="num">0</span>)')
            ->assertDontSee('Ubah status terpilih')
            ->assertDontSeeHtml('askStatus(')
            ->assertDontSeeHtml('askStatusSelected')
            ->assertSeeHtml('wire:click="askTrashStudent('.$this->nadia->user_id.')"')
            ->assertSeeHtml('wire:click="askData('.$this->nadia->user_id.')"');
    }

    $this->get(route('kesiswaan.aktivasi.index', ['tampil' => 'siswa']))
        ->assertOk()
        ->assertDontSee('id="'.UbahStatusSiswa::DIALOG.'"', false)
        ->assertSee('id="'.HapusSiswa::DIALOG.'"', false)
        ->assertSee('wire:name="akun.ubah-data-siswa"', false);

    $screen = sv6List($this->kesiswaan);
    $screen->call('askStatus', $this->nadia->user_id)->assertForbidden();
    sv6List($this->kesiswaan)->set('selectedStudents', [(string) $this->nadia->user_id])->call('askStatusSelected')->assertForbidden();
});

it('shows no ticks, no Hapus and no Ubah data to a Kesiswaan whose role lost student.trash and student.update', function (): void {
    Role::findByName(RoleName::Kesiswaan->value)->revokePermissionTo(['student.trash', 'student.update']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $kesiswaan = $this->kesiswaan->fresh();

    $screen = sv6List($kesiswaan)
        ->assertDontSee('Pilih semua yang tampil')
        ->assertDontSeeHtml('askTrashStudent(')
        ->assertDontSeeHtml('askData(')
        ->assertDontSeeHtml('wire:model.live="selectedStudents"');

    $screen->call('askTrashStudent', $this->nadia->user_id)->assertForbidden();
    sv6List($kesiswaan)->call('askData', $this->nadia->user_id)->assertForbidden();
    sv6List($kesiswaan)->set('selectedStudents', [(string) $this->nadia->user_id])->call('askTrashSelected')->assertForbidden();

    $this->get(route('kesiswaan.aktivasi.index', ['tampil' => 'siswa']))
        ->assertOk()
        ->assertDontSee('id="'.HapusSiswa::DIALOG.'"', false)
        ->assertDontSee('wire:name="akun.ubah-data-siswa"', false);

    expect(Student::query()->whereKey($this->nadia->getKey())->exists())->toBeTrue();
});

// --- One row ---------------------------------------------------------------------------------------------------------

it('opens the shared delete dialog for one listed row, from the list and from the class dialog', function (User $actor): void {
    sv6List($actor)
        ->call('askTrashStudent', $this->nadia->user_id)
        ->assertDispatchedTo(HapusSiswa::class, HapusSiswa::OPEN_EVENT, ids: [(int) $this->nadia->user_id]);

    Livewire::test(StatusAktivasi::class)
        ->call('showClass', $this->xi2->getKey())
        ->call('askTrashStudent', $this->sinta->user_id)
        ->assertDispatchedTo(HapusSiswa::class, HapusSiswa::OPEN_EVENT, ids: [(int) $this->sinta->user_id]);
})->with([
    'admin' => fn () => test()->admin,
    'kesiswaan' => fn () => test()->kesiswaan,
]);

it('opens the shared "Ubah data siswa" dialog for one listed row (D-187)', function (): void {
    sv6List($this->kesiswaan)
        ->call('askData', $this->raka->user_id)
        ->assertDispatched(UbahDataSiswa::OPEN_EVENT, userId: (int) $this->raka->user_id);

    Livewire::test(StatusAktivasi::class)
        ->call('showClass', $this->x1->getKey())
        ->call('askData', $this->nadia->user_id)
        ->assertDispatched(UbahDataSiswa::OPEN_EVENT, userId: (int) $this->nadia->user_id);
});

it('refuses an id the screen never listed with 404: a staff account, an unknown id, a student of another class', function (string $method): void {
    // The class dialog of X-1 lists Nadia and Raka only; nothing else was drawn in this visit.
    $screen = fn (): Testable => Livewire::actingAs($this->admin)->test(StatusAktivasi::class)->call('showClass', $this->x1->getKey());
    session(A::passwordConfirmed());

    foreach ([(int) $this->kesiswaan->getKey(), 999999, (int) $this->sinta->user_id] as $id) {
        $screen()->call($method, $id)
            ->assertNotFound()
            ->assertNotDispatched(HapusSiswa::OPEN_EVENT)
            ->assertNotDispatched(UbahStatusSiswa::OPEN_EVENT)
            ->assertNotDispatched(UbahDataSiswa::OPEN_EVENT);
    }
})->with(['askStatus', 'askTrashStudent', 'askData']);

it('answers a listed student who went to the trash meanwhile with a toast, never an error page', function (string $method): void {
    $screen = sv6List($this->admin);
    app(TrashStudents::class)->handle($this->admin, [$this->nadia->user_id], 'Dihapus dari tab lain.');

    $screen->call($method, $this->nadia->user_id)
        ->assertOk()
        ->assertDispatched('sth-toast', message: StatusAktivasi::MSG_STUDENT_GONE, tone: 'warning')
        ->assertNotDispatched(HapusSiswa::OPEN_EVENT)
        ->assertNotDispatched(UbahStatusSiswa::OPEN_EVENT)
        ->assertNotDispatched(UbahDataSiswa::OPEN_EVENT);
})->with(['askStatus', 'askTrashStudent', 'askData']);

// --- Ticks -----------------------------------------------------------------------------------------------------------

it('ticks rows one by one or all at once and opens Ubah status or Hapus for the ticked ones', function (): void {
    $screen = sv6List($this->admin)
        ->set('selectedStudents', [(string) $this->sinta->user_id, (string) $this->raka->user_id])
        ->assertSeeHtml('Hapus terpilih (<span class="num">2</span>)');

    $screen->call('askTrashSelected')
        ->assertDispatchedTo(HapusSiswa::class, HapusSiswa::OPEN_EVENT, ids: [(int) $this->sinta->user_id, (int) $this->raka->user_id]);
    $screen->call('askStatusSelected')
        ->assertDispatchedTo(UbahStatusSiswa::class, UbahStatusSiswa::OPEN_EVENT, ids: [(int) $this->sinta->user_id, (int) $this->raka->user_id]);

    // "Pilih semua yang tampil" ticks every row of the list (by class, then name), a second click clears them.
    $screen->call('toggleAllStudents')
        ->assertSet('selectedStudents', [(string) $this->nadia->user_id, (string) $this->raka->user_id, (string) $this->sinta->user_id])
        ->assertSeeHtml('Hapus terpilih (<span class="num">3</span>)');
    expect($screen->viewData('listTicks'))->toBe(['selected' => 3, 'allSelected' => true]);

    $screen->call('toggleAllStudents')->assertSet('selectedStudents', []);

    // In the class dialog only that class.
    Livewire::test(StatusAktivasi::class)
        ->call('showClass', $this->x1->getKey())
        ->call('toggleAllStudents')
        ->assertSet('selectedStudents', [(string) $this->nadia->user_id, (string) $this->raka->user_id])
        ->call('askTrashSelected')
        ->assertDispatchedTo(HapusSiswa::class, HapusSiswa::OPEN_EVENT, ids: [(int) $this->nadia->user_id, (int) $this->raka->user_id]);
});

it('clears the ticks when the list changes and after a shared dialog changed something', function (string $change): void {
    $screen = sv6List($this->admin)->set('selectedStudents', [(string) $this->nadia->user_id]);

    match ($change) {
        'status tab' => $screen->call('setStatus', AccountControls::STATE_PENDING),
        'class filter' => $screen->set('daftarKelas', $this->x1->getKey()),
        'search' => $screen->set('q', 'Nadia'),
        'tab' => $screen->call('setView', 'kelas'),
        'class dialog' => $screen->call('showClass', $this->x1->getKey()),
        'delete done' => $screen->dispatch(HapusSiswa::DONE_EVENT),
        'status done' => $screen->dispatch(UbahStatusSiswa::DONE_EVENT),
        'data done' => $screen->dispatch(UbahDataSiswa::DONE_EVENT),
    };

    $screen->assertSet('selectedStudents', []);
})->with(['status tab', 'class filter', 'search', 'tab', 'class dialog', 'delete done', 'status done', 'data done']);

it('refuses a forged or malformed tick with 404 and says to tick first when nothing is ticked', function (): void {
    sv6List($this->kesiswaan)->set('selectedStudents', [(string) $this->nadia->user_id, (string) $this->kesiswaan->getKey()])
        ->call('askTrashSelected')
        ->assertNotFound();
    sv6List($this->kesiswaan)->set('selectedStudents', ['1 OR 1=1'])->call('askTrashSelected')->assertNotFound();
    // A student of another class the class dialog never listed.
    Livewire::test(StatusAktivasi::class)->call('showClass', $this->x1->getKey())
        ->set('selectedStudents', [(string) $this->sinta->user_id])
        ->call('askTrashSelected')
        ->assertNotFound();

    sv6List($this->kesiswaan)->call('askTrashSelected')
        ->assertDispatched('sth-toast', message: StatusAktivasi::MSG_NONE_SELECTED, tone: 'warning')
        ->assertNotDispatched(HapusSiswa::OPEN_EVENT);

    expect(TrashEntry::query()->count())->toBe(0);
});

it('drops a ticked student who went to the trash meanwhile and opens nothing', function (): void {
    $screen = sv6List($this->kesiswaan)->set('selectedStudents', [(string) $this->nadia->user_id, (string) $this->sinta->user_id]);
    app(TrashStudents::class)->handle($this->admin, [$this->nadia->user_id], 'Dihapus dari tab lain.');

    $screen->call('askTrashSelected')
        ->assertOk()
        ->assertDispatched('sth-toast', message: StatusAktivasi::MSG_STUDENTS_GONE, tone: 'warning')
        ->assertNotDispatched(HapusSiswa::OPEN_EVENT)
        ->assertSet('selectedStudents', [(string) $this->sinta->user_id]);
});

/** $count students waiting for their activation in one class, written in bulk (the factories would take minutes). */
function sv6ManyStudents(Classroom $classroom, int $count): void
{
    $now = now();
    $password = Hash::make('password');

    foreach (array_chunk(range(1, $count), 90) as $chunk) {
        DB::table('users')->insert(array_map(fn (int $n): array => [
            'name' => sprintf('Siswa Contoh %04d', $n),
            'password' => $password,
            'status' => UserStatus::PendingActivation->value,
            'must_change_password' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $chunk));
    }

    $userIds = DB::table('users')->where('name', 'like', 'Siswa Contoh %')->orderBy('id')->pluck('id')->all();

    foreach (array_chunk($userIds, 90, true) as $chunk) {
        $rows = [];

        foreach ($chunk as $n => $userId) {
            $rows[] = [
                'id' => strtolower((string) Str::ulid()),
                'user_id' => $userId,
                'nis' => sprintf('8%08d', $n),
                'nisn' => sprintf('08%08d', $n),
                'birth_date' => '2010-05-05',
                'classroom_id' => $classroom->getKey(),
                'import_school_year' => '2026/2027',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('students')->insert($rows);
    }
}

it('ticks at most 400 rows with "Pilih semua" and refuses more ticks than that with a toast', function (): void {
    $big = Classroom::factory()->level(12)->create(['name' => 'XII-1']);
    sv6ManyStudents($big, StatusAktivasi::MAX_SELECTED + 5);
    $this->actingAs($this->kesiswaan);

    $screen = Livewire::withQueryParams(['kelas_daftar' => $big->getKey()])
        ->test(StatusAktivasi::class, ['limit' => StatusAktivasi::MAX_SELECTED + 20])
        ->assertSet('tampil', StatusAktivasi::VIEW_STUDENTS);

    expect(count($screen->viewData('rows')))->toBe(StatusAktivasi::MAX_SELECTED + 5);

    $screen->call('toggleAllStudents')
        ->assertDispatched('sth-toast', message: 'Paling banyak 400 siswa sekaligus. 400 siswa pertama dipilih.', tone: 'warning');

    expect($screen->get('selectedStudents'))->toHaveCount(StatusAktivasi::MAX_SELECTED)
        ->and($screen->viewData('listTicks'))->toBe(['selected' => 400, 'allSelected' => true]);

    $screen->call('askTrashSelected')
        ->assertDispatchedTo(HapusSiswa::class, HapusSiswa::OPEN_EVENT);

    // Ticked by hand beyond the bound: a toast instead of a dialog that would refuse it.
    $all = collect($screen->viewData('rows'))->pluck('userId')->map(fn (int $id): string => (string) $id)->all();
    $screen->set('selectedStudents', $all)
        ->call('askTrashSelected')
        ->assertDispatched('sth-toast', message: 'Paling banyak 400 siswa sekaligus.', tone: 'warning');
});

// --- Download Excel --------------------------------------------------------------------------------------------------

it('offers Download Excel on both tabs and in the class dialog', function (): void {
    $this->actingAs($this->kesiswaan);

    Livewire::test(StatusAktivasi::class)
        ->assertSeeHtml('wire:click="export"')
        ->assertSee('Isi file: Semua kelas, semua status.')
        ->call('showClass', $this->x1->getKey())
        ->assertSeeHtml("wire:click=\"exportClass('".$this->x1->getKey()."')\"")
        ->call('setView', 'siswa')
        ->assertSeeHtml('wire:click="export"')
        ->call('setStatus', AccountControls::STATE_PENDING)
        ->set('q', 'Nadia')
        ->assertSee('Isi file: Semua kelas, status Belum aktivasi, hasil pencarian.');
});

it('downloads the whole class from the class dialog, every status, one sheet named after the class', function (): void {
    $screen = sv6List($this->kesiswaan)
        ->call('showClass', $this->x1->getKey())
        // The dialog's status tab does not narrow the file.
        ->call('setClassStatus', AccountControls::STATE_DISABLED)
        ->call('exportClass', $this->x1->getKey());
    $file = sv6Download($screen);
    $rows = SimpleExcelReader::create($file)->fromSheetName('X-1')->getRows()->all();
    $log = Activity::query()->where('event', ExportActivationReport::EVENT)->sole();

    expect($screen->effects['download']['name'] ?? '')->toStartWith('status-aktivasi-kelas-x-1-')
        ->and(sv6Sheets($file))->toBe(['X-1'])
        ->and(array_column($rows, 'Nama'))->toBe(['Nadia Putri Anindya', 'Raka Pratama'])
        ->and(array_column($rows, 'Status'))->toBe(['Belum aktivasi', 'Nonaktif'])
        ->and($log->properties->only(['kelas', 'source', 'rows'])->all())->toEqual(['kelas' => 'X-1', 'source' => 'aktivasi', 'rows' => 2])
        ->and(str_contains((string) json_encode($log->properties), 'Nadia'))->toBeFalse();

    // The dialog stays on its class.
    $screen->assertSet('kelas', (string) $this->x1->getKey());
});

it('says that no student matches instead of an empty file, and audits nothing', function (): void {
    $empty = Classroom::factory()->level(12)->create(['name' => 'XII-9']);

    // A status tab nobody is in.
    sv6List($this->kesiswaan)->call('setStatus', AccountControls::STATE_FROZEN)->call('export')
        ->assertDispatched('sth-toast', message: ExportActivationReport::MSG_EMPTY, tone: 'warning')
        ->assertNoFileDownloaded();
    // A tingkat without students.
    sv6List($this->kesiswaan)->call('setView', 'kelas')->set('unduhTingkat', '12')->call('export')
        ->assertDispatched('sth-toast', message: ExportActivationReport::MSG_EMPTY, tone: 'warning')
        ->assertNoFileDownloaded();
    // A class without students.
    sv6List($this->kesiswaan)->call('exportClass', $empty->getKey())
        ->assertDispatched('sth-toast', message: ExportActivationReport::MSG_EMPTY, tone: 'warning')
        ->assertNoFileDownloaded();
    // A class deleted meanwhile, or an id that never was one.
    sv6List($this->kesiswaan)->call('exportClass', 'tidak-ada')
        ->assertDispatched('sth-toast', message: StatusAktivasi::MSG_CLASS_GONE, tone: 'warning')
        ->assertNoFileDownloaded();

    expect(Activity::query()->where('event', ExportActivationReport::EVENT)->exists())->toBeFalse();
});

it('sends the class download through the password page and resumes it there', function (): void {
    $this->actingAs($this->kesiswaan);

    Livewire::test(StatusAktivasi::class)
        ->call('showClass', $this->x1->getKey())
        ->call('exportClass', $this->x1->getKey())
        ->assertRedirect(route('password.confirm'))
        ->assertNoFileDownloaded();

    // Back on the screen, with the dialog of that class.
    expect(session('url.intended'))->toContain('kelas='.$this->x1->getKey());

    session(A::passwordConfirmed());
    Livewire::withQueryParams(['kelas' => $this->x1->getKey()])->test(StatusAktivasi::class)
        ->assertSeeHtml('wire:init="exportClass(&#039;'.$this->x1->getKey().'&#039;)"');

    expect(Activity::query()->where('event', ExportActivationReport::EVENT)->exists())->toBeFalse();
});

it('refuses the downloads to a role without student.view', function (RoleName $role): void {
    $this->actingAs(A::staff($role));

    Livewire::test(StatusAktivasi::class)->assertForbidden();
})->with([RoleName::Humas, RoleName::Guru]);
