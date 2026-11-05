<?php

declare(strict_types=1);

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\ActivationReport;
use App\Actions\Accounts\DisableAccount;
use App\Actions\Accounts\ExportActivationReport;
use App\Actions\Accounts\UnfreezeAccount;
use App\Actions\Trash\PurgeFromTrash;
use App\Actions\Trash\RestoreFromTrash;
use App\Actions\Trash\TrashClassroom;
use App\Actions\Trash\TrashStudents;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\HapusSiswa;
use App\Livewire\Admin\Kelas;
use App\Livewire\Admin\UbahStatusSiswa;
use App\Livewire\Akun\StatusAktivasi;
use App\Livewire\Akun\UbahDataSiswa;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\TrashEntry;
use App\Models\User;
use App\Support\ClassroomArea;
use App\Support\Navigation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 | The lead's requests of 5 Nov 2026 for the Kelas page and the student files (smaga_server):
 | - Tata Usaha (`tu.kelas.index`, replacing TU › Data siswa) and Kesiswaan (`kesiswaan.kelas.index`) get the Kelas page
 |   "sama persis Admin": every action, never an `admin.*` address; the other Admin screens stay Admin only;
 | - the Sampah names who deleted ("oleh <nama> (<peran>)") and offers "Hapus permanen" per entry (D-186);
 | - two student files from one implementation: "Data siswa" (the Kelas page) and "Status aktivasi" (Status aktivasi), the
 |   same chooser on both screens.
 | Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    Notification::fake();
    config(['session.driver' => 'database']);
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00', 'Asia/Jakarta'));
    $this->admin = A::admin(['name' => 'Admin Sekolah Contoh']);
    $this->tu = A::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani, S.E.']);
    $this->kesiswaan = A::staff(RoleName::Kesiswaan, ['name' => 'Dra. Wulan Sari']);
    $this->x1 = Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
    $this->x2 = Classroom::factory()->create(['name' => 'X-2', 'level' => 10]);
    $this->xi3 = Classroom::factory()->level(11)->create(['name' => 'XI-3']);
});

function kaSiswa(Classroom $classroom, string $name, array $student = [], array $user = []): Student
{
    $row = Student::factory()->create(array_merge(['classroom_id' => $classroom->getKey()], $student));
    $row->user->forceFill(array_merge(['name' => $name], $user))->saveQuietly();

    return $row->fresh();
}

/** The Kelas page as this account, with a password confirmed just now. */
function kaScreen(User $actor): Testable
{
    test()->actingAs($actor);
    session(A::passwordConfirmed());

    return Livewire::test(Kelas::class);
}

/** @return list<string> sheet names of an .xlsx, in order */
function kaSheets(string $xlsx): array
{
    $zip = new ZipArchive;
    $zip->open($xlsx);
    $book = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
    $zip->close();
    $book->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    return array_map(fn (SimpleXMLElement $sheet): string => (string) $sheet['name'], $book->xpath('//m:sheets/m:sheet'));
}

/** The Excel file of a Livewire download, written to a temp .xlsx. */
function kaDownload(Testable $component): string
{
    $path = tempnam(sys_get_temp_dir(), 'sth-ka-').'.xlsx';
    file_put_contents($path, base64_decode((string) ($component->effects['download']['content'] ?? '')));

    return $path;
}

/** @return array<string, array<string, mixed>> the menu items by key */
function kaMenu(string $role, ?string $route = null): array
{
    return collect(Navigation::for(['name' => 'X', 'roles' => [$role]], $role, [], $route)['sections'])
        ->flatMap(fn (array $section): array => $section['items'])
        ->keyBy('key')
        ->all();
}

// --- Permissions ------------------------------------------------------------------------------------------------------

it('grants Kesiswaan and Tata Usaha the permissions of the Kelas page, and nothing of the Admin-only screens', function (RoleName $role): void {
    $permissions = $role->defaultPermissions();

    expect($permissions)->toContain(Permission::ClassroomManage, Permission::StudentStatus, Permission::StudentTrash, Permission::StudentUpdate, Permission::StudentView)
        ->and($permissions)->not->toContain(Permission::UserManage)
        ->and($permissions)->not->toContain(Permission::UserArchive)
        ->and($permissions)->not->toContain(Permission::UserResetPassword)
        ->and($permissions)->not->toContain(Permission::SettingManage)
        ->and($permissions)->not->toContain(Permission::AuditView);
})->with([RoleName::Kesiswaan, RoleName::TataUsaha]);

