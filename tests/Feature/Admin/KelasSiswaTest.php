<?php

declare(strict_types=1);

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\ActivationReport;
use App\Actions\Accounts\DisableAccount;
use App\Actions\Accounts\ExportActivationReport;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\HapusSiswa;
use App\Livewire\Admin\Kelas;
use App\Livewire\Admin\UbahStatusSiswa;
use App\Livewire\Akun\UbahDataSiswa;
use App\Models\Classroom;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 * The lead, 2026-11-04: Admin › Kelas shows the students of a class ("Lihat siswa" or the class name). SV4 disabled one
 * from there; SV5 (the same day, "bisa memilih siswa untuk diubah statusnya, apapun, semuanya bisa") ticks one or many
 * and changes their status in the shared dialog UbahStatusSiswa, which replaces SV4's "Nonaktifkan". Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    $this->withoutVite();
    $this->admin = A::admin();
    $this->x1 = Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
    $this->x2 = Classroom::factory()->create(['name' => 'X-2', 'level' => 10]);
    $this->actingAs($this->admin);
});

function kelasSiswa(Classroom $classroom, string $name, UserStatus $status = UserStatus::Active): Student
{
    $student = Student::factory()->create(['classroom_id' => $classroom->getKey()]);
    $student->user->forceFill(['name' => $name, 'status' => $status])->saveQuietly();

    return $student->fresh();
}

// --- The student dialog ------------------------------------------------------------------------------------------

it('offers "Lihat siswa" and the class name as a button on every row', function (): void {
    Livewire::test(Kelas::class)
        ->assertSeeHtml("wire:click=\"showStudents('{$this->x1->getKey()}')\"")
        ->assertSeeHtml("wire:click=\"showStudents('{$this->x2->getKey()}')\"")
        ->assertSee('Lihat siswa');
});

it('lists only the students of the chosen class, by name, with NISN, state, a tick and "Ubah status" on every row', function (): void {
    $raka = kelasSiswa($this->x1, 'Raka Pratama');
    $nadia = kelasSiswa($this->x1, 'Nadia Putri Anindya', UserStatus::PendingActivation);
    $dimas = kelasSiswa($this->x1, 'Dimas Saputra', UserStatus::Disabled);
    $bima = kelasSiswa($this->x1, 'Bima Alumni', UserStatus::Archived);
    $salsa = kelasSiswa($this->x1, 'Salsa Maharani', UserStatus::PendingActivation);
    $salsa->user->forceFill(['frozen_at' => now(), 'frozen_reason' => AccountControls::FROZEN_FAILED_ATTEMPTS])->saveQuietly();
    $other = kelasSiswa($this->x2, 'Citra Kelas Lain');

    $screen = Livewire::test(Kelas::class)
        ->call('showStudents', $this->x1->getKey())
        ->assertSet('viewingId', (string) $this->x1->getKey())
        ->assertDispatched('sth-modal-open', id: Kelas::STUDENTS_DIALOG)
        ->assertSee('Kelas X-1')
        ->assertSee((string) $nadia->nisn)
        ->assertSee('Belum aktivasi')
        ->assertSee('Diarsipkan')
        ->assertSee('Dibekukan')
        ->assertSee('Pilih semua yang tampil')
        ->assertSeeHtml('Ubah status terpilih (<span class="num">0</span>)')
        ->assertDontSee('Citra Kelas Lain')
        ->assertDontSee((string) $other->nisn);

    $dialog = $screen->viewData('studentsDialog');

    expect(array_column($dialog['rows'], 'name'))->toBe(['Bima Alumni', 'Dimas Saputra', 'Nadia Putri Anindya', 'Raka Pratama', 'Salsa Maharani'])
        ->and($dialog['count'])->toBe(5)
        ->and($dialog['active'])->toBe(3)
        ->and($dialog['selected'])->toBe(0)
        ->and(collect($dialog['rows'])->mapWithKeys(fn (array $r) => [$r['name'] => [$r['nisn'], $r['state'], $r['status'], $r['tone']]])->all())
        ->toBe([
            'Bima Alumni' => [$bima->nisn, 'arsip', 'Diarsipkan', 'gray'],
            'Dimas Saputra' => [$dimas->nisn, 'nonaktif', 'Nonaktif', 'gray'],
            'Nadia Putri Anindya' => [$nadia->nisn, 'belum', 'Belum aktivasi', 'gray'],
            'Raka Pratama' => [$raka->nisn, 'aktif', 'Aktif', 'green'],
            'Salsa Maharani' => [$salsa->nisn, 'dibekukan', 'Dibekukan', 'red'],
        ]);

    foreach ([$raka, $nadia, $dimas, $bima, $salsa] as $student) {
        $screen->assertSeeHtml('wire:click="askStatus('.$student->user_id.')"')
            ->assertSeeHtml('id="kelas-siswa-pilih-'.$student->user_id.'" value="'.$student->user_id.'" wire:model.live="selectedStudents"');
    }

    // SV4's one-way "Nonaktifkan" and its dialog are gone: one way to change a status.
    $screen->assertDontSeeHtml('askDisable(')->assertDontSeeHtml('kelas-siswa-nonaktif');
});

it('renders the shared dialog once on the page', function (): void {
    $this->get(route('admin.kelas.index'))
        ->assertOk()
        ->assertSee('id="'.UbahStatusSiswa::DIALOG.'"', false)
        ->assertSee('data-sth-on-close="forget"', false);
});

it('shows an empty class with a sentence instead of a table', function (): void {
    Livewire::test(Kelas::class)
        ->call('showStudents', $this->x2->getKey())
        ->assertSee('Belum ada siswa di kelas ini')
        ->assertDontSee('Pilih semua yang tampil');
});

it('shows the students in steps with "Tampilkan lebih banyak"', function (): void {
    $count = Kelas::STUDENTS_STEP + 3;
    Student::factory()->count($count)->create(['classroom_id' => $this->x1->getKey()]);

    $screen = Livewire::test(Kelas::class)->call('showStudents', $this->x1->getKey());
    $dialog = $screen->viewData('studentsDialog');

    expect($dialog['rows'])->toHaveCount(Kelas::STUDENTS_STEP)
        ->and($dialog['hasMore'])->toBeTrue()
        ->and($dialog['count'])->toBe($count);

    $screen->assertSee('Tampilkan lebih banyak')
        ->call('showMoreStudents')
        ->assertSet('studentLimit', 2 * Kelas::STUDENTS_STEP);

    $dialog = $screen->viewData('studentsDialog');
    expect($dialog['rows'])->toHaveCount($count)
        ->and($dialog['hasMore'])->toBeFalse();

    // A forged limit is clamped.
    $screen->set('studentLimit', 100000);
    expect($screen->get('studentLimit'))->toBe(Kelas::STUDENTS_MAX);
});

it('forgets the class and the ticks when the dialog closes', function (): void {
    $raka = kelasSiswa($this->x1, 'Raka Pratama');

    Livewire::test(Kelas::class)
        ->call('showStudents', $this->x1->getKey())
        ->set('selectedStudents', [(string) $raka->user_id])
        ->call('closeStudents')
        ->assertSet('viewingId', null)
        ->assertSet('shownStudents', [])
        ->assertSet('selectedStudents', [])
        ->assertViewHas('studentsDialog', null);
});

it('answers a class deleted in another tab with a toast', function (): void {
    $id = $this->x2->getKey();
    $this->x2->delete();

    Livewire::test(Kelas::class)
        ->call('showStudents', $id)
        ->assertOk()
        ->assertSet('viewingId', null)
        ->assertDispatched('sth-toast', message: Kelas::MSG_GONE, tone: 'warning')
        ->assertNotDispatched('sth-modal-open');
});

// --- Ticks and "Ubah status" -----------------------------------------------------------------------------------------

it('opens the shared dialog for one row with "Ubah status"', function (UserStatus $status): void {
    $raka = kelasSiswa($this->x1, 'Raka Pratama', $status);

    Livewire::test(Kelas::class)
        ->call('showStudents', $this->x1->getKey())
        ->call('askStatus', $raka->user_id)
        ->assertDispatchedTo(UbahStatusSiswa::class, UbahStatusSiswa::OPEN_EVENT, ids: [(int) $raka->user_id]);
})->with([UserStatus::Active, UserStatus::PendingActivation, UserStatus::Disabled, UserStatus::Archived]);