it('grants student.status to Admin, Kesiswaan and Tata Usaha only', function (RoleName $role): void {
    expect(in_array(Permission::StudentStatus, $role->defaultPermissions(), true))
        ->toBe(in_array($role, [RoleName::Admin, RoleName::Kesiswaan, RoleName::TataUsaha], true));
})->with(RoleName::cases());

it('keeps the Admin screens Admin only', function (RoleName $role): void {
    $this->actingAs(A::staff($role));

    foreach (['admin.kelas.index', 'admin.aktivasi.index', 'admin.arsip.index', 'admin.audit.index', 'admin.ringkasan', 'admin.import'] as $route) {
        $this->get(route($route))->assertForbidden();
    }

    $this->withSession(A::passwordConfirmed())->get(route('admin.pengguna.index'))->assertForbidden();
    $this->withSession(A::passwordConfirmed())->get(route('admin.pengaturan'))->assertForbidden();
})->with([RoleName::Kesiswaan, RoleName::TataUsaha]);

it('lets student.status change a student account only, never a staff account', function (): void {
    $nadia = kaSiswa($this->x1, 'Nadia Putri Anindya');
    $guru = A::staff(RoleName::Guru);

    app(DisableAccount::class)->handle($this->kesiswaan, $nadia->user, 'Pindah sekolah ke kota lain.');

    expect($nadia->user->fresh()->status)->toBe(UserStatus::Disabled)
        ->and(fn () => app(DisableAccount::class)->handle($this->kesiswaan, $guru, 'Bukan wewenang Kesiswaan.'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DisableAccount::class)->handle($this->tu, $this->kesiswaan, 'Bukan wewenang Tata Usaha.'))->toThrow(AuthorizationException::class)
        ->and($guru->fresh()->status)->toBe(UserStatus::Active)
        ->and($this->kesiswaan->can('viewArchive', User::class))->toBeFalse()
        ->and($this->tu->can('changeArchiveDate', $nadia->user))->toBeFalse();
});

// --- Routes and menus -------------------------------------------------------------------------------------------------