it('ticks rows one by one or all at once and opens the dialog for the ticked ones', function (): void {
    $bima = kelasSiswa($this->x1, 'Bima Alumni', UserStatus::Archived);
    $dimas = kelasSiswa($this->x1, 'Dimas Saputra', UserStatus::Disabled);
    $raka = kelasSiswa($this->x1, 'Raka Pratama');

    $screen = Livewire::test(Kelas::class)
        ->call('showStudents', $this->x1->getKey())
        ->set('selectedStudents', [(string) $dimas->user_id, (string) $bima->user_id])
        ->assertSeeHtml('Ubah status terpilih (<span class="num">2</span>)');

    expect($screen->viewData('studentsDialog')['selected'])->toBe(2)
        ->and($screen->viewData('studentsDialog')['allSelected'])->toBeFalse();

    $screen->call('askStatusSelected')
        ->assertDispatchedTo(UbahStatusSiswa::class, UbahStatusSiswa::OPEN_EVENT, ids: [(int) $dimas->user_id, (int) $bima->user_id]);

    // "Pilih semua yang tampil" ticks every listed row, a second click clears them.
    $screen->call('toggleAllStudents')
        ->assertSet('selectedStudents', [(string) $bima->user_id, (string) $dimas->user_id, (string) $raka->user_id])
        ->assertSeeHtml('Ubah status terpilih (<span class="num">3</span>)');
    expect($screen->viewData('studentsDialog')['allSelected'])->toBeTrue();

    $screen->call('toggleAllStudents')->assertSet('selectedStudents', []);
});

it('ticks only the rows shown when "Pilih semua" is used on a long class', function (): void {
    Student::factory()->count(Kelas::STUDENTS_STEP + 2)->create(['classroom_id' => $this->x1->getKey()]);

    $screen = Livewire::test(Kelas::class)
        ->call('showStudents', $this->x1->getKey())
        ->call('toggleAllStudents');

    expect($screen->get('selectedStudents'))->toHaveCount(Kelas::STUDENTS_STEP);
});

it('says to tick a student first when nothing is ticked', function (): void {
    kelasSiswa($this->x1, 'Raka Pratama');

    Livewire::test(Kelas::class)
        ->call('showStudents', $this->x1->getKey())
        ->call('askStatusSelected')
        ->assertDispatched('sth-toast', message: Kelas::MSG_NONE_SELECTED, tone: 'warning')
        ->assertNotDispatched(UbahStatusSiswa::OPEN_EVENT);
});

it('drops a tick of a student who left the class meanwhile and opens nothing', function (): void {
    $raka = kelasSiswa($this->x1, 'Raka Pratama');
    $nadia = kelasSiswa($this->x1, 'Nadia Putri Anindya');

    $screen = Livewire::test(Kelas::class)
        ->call('showStudents', $this->x1->getKey())
        ->set('selectedStudents', [(string) $raka->user_id, (string) $nadia->user_id]);

    $raka->forceFill(['classroom_id' => $this->x2->getKey()])->save();

    $screen->call('askStatusSelected')
        ->assertOk()
        ->assertDispatched('sth-toast', message: Kelas::MSG_STUDENTS_MOVED, tone: 'warning')
        ->assertNotDispatched(UbahStatusSiswa::OPEN_EVENT)
        ->assertSet('selectedStudents', [(string) $nadia->user_id]);

    // One row: the same toast, no dialog.
    $screen->call('askStatus', $raka->user_id)
        ->assertDispatched('sth-toast', message: Kelas::MSG_STUDENT_MOVED, tone: 'warning')
        ->assertNotDispatched(UbahStatusSiswa::OPEN_EVENT);
});

it('refuses a forged id: a staff account, a student of another class, nothing listed yet, a malformed tick', function (string $case, string $call): void {
    $raka = kelasSiswa($this->x1, 'Raka Pratama');
    $other = kelasSiswa($this->x2, 'Citra Kelas Lain');
    $guru = A::staff(RoleName::Guru);

    $screen = Livewire::test(Kelas::class);

    if ($case !== 'nothing listed') {
        $screen->call('showStudents', $this->x1->getKey());
    }

    $id = match ($case) {
        'staff' => (string) $guru->getKey(),
        'other class' => (string) $other->user_id,
        'nothing listed' => (string) $raka->user_id,
        'malformed' => '1 OR 1=1',
    };

    $call === 'askStatus'
        ? $screen->call('askStatus', (int) $id)->assertNotFound()->assertNotDispatched(UbahStatusSiswa::OPEN_EVENT)
        : $screen->set('selectedStudents', [(string) $raka->user_id, $id])->call('askStatusSelected')->assertNotFound()->assertNotDispatched(UbahStatusSiswa::OPEN_EVENT);

    expect(Activity::query()->whereIn('event', ['account.disabled', 'account.archived', 'account.enabled'])->exists())->toBeFalse();
})->with([
    'staff, one row' => ['staff', 'askStatus'],
    'other class, one row' => ['other class', 'askStatus'],
    'nothing listed, one row' => ['nothing listed', 'askStatus'],
    'staff, ticked' => ['staff', 'askStatusSelected'],
    'other class, ticked' => ['other class', 'askStatusSelected'],
    'nothing listed, ticked' => ['nothing listed', 'askStatusSelected'],
    'malformed tick' => ['malformed', 'askStatusSelected'],
]);

// --- After a change -------------------------------------------------------------------------------------------------

it('redraws the list, the chips, the counts and clears the ticks after the shared dialog changed students', function (): void {
    session(A::passwordConfirmed());
    $raka = kelasSiswa($this->x1, 'Raka Pratama');
    $dimas = kelasSiswa($this->x1, 'Dimas Saputra', UserStatus::Disabled);
    $dimas->user->forceFill(['must_change_password' => false, 'email' => 'dimas.saputra@example.test', 'email_verified_at' => now()])->saveQuietly();
    $dimas->forceFill(['archive_at' => CarbonImmutable::now('Asia/Jakarta')->addYear()->toDateString()])->save();

    $screen = Livewire::test(Kelas::class)
        ->call('showStudents', $this->x1->getKey())
        ->set('selectedStudents', [(string) $dimas->user_id])
        ->call('askStatusSelected');

    expect($screen->viewData('groups')[0]['rows'][0])->toMatchArray(['name' => 'X-1', 'active' => 1, 'total' => 2]);

    // The browser hands the event to the dialog, which changes the student and tells the page.
    Livewire::test(UbahStatusSiswa::class, ['returnTo' => route('admin.kelas.index')])
        ->call('open', [(int) $dimas->user_id])
        ->assertSet('statusTarget', UbahStatusSiswa::TARGET_ACTIVE)
        ->set('statusReason', 'Salah menonaktifkan akun.')
        ->call('save')
        ->assertDispatched('sth-toast', message: '1 akun diubah.', tone: 'success')
        ->assertDispatched(UbahStatusSiswa::DONE_EVENT);

    $screen->dispatch(UbahStatusSiswa::DONE_EVENT)
        ->assertSet('selectedStudents', [])
        ->assertSet('viewingId', (string) $this->x1->getKey());

    $rows = collect($screen->viewData('studentsDialog')['rows'])->keyBy('name');

    expect($rows['Dimas Saputra']['status'])->toBe('Aktif')
        ->and($rows['Raka Pratama']['status'])->toBe('Aktif')
        ->and($screen->viewData('studentsDialog')['active'])->toBe(2)
        ->and($screen->viewData('groups')[0]['rows'][0])->toMatchArray(['name' => 'X-1', 'active' => 2, 'total' => 2])
        ->and(Activity::query()->where('event', 'account.enabled')->where('subject_id', $dimas->user_id)->exists())->toBeTrue();

    // Disable him again from the same list: the chip follows.
    app(DisableAccount::class)->handle($this->admin, $dimas->user->fresh(), 'Pindah sekolah.');
    $screen->dispatch(UbahStatusSiswa::DONE_EVENT);
    expect(collect($screen->viewData('studentsDialog')['rows'])->firstWhere('name', 'Dimas Saputra')['status'])->toBe('Nonaktif');
});

it('refuses the screen, the student list, the ticks and "Ubah status" to Kesiswaan', function (): void {
    $raka = kelasSiswa($this->x1, 'Raka Pratama');
    $kesiswaan = A::staff(RoleName::Kesiswaan);

    $this->actingAs($kesiswaan)->get(route('admin.kelas.index'))->assertForbidden();
    Livewire::test(Kelas::class)->assertForbidden();

    // Screens an Admin opened, then used by another account: every action asks the policies again.
    $this->actingAs($this->admin);
    $opened = fn () => Livewire::test(Kelas::class)
        ->call('showStudents', $this->x1->getKey())
        ->set('selectedStudents', [(string) $raka->user_id]);
    $screens = [$opened(), $opened(), $opened()];
    $list = Livewire::test(Kelas::class);

    $this->actingAs($kesiswaan);
    $screens[0]->call('askStatus', $raka->user_id)->assertForbidden();
    $screens[1]->call('askStatusSelected')->assertForbidden();
    $screens[2]->call('toggleAllStudents')->assertForbidden();
    $list->call('showStudents', $this->x1->getKey())->assertForbidden();

    expect($raka->user->fresh()->status)->toBe(UserStatus::Active);
});

// --- SV6: the same row actions as Status aktivasi, Ubah data, Download Excel ------------------------------------------