it('opens the Kelas page under the Tata Usaha and the Kesiswaan address, each for its own role', function (): void {
    kaSiswa($this->x1, 'Nadia Putri Anindya');

    $this->actingAs($this->tu)->get(route('tu.kelas.index'))
        ->assertOk()->assertSee('Tambah kelas')->assertSee('X-1')->assertSee('Sampah')->assertSee('Download Excel');
    $this->actingAs($this->kesiswaan)->get(route('kesiswaan.kelas.index'))
        ->assertOk()->assertSee('Tambah kelas')->assertSee('X-1')->assertSee('Sampah');

    $this->actingAs($this->kesiswaan)->get(route('tu.kelas.index'))->assertForbidden();
    $this->actingAs($this->tu)->get(route('kesiswaan.kelas.index'))->assertForbidden();

    foreach ([RoleName::KepalaSekolah, RoleName::Humas, RoleName::Guru] as $role) {
        $staff = A::staff($role);
        $this->actingAs($staff)->get(route('tu.kelas.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('kesiswaan.kelas.index'))->assertForbidden();
    }

    auth()->logout();
    $this->get(route('tu.kelas.index'))->assertRedirect(route('login'));
    $this->get(route('kesiswaan.kelas.index'))->assertRedirect(route('login'));
});

it('lists Kelas in the Kesiswaan menu (Data) and the Tata Usaha menu, each with its own address', function (): void {
    $kesiswaan = kaMenu('kesiswaan', 'kesiswaan.kelas.index');
    $tu = kaMenu('tata_usaha', 'tu.kelas.index');

    expect($kesiswaan['kelas']['href'])->toBe(route('kesiswaan.kelas.index'))
        ->and($kesiswaan['kelas']['current'])->toBeTrue()
        ->and($tu['kelas']['href'])->toBe(route('tu.kelas.index'))
        ->and($tu['kelas']['current'])->toBeTrue()
        ->and(collect(kaMenu('kepala_sekolah'))->pluck('key')->all())->not->toContain('kelas')
        ->and(collect(kaMenu('humas'))->pluck('key')->all())->not->toContain('kelas');
});

it('resolves the area from the address, else from the role', function (): void {
    expect(ClassroomArea::resolve('tu.kelas.index', $this->admin))->toBe(ClassroomArea::TU)
        ->and(ClassroomArea::resolve('kesiswaan.kelas.index', $this->admin))->toBe(ClassroomArea::KESISWAAN)
        ->and(ClassroomArea::resolve('livewire.update', $this->tu))->toBe(ClassroomArea::TU)
        ->and(ClassroomArea::resolve(null, $this->kesiswaan))->toBe(ClassroomArea::KESISWAAN)
        ->and(ClassroomArea::resolve(null, $this->admin))->toBe(ClassroomArea::ADMIN)
        ->and(ClassroomArea::indexUrl(ClassroomArea::TU))->toBe(route('tu.kelas.index'));
});

it('never sends Tata Usaha or Kesiswaan to an admin address: password page, shared dialogs, links', function (string $who, string $route): void {
    $actor = $who === 'tu' ? $this->tu : $this->kesiswaan;
    kaSiswa($this->x1, 'Nadia Putri Anindya');

    $html = (string) $this->actingAs($actor)->withSession(A::passwordConfirmed())->get(route($route))->assertOk()->getContent();
    expect($html)->not->toContain(route('admin.kelas.index'));

    // Without a recent password the page sends the user to the password page and back to its own address.
    $this->actingAs($actor);
    session()->forget('auth.password_confirmed_at');
    Livewire::test(Kelas::class)->call('askDelete', $this->x2->getKey())->assertRedirect(route('password.confirm'));
    expect((string) session('url.intended'))->toStartWith(route($route))
        ->and((string) session('url.intended'))->not->toContain('/admin/');
})->with([
    'Tata Usaha' => ['tu', 'tu.kelas.index'],
    'Kesiswaan' => ['kesiswaan', 'kesiswaan.kelas.index'],
]);

// --- Every action as Tata Usaha and Kesiswaan --------------------------------------------------------------------------

it('lets Tata Usaha and Kesiswaan add, rename and delete a class, then restore it', function (string $who): void {
    $actor = $who === 'tu' ? $this->tu : $this->kesiswaan;
    $raka = kaSiswa($this->x2, 'Raka Pratama');

    $screen = kaScreen($actor)->set('level', '12')->set('suffix', '7')->call('add')
        ->assertDispatched('sth-toast', message: 'Kelas XII-7 ditambahkan.', tone: 'success');
    $xii7 = Classroom::query()->where('name', 'XII-7')->sole();

    $screen->call('askRename', $xii7->getKey())->set('renameSuffix', '8')->call('rename')
        ->assertDispatched('sth-toast', message: 'Nama kelas diganti menjadi XII-8.', tone: 'success');

    $screen->call('askDelete', $this->x2->getKey())->assertDispatched('sth-modal-open', id: Kelas::DELETE_DIALOG)
        ->set('classReason', 'Kelas digabung ke kelas X-1.')->call('delete');

    $entry = TrashEntry::query()->where('kind', TrashEntry::KIND_CLASSROOM)->sole();
    expect(Classroom::query()->whereKey($this->x2->getKey())->exists())->toBeFalse()
        ->and(Student::query()->whereKey($raka->getKey())->exists())->toBeFalse()
        ->and($entry->deleted_by)->toBe((int) $actor->getKey());

    $screen->call('askRestore', $entry->getKey())->assertDispatched('sth-modal-open', id: Kelas::RESTORE_DIALOG)->call('restore');

    expect(Classroom::query()->whereKey($this->x2->getKey())->exists())->toBeTrue()
        ->and(Student::query()->whereKey($raka->getKey())->exists())->toBeTrue();
})->with(['tu', 'kesiswaan']);

it('lets Tata Usaha and Kesiswaan open Lihat siswa with Ubah data, Ubah status and Hapus, and use the shared dialogs', function (string $who): void {
    $actor = $who === 'tu' ? $this->tu : $this->kesiswaan;
    $nadia = kaSiswa($this->x1, 'Nadia Putri Anindya');
    $raka = kaSiswa($this->x1, 'Raka Pratama');

    $screen = kaScreen($actor)->call('showStudents', $this->x1->getKey())
        ->assertSee('Nadia Putri Anindya')
        ->assertSeeHtml('wire:click="askData('.$nadia->user_id.')"')
        ->assertSeeHtml('wire:click="askStatus('.$nadia->user_id.')"')
        ->assertSeeHtml('wire:click="askTrashStudent('.$nadia->user_id.')"')
        ->assertSeeHtml('Ubah status terpilih (<span class="num">0</span>)');

    $screen->call('askData', $nadia->user_id)->assertDispatched(UbahDataSiswa::OPEN_EVENT, userId: (int) $nadia->user_id);
    $screen->call('askStatus', $nadia->user_id)->assertDispatchedTo(UbahStatusSiswa::class, UbahStatusSiswa::OPEN_EVENT, ids: [(int) $nadia->user_id]);
    $screen->call('askTrashStudent', $raka->user_id)->assertDispatchedTo(HapusSiswa::class, HapusSiswa::OPEN_EVENT, ids: [(int) $raka->user_id]);

    // Ubah status: Nonaktif, then the shared dialog changes the account.
    Livewire::test(UbahStatusSiswa::class, ['returnTo' => ClassroomArea::indexUrl($who)])
        ->call('open', [(int) $nadia->user_id])
        ->set('statusTarget', UbahStatusSiswa::TARGET_DISABLED)
        ->set('statusReason', 'Pindah sekolah ke kota lain.')
        ->call('save');
    expect($nadia->user->fresh()->status)->toBe(UserStatus::Disabled);

    // Hapus: the student goes to the Sampah, deleted by this account.
    Livewire::test(HapusSiswa::class, ['returnTo' => ClassroomArea::indexUrl($who)])
        ->call('open', [(int) $raka->user_id])
        ->set('reason', 'Data ganda dari import.')
        ->call('trash');
    expect(Student::query()->whereKey($raka->getKey())->exists())->toBeFalse()
        ->and(TrashEntry::query()->where('user_id', $raka->user_id)->sole()->deleted_by)->toBe((int) $actor->getKey());
})->with(['tu', 'kesiswaan']);

it('leaves a "Bukan saya" freeze to the Admin when Kesiswaan or Tata Usaha uses Ubah status', function (string $who): void {
    $actor = $who === 'tu' ? $this->tu : $this->kesiswaan;
    $dimas = kaSiswa($this->x1, 'Dimas Saputra', [], ['frozen_at' => now(), 'frozen_reason' => AccountControls::FROZEN_NOT_ME, 'initial_password_expires_at' => now()->addDays(5)]);
    kaScreen($actor);

    Livewire::test(UbahStatusSiswa::class, ['returnTo' => ClassroomArea::indexUrl($who)])
        ->call('open', [(int) $dimas->user_id])
        ->set('statusTarget', UbahStatusSiswa::TARGET_ACTIVE)
        ->set('statusReason', 'Siswa datang ke ruang guru.')
        ->call('save')
        ->assertDispatched('sth-toast', message: UbahStatusSiswa::resultMessage(0, 0, 1, UnfreezeAccount::MSG_NOT_ME_ADMIN), tone: 'warning');

    expect(AccountControls::frozenReason($dimas->user->fresh()))->toBe(AccountControls::FROZEN_NOT_ME);
})->with(['tu', 'kesiswaan']);

it('refuses the Kelas actions to a Tata Usaha account whose role lost the permissions (RoleSeeder not run)', function (): void {
    $nadia = kaSiswa($this->x1, 'Nadia Putri Anindya');
    $screen = kaScreen($this->tu)->call('showStudents', $this->x1->getKey());

    Role::findByName(RoleName::TataUsaha->value)->revokePermissionTo(['classroom.manage', 'student.status', 'student.trash']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($this->tu->fresh());

    $screen->call('askStatus', $nadia->user_id)->assertForbidden();
    Livewire::test(Kelas::class)->assertForbidden();
    expect(fn () => app(TrashStudents::class)->handle($this->tu->fresh(), [$nadia->user_id], 'Data ganda dari import.'))->toThrow(AuthorizationException::class);
});

// --- Who deleted -------------------------------------------------------------------------------------------------------

it('names who deleted in the Sampah: name and role', function (): void {
    $raka = kaSiswa($this->x1, 'Raka Pratama');
    $sinta = kaSiswa($this->x1, 'Sinta Ayu Lestari');
    app(TrashStudents::class)->handle($this->tu, [$raka->user_id], 'Data ganda dari import.');
    $this->travel(5)->minutes();
    app(TrashStudents::class)->handle($this->kesiswaan, [$sinta->user_id], 'Salah kelas waktu import.');
    $this->travel(5)->minutes();
    app(TrashClassroom::class)->handle($this->admin, $this->xi3, 'Kelas tidak dipakai tahun ini.');

    kaScreen($this->admin)
        ->assertSee('Dihapus 5 Nov 2026, 10.00 oleh Sri Handayani, S.E. (Tata Usaha)')
        ->assertSee('Dihapus 5 Nov 2026, 10.05 oleh Dra. Wulan Sari (Kesiswaan)')
        ->assertSee('Dihapus 5 Nov 2026, 10.10 oleh Admin Sekolah Contoh (Admin)')
        ->assertSee('Alasan: Data ganda dari import.');

    expect(Kelas::deleterText(null))->toBe('akun yang sudah tidak ada');
});

// --- Hapus permanen ----------------------------------------------------------------------------------------------------

it('asks before "Hapus permanen" of a student: cannot be undone, what goes with it', function (): void {
    $raka = kaSiswa($this->x1, 'Raka Pratama');
    $entry = app(TrashStudents::class)->handle($this->admin, [$raka->user_id], 'Data ganda dari import.')[0];

    kaScreen($this->kesiswaan)
        ->assertSeeHtml("wire:click=\"askPurge('".$entry->getKey()."')\"")
        ->call('askPurge', $entry->getKey())
        ->assertSet('purgingId', (string) $entry->getKey())
        ->assertDispatched('sth-modal-open', id: Kelas::PURGE_DIALOG)
        ->assertSee('Hapus permanen Raka Pratama?')
        ->assertSee('Siswa ini langsung terhapus permanen, tanpa menunggu 13 November 2026.')
        ->assertSee('Akun, pendaftaran, surat tugas, laporan hasil, prestasi, talenta, dan file siswanya ikut terhapus.')
        ->assertSee('Ini tidak bisa dibatalkan dan tidak bisa dipulihkan.')
        ->assertDontSeeHtml('id="f-kelas-hapus-permanen-nama"');
});

it('deletes a student entry for good after the question: gone, entry closed, audited, toast', function (string $who): void {
    $actor = $who === 'tu' ? $this->tu : $this->kesiswaan;
    $raka = kaSiswa($this->x1, 'Raka Pratama');
    $entry = app(TrashStudents::class)->handle($this->admin, [$raka->user_id], 'Data ganda dari import.')[0];

    kaScreen($actor)->call('askPurge', $entry->getKey())->call('purge')
        ->assertDispatched('sth-toast', message: 'Raka Pratama dihapus permanen.', tone: 'success')
        ->assertDispatched('sth-modal-close', id: Kelas::PURGE_DIALOG)
        ->assertSet('purgingId', null)
        ->assertDontSee('Raka Pratama');

    $log = Activity::query()->where('event', PurgeFromTrash::EVENT)->sole();

    expect(Student::withTrashed()->whereKey($raka->getKey())->exists())->toBeFalse()
        ->and(User::withTrashed()->whereKey($raka->user_id)->exists())->toBeFalse()
        ->and($entry->fresh()->purged_at)->not->toBeNull()
        ->and($log->causer_id)->toBe($actor->getKey())
        ->and(str_contains((string) json_encode($log->properties), 'Raka'))->toBeFalse();
})->with(['tu', 'kesiswaan']);

it('asks the class name before "Hapus permanen" of a class and keeps the dialog open on a wrong one', function (): void {
    $sinta = kaSiswa($this->x2, 'Sinta Ayu Lestari');
    $entry = app(TrashClassroom::class)->handle($this->admin, $this->x2, 'Kelas digabung ke kelas X-1.');

    $screen = kaScreen($this->tu)->call('askPurge', $entry->getKey())
        ->assertSee('Hapus permanen kelas X-2?')
        ->assertSee('Kelas ini dan 1 siswanya langsung terhapus permanen, tanpa menunggu 13 November 2026.')
        ->assertSeeHtml('id="f-kelas-hapus-permanen-nama"')
        ->call('purge')
        ->assertHasErrors([PurgeFromTrash::CONFIRM_KEY])
        ->set('purgeConfirmName', 'X-1')
        ->call('purge')
        ->assertHasErrors([PurgeFromTrash::CONFIRM_KEY => PurgeFromTrash::MSG_CONFIRM_NAME])
        ->assertSet('purgingId', (string) $entry->getKey());

    expect(Classroom::withTrashed()->whereKey($this->x2->getKey())->exists())->toBeTrue();

    $screen->set('purgeConfirmName', ' x-2 ')->call('purge')
        ->assertDispatched('sth-toast', message: 'Kelas X-2 dan 1 siswanya dihapus permanen.', tone: 'success');

    expect(Classroom::withTrashed()->whereKey($this->x2->getKey())->exists())->toBeFalse()
        ->and(Student::withTrashed()->whereKey($sinta->getKey())->exists())->toBeFalse();
});

it('does nothing on "Hapus permanen" without the question, and asks the password first', function (): void {
    $raka = kaSiswa($this->x1, 'Raka Pratama');
    $entry = app(TrashStudents::class)->handle($this->admin, [$raka->user_id], 'Data ganda dari import.')[0];

    // No question: a silent no-op (D-128).
    kaScreen($this->admin)->call('purge')->assertNotDispatched('sth-toast')->assertHasNoErrors();

    // No recent password: the password page, nothing deleted.
    $this->actingAs($this->admin);
    session()->forget('auth.password_confirmed_at');
    Livewire::test(Kelas::class)->call('askPurge', $entry->getKey())
        ->assertRedirect(route('password.confirm'))
        ->assertSet('purgingId', null);

    expect($entry->fresh()->isOpen())->toBeTrue()
        ->and(Student::withTrashed()->whereKey($raka->getKey())->exists())->toBeTrue()
        ->and(Activity::query()->where('event', PurgeFromTrash::EVENT)->exists())->toBeFalse();
});

it('says so when the entry was restored meanwhile, and refuses an unknown entry', function (): void {
    $raka = kaSiswa($this->x1, 'Raka Pratama');
    $entry = app(TrashStudents::class)->handle($this->admin, [$raka->user_id], 'Data ganda dari import.')[0];
    $screen = kaScreen($this->admin)->call('askPurge', $entry->getKey());

    app(RestoreFromTrash::class)->handle($this->admin, $entry);

    $screen->call('purge')->assertDispatched('sth-toast', message: Kelas::MSG_ENTRY_CLOSED, tone: 'warning');
    expect(Student::query()->whereKey($raka->getKey())->exists())->toBeTrue();

    kaScreen($this->admin)->call('askPurge', '01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
});

it('refuses "Hapus permanen" to roles without classroom.manage', function (RoleName $role): void {
    $raka = kaSiswa($this->x1, 'Raka Pratama');
    $entry = app(TrashStudents::class)->handle($this->admin, [$raka->user_id], 'Data ganda dari import.')[0];
    $screen = kaScreen($this->admin)->call('askPurge', $entry->getKey());

    $this->actingAs(A::staff($role));
    $screen->call('purge')->assertForbidden();

    expect(fn () => app(PurgeFromTrash::class)->handle(A::staff($role), $entry))->toThrow(AuthorizationException::class)
        ->and($entry->fresh()->isOpen())->toBeTrue();
})->with([RoleName::KepalaSekolah, RoleName::Humas, RoleName::Guru]);

// --- The two files and the one chooser ----------------------------------------------------------------------------------

it('offers exactly the same download chooser on the Kelas page and on Status aktivasi', function (): void {
    kaSiswa($this->x1, 'Nadia Putri Anindya');
    kaSiswa($this->xi3, 'Raka Pratama');

    $kelas = kaScreen($this->kesiswaan)->viewData('downloadOptions');
    $aktivasi = Livewire::test(StatusAktivasi::class)->viewData('downloadOptions');

    expect($kelas)->toBe($aktivasi)
        ->and($kelas)->toBe([
            '' => 'Semua kelas',
            '10' => 'Kelas X',
            '11' => 'Kelas XI',
            '12' => 'Kelas XII',
            ExportActivationReport::SCOPE_CLASS_GROUP => [(string) $this->x1->getKey() => 'X-1', (string) $this->xi3->getKey() => 'XI-3'],
        ]);
});

it('writes the Data siswa file: the student columns only, text cells, leading zeros, DD-MM-YYYY, masked email', function (): void {
    $nadia = kaSiswa($this->x1, 'Nadia Putri Anindya',
        ['nis' => '0022622', 'nisn' => '0012345670', 'birth_date' => '2009-02-01', 'phone' => '081234567890'],
        ['email' => 'nadia.putri@example.test']);
    kaSiswa($this->x1, '=Raka Pratama');
    kaSiswa($this->xi3, 'Sinta Ayu Lestari');

    $file = app(ExportActivationReport::class)->handle($this->tu, source: ExportActivationReport::SOURCE_CLASSES, kind: ActivationReport::KIND_DATA);
    $rows = SimpleExcelReader::create($file->path)->fromSheetName('X')->getRows()->all();
    $first = collect($rows)->firstWhere('Nama', 'Nadia Putri Anindya');

    expect($file->filename)->toStartWith('data-siswa-semua-')
        ->and(kaSheets($file->path))->toBe(['X', 'XI'])
        ->and(SimpleExcelReader::create($file->path)->fromSheetName('X')->getHeaders())->toBe(['No', 'Kelas', 'Nama', 'NIS', 'NISN', 'Tanggal lahir', 'No HP', 'Email'])
        ->and(array_column($rows, 'No'))->toBe(['1', '2'])
        ->and(array_column($rows, 'Nama'))->toBe(["'=Raka Pratama", 'Nadia Putri Anindya'])
        ->and($first['NIS'])->toBe('0022622')
        ->and($first['NISN'])->toBe('0012345670')
        ->and($first['No HP'])->toBe('081234567890')
        ->and($first['Tanggal lahir'])->toBe('01-02-2009')
        ->and($first['Email'])->toBe(AccountControls::maskEmail('nadia.putri@example.test'))
        ->and($first['Email'])->not->toContain('nadia.putri@')
        ->and(Activity::query()->where('event', ExportActivationReport::EVENT)->sole()->properties['file'])->toBe(ActivationReport::KIND_DATA);

    @unlink($file->path);
});

it('writes the Status aktivasi file: the same columns, then the status columns, and Ringkasan last', function (): void {
    kaSiswa($this->x1, 'Nadia Putri Anindya', ['nisn' => '0012345670', 'birth_date' => '2009-02-01', 'phone' => '081234567890']);
    kaSiswa($this->xi3, 'Raka Pratama', [], ['status' => UserStatus::Disabled]);

    $file = app(ExportActivationReport::class)->handle($this->kesiswaan, kind: ActivationReport::KIND_STATUS);
    $x = SimpleExcelReader::create($file->path)->fromSheetName('X')->getRows()->first();

    expect($file->filename)->toStartWith('status-aktivasi-semua-')
        ->and(kaSheets($file->path))->toBe(['X', 'XI', ActivationReport::SUMMARY_SHEET])
        ->and(SimpleExcelReader::create($file->path)->fromSheetName('XI')->getHeaders())->toBe([
            'No', 'Kelas', 'Nama', 'NIS', 'NISN', 'Tanggal lahir', 'No HP', 'Email',
            'Status', 'Aktivasi', 'Login terakhir', 'Batas kata sandi awal', 'Catatan',
        ])
        ->and($x['NISN'])->toBe('0012345670')
        ->and($x['Tanggal lahir'])->toBe('01-02-2009')
        ->and($x['No HP'])->toBe('081234567890')
        ->and(SimpleExcelReader::create($file->path)->fromSheetName('XI')->getRows()->first()['Status'])->toBe('Nonaktif');

    @unlink($file->path);
});

it('writes one class as one sheet named after it and one tingkat as one sheet, in both kinds', function (string $kind): void {
    kaSiswa($this->x1, 'Nadia Putri Anindya');
    kaSiswa($this->x2, 'Raka Pratama');
    kaSiswa($this->xi3, 'Sinta Ayu Lestari');

    $class = app(ExportActivationReport::class)->handle($this->tu, (string) $this->x2->getKey(), kind: $kind);
    $level = app(ExportActivationReport::class)->handle($this->tu, level: 10, kind: $kind);

    expect(kaSheets($class->path))->toBe(['X-2'])
        ->and(array_column(SimpleExcelReader::create($class->path)->fromSheetName('X-2')->getRows()->all(), 'Nama'))->toBe(['Raka Pratama'])
        ->and(kaSheets($level->path))->toBe(['X'])
        ->and(array_column(SimpleExcelReader::create($level->path)->fromSheetName('X')->getRows()->all(), 'Kelas'))->toBe(['X-1', 'X-2']);
})->with([ActivationReport::KIND_DATA, ActivationReport::KIND_STATUS]);

it('writes no file for an empty choice, in both kinds', function (string $kind): void {
    expect(app(ExportActivationReport::class)->handle($this->tu, level: 12, kind: $kind))->toBeNull()
        ->and(app(ExportActivationReport::class)->handle($this->tu, (string) $this->x1->getKey(), kind: $kind))->toBeNull()
        ->and(Activity::query()->where('event', ExportActivationReport::EVENT)->exists())->toBeFalse();
})->with([ActivationReport::KIND_DATA, ActivationReport::KIND_STATUS]);

it('downloads the Data siswa file from the TU Kelas page with the chooser, also resumed after the password page', function (): void {
    kaSiswa($this->x1, 'Nadia Putri Anindya');
    kaSiswa($this->xi3, 'Raka Pratama');
    $this->actingAs($this->tu);

    Livewire::test(Kelas::class)->set('unduh', '11')->call('export')->assertRedirect(route('password.confirm'));
    expect((string) session('url.intended'))->toBe(route('tu.kelas.index').'?unduh=11');

    session(A::passwordConfirmed());
    $screen = Livewire::withQueryParams(['unduh' => '11'])->test(Kelas::class)
        ->assertSet('unduh', '11')
        ->assertSee('Isi file: Kelas XI;')
        ->assertSeeHtml('wire:init="export"')
        ->call('export');
    $file = kaDownload($screen);

    expect($screen->effects['download']['name'] ?? '')->toStartWith('data-siswa-xi-')
        ->and(kaSheets($file))->toBe(['XI'])
        ->and(SimpleExcelReader::create($file)->fromSheetName('XI')->getHeaders())->toBe(ActivationReport::DATA_HEADER);

    // One class from the same chooser; a forged value answers 404; a class that lost its students a toast.
    $class = kaDownload(Livewire::test(Kelas::class)->set('unduh', (string) $this->x1->getKey())->call('export'));
    expect(kaSheets($class))->toBe(['X-1']);

    Livewire::test(Kelas::class)->set('unduh', '9')->call('export')->assertNotFound();
    Livewire::test(Kelas::class)->set('unduh', (string) $this->x2->getKey())->call('export')
        ->assertDispatched('sth-toast', message: ExportActivationReport::MSG_EMPTY, tone: 'warning')
        ->assertNoFileDownloaded();
});

it('downloads the Status aktivasi file from every download of Status aktivasi', function (): void {
    kaSiswa($this->x1, 'Nadia Putri Anindya');
    $this->actingAs($this->kesiswaan);
    session(A::passwordConfirmed());

    $list = kaDownload(Livewire::test(StatusAktivasi::class)->set('unduhTingkat', (string) $this->x1->getKey())->call('export'));
    $dialog = kaDownload(Livewire::test(StatusAktivasi::class)->call('exportClass', $this->x1->getKey()));

    foreach ([$list, $dialog] as $file) {
        expect(kaSheets($file))->toBe(['X-1'])
            ->and(SimpleExcelReader::create($file)->fromSheetName('X-1')->getHeaders())->toBe(ActivationReport::HEADER);
    }

    expect(Activity::query()->where('event', ExportActivationReport::EVENT)->pluck('properties')->map(fn ($p) => $p['file'])->unique()->values()->all())
        ->toBe([ActivationReport::KIND_STATUS]);
});