it('offers Ubah data, Ubah status (Aktifkan lagi for a row that can be brought back) and Hapus on every row, with the shared dialogs (SV6)', function (): void {
    $raka = kelasSiswa($this->x1, 'Raka Pratama');
    $dimas = kelasSiswa($this->x1, 'Dimas Saputra', UserStatus::Disabled);

    $screen = Livewire::test(Kelas::class)->call('showStudents', $this->x1->getKey());

    foreach ([$raka, $dimas] as $student) {
        $screen->assertSeeHtml('wire:click="askData('.$student->user_id.')"')
            ->assertSeeHtml('wire:click="askStatus('.$student->user_id.')"')
            ->assertSeeHtml('wire:click="askTrashStudent('.$student->user_id.')"');
    }

    expect(collect($screen->viewData('studentsDialog')['rows'])->pluck('change', 'name')->all())
        ->toBe(['Dimas Saputra' => 'Aktifkan lagi', 'Raka Pratama' => 'Ubah status']);

    $this->get(route('admin.kelas.index'))
        ->assertOk()
        ->assertSee('id="'.HapusSiswa::DIALOG.'"', false)
        ->assertSee('wire:name="akun.ubah-data-siswa"', false)
        ->assertDontSee('kelas-siswa-hapus', false);
});

it('opens the shared "Ubah data siswa" dialog for a listed row only (SV6, D-187)', function (): void {
    $raka = kelasSiswa($this->x1, 'Raka Pratama');
    $other = kelasSiswa($this->x2, 'Citra Kelas Lain');

    Livewire::test(Kelas::class)
        ->call('showStudents', $this->x1->getKey())
        ->call('askData', $raka->user_id)
        ->assertDispatched(UbahDataSiswa::OPEN_EVENT, userId: (int) $raka->user_id);

    Livewire::test(Kelas::class)
        ->call('showStudents', $this->x1->getKey())
        ->call('askData', $other->user_id)
        ->assertNotFound()
        ->assertNotDispatched(UbahDataSiswa::OPEN_EVENT);

    // A student who left the class meanwhile: a toast.
    $screen = Livewire::test(Kelas::class)->call('showStudents', $this->x1->getKey());
    $raka->forceFill(['classroom_id' => $this->x2->getKey()])->save();
    $screen->call('askData', $raka->user_id)
        ->assertDispatched('sth-toast', message: Kelas::MSG_STUDENT_MOVED, tone: 'warning')
        ->assertNotDispatched(UbahDataSiswa::OPEN_EVENT);
});

it('clears the ticks after the shared delete or data dialog finished (SV6)', function (string $event): void {
    $raka = kelasSiswa($this->x1, 'Raka Pratama');

    Livewire::test(Kelas::class)
        ->call('showStudents', $this->x1->getKey())
        ->set('selectedStudents', [(string) $raka->user_id])
        ->dispatch($event)
        ->assertSet('selectedStudents', [])
        ->assertSet('viewingId', (string) $this->x1->getKey());
})->with([HapusSiswa::DONE_EVENT, UbahDataSiswa::DONE_EVENT]);

it('downloads every class from "Download semua kelas": Ringkasan first, one sheet per tingkat with students (SV6, D-185)', function (): void {
    kelasSiswa($this->x1, 'Raka Pratama');
    kelasSiswa($this->x1, 'Dimas Saputra', UserStatus::Disabled);
    $xi = Classroom::factory()->level(11)->create(['name' => 'XI-3']);
    kelasSiswa($xi, 'Nadia Putri Anindya', UserStatus::PendingActivation);

    Livewire::test(Kelas::class)
        ->assertSeeHtml('wire:click="exportAll"')
        ->call('exportAll')
        ->assertRedirect(route('password.confirm'))
        ->assertNoFileDownloaded();

    session(A::passwordConfirmed());
    Livewire::test(Kelas::class)->assertSeeHtml('wire:init="exportAll"');

    $screen = Livewire::test(Kelas::class)->call('exportAll');
    $file = kelasSiswaDownload($screen);
    $summary = SimpleExcelReader::create($file)->fromSheetName(ActivationReport::SUMMARY_SHEET)->getRows()->all();
    $log = Activity::query()->where('event', ExportActivationReport::EVENT)->sole();

    expect($screen->effects['download']['name'] ?? '')->toStartWith('status-aktivasi-semua-')
        ->and(kelasSiswaSheets($file))->toBe([ActivationReport::SUMMARY_SHEET, 'X', 'XI'])
        ->and(array_column($summary, 'Kelas'))->toBe(['X-1', 'XI-3', ActivationReport::SUMMARY_TOTAL])
        ->and(array_column($summary, 'Nonaktif'))->toBe(['1', '0', '1'])
        ->and(array_column(SimpleExcelReader::create($file)->fromSheetName('X')->getRows()->all(), 'Nama'))->toBe(['Dimas Saputra', 'Raka Pratama'])
        ->and($log->properties['source'])->toBe(ExportActivationReport::SOURCE_CLASSES)
        ->and($log->properties['tingkat'])->toBe('semua')
        ->and($log->properties['rows'])->toBe(3);
});

it('downloads one class from "Lihat siswa", and says so when the class has no student (SV6, D-185)', function (): void {
    kelasSiswa($this->x1, 'Raka Pratama');
    session(A::passwordConfirmed());

    $screen = Livewire::test(Kelas::class)
        ->call('showStudents', $this->x1->getKey())
        ->assertSeeHtml("wire:click=\"exportClass('".$this->x1->getKey()."')\"")
        ->call('exportClass', $this->x1->getKey());
    $file = kelasSiswaDownload($screen);

    expect(kelasSiswaSheets($file))->toBe(['X-1'])
        ->and(array_column(SimpleExcelReader::create($file)->fromSheetName('X-1')->getRows()->all(), 'Status'))->toBe(['Aktif'])
        ->and(Activity::query()->where('event', ExportActivationReport::EVENT)->sole()->properties['kelas'])->toBe('X-1');

    // An empty class shows no download, and a forged call answers with a toast and no file.
    Livewire::test(Kelas::class)
        ->call('showStudents', $this->x2->getKey())
        ->assertDontSeeHtml('exportClass(')
        ->call('exportClass', $this->x2->getKey())
        ->assertDispatched('sth-toast', message: ExportActivationReport::MSG_EMPTY, tone: 'warning')
        ->assertNoFileDownloaded();

    Livewire::test(Kelas::class)->call('exportClass', 'tidak-ada')
        ->assertDispatched('sth-toast', message: Kelas::MSG_GONE, tone: 'warning')
        ->assertNoFileDownloaded();

    expect(Activity::query()->where('event', ExportActivationReport::EVENT)->count())->toBe(1);
});

it('says that there is nothing to download when no class has students (SV6)', function (): void {
    session(A::passwordConfirmed());

    Livewire::test(Kelas::class)->call('exportAll')
        ->assertDispatched('sth-toast', message: ExportActivationReport::MSG_EMPTY, tone: 'warning')
        ->assertNoFileDownloaded();

    expect(Activity::query()->where('event', ExportActivationReport::EVENT)->exists())->toBeFalse();
});

it('refuses the downloads, Ubah data and Hapus of Admin › Kelas to Kesiswaan (no classroom.manage)', function (): void {
    $raka = kelasSiswa($this->x1, 'Raka Pratama');
    $opened = fn () => Livewire::test(Kelas::class)->call('showStudents', $this->x1->getKey());
    $screens = [$opened(), $opened(), $opened(), $opened()];

    $this->actingAs(A::staff(RoleName::Kesiswaan));
    session(A::passwordConfirmed());

    $screens[0]->call('exportAll')->assertForbidden();
    $screens[1]->call('exportClass', $this->x1->getKey())->assertForbidden();
    $screens[2]->call('askData', $raka->user_id)->assertForbidden();
    $screens[3]->call('askTrashStudent', $raka->user_id)->assertForbidden();

    expect(Activity::query()->where('event', ExportActivationReport::EVENT)->exists())->toBeFalse()
        ->and(Student::query()->whereKey($raka->getKey())->exists())->toBeTrue();
});

/** The Excel file of a Livewire download, written to a temp .xlsx. */
function kelasSiswaDownload(mixed $component): string
{
    $path = tempnam(sys_get_temp_dir(), 'sth-kelas-').'.xlsx';
    file_put_contents($path, base64_decode((string) ($component->effects['download']['content'] ?? '')));

    return $path;
}

/** @return list<string> sheet names of an .xlsx, in order */
function kelasSiswaSheets(string $xlsx): array
{
    $zip = new ZipArchive;
    $zip->open($xlsx);
    $book = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
    $zip->close();
    $book->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    return array_map(fn (SimpleXMLElement $sheet): string => (string) $sheet['name'], $book->xpath('//m:sheets/m:sheet'));
}
