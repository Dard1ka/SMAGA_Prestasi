<?php

declare(strict_types=1);

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\DisableAccount;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\UbahStatusSiswa;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 * SV5 (the lead, 2026-11-04): the shared dialog "Ubah status" of Admin › Kelas and Status aktivasi. Every K9 state can
 * be brought back ("Aktif kembali"), disabled or archived; one reason, a new archive date when needed, a password
 * confirmation, one Action per student. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    $this->withoutVite();
    $this->admin = A::admin();
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());
    $this->classroom = Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
});

/**
 * A student in one K9 state: aktif, belum, kedaluwarsa, dibekukan, nonaktif (archive date ahead), nonaktif-lewat (archive
 * date passed), arsip (activated), arsip-belum (never activated, window long over).
 */
function ubahStatusSiswa(string $state, string $name = 'Raka Pratama'): User
{
    $activated = ['must_change_password' => false, 'email' => 'siswa.'.fake()->unique()->numerify('####').'@example.test', 'email_verified_at' => now()];
    $pending = ['must_change_password' => true, 'email' => null, 'email_verified_at' => null];
    $ahead = CarbonImmutable::now('Asia/Jakarta')->addYear()->toDateString();

    [$user, $student] = match ($state) {
        'aktif' => [['status' => UserStatus::Active] + $activated, []],
        'belum' => [['status' => UserStatus::PendingActivation, 'initial_password_expires_at' => now()->addDays(5)] + $pending, []],
        'kedaluwarsa' => [['status' => UserStatus::PendingActivation, 'initial_password_expires_at' => now()->subDay()] + $pending, []],
        'dibekukan' => [['status' => UserStatus::PendingActivation, 'initial_password_expires_at' => now()->addDays(5), 'frozen_at' => now(), 'frozen_reason' => AccountControls::FROZEN_FAILED_ATTEMPTS, 'failed_activation_attempts' => 10] + $pending, []],
        'nonaktif' => [['status' => UserStatus::Disabled] + $activated, ['archive_at' => $ahead]],
        'nonaktif-lewat' => [['status' => UserStatus::Disabled] + $activated, ['archive_at' => CarbonImmutable::now('Asia/Jakarta')->subDay()->toDateString()]],
        'arsip' => [['status' => UserStatus::Archived] + $activated, ['archive_at' => CarbonImmutable::now('Asia/Jakarta')->subMonth()->toDateString()]],
        'arsip-belum' => [['status' => UserStatus::Archived, 'initial_password_expires_at' => now()->subYear()] + $pending, ['archive_at' => CarbonImmutable::now('Asia/Jakarta')->subMonth()->toDateString()]],
    };

    $row = Student::factory()->create(array_merge(['classroom_id' => test()->classroom->getKey()], $student));
    $row->user->forceFill(array_merge(['name' => $name], $user))->saveQuietly();

    return $row->user->fresh();
}

function ubahStatusTomorrow(): string
{
    return CarbonImmutable::now('Asia/Jakarta')->addDay()->toDateString();
}

// --- Asking -------------------------------------------------------------------------------------------------------

it('opens for the given students with the targets that apply and a preselection', function (): void {
    $arsip = ubahStatusSiswa('arsip', 'Bima Alumni');
    $aktif = ubahStatusSiswa('aktif', 'Raka Pratama');

    $screen = Livewire::test(UbahStatusSiswa::class)
        ->call('open', [$arsip->getKey()])
        ->assertSet('userIds', [(int) $arsip->getKey()])
        ->assertSet('statusTarget', UbahStatusSiswa::TARGET_ACTIVE)
        ->assertDispatched('sth-modal-open', id: UbahStatusSiswa::DIALOG)
        ->assertSee('Bima Alumni')
        ->assertSee('Kelas X-1')
        ->assertSee('Aktifkan lagi');

    expect(array_keys($screen->viewData('dialog')['targets']))->toBe([UbahStatusSiswa::TARGET_ACTIVE, UbahStatusSiswa::TARGET_DISABLED]);

    // An active student: "Aktif kembali" does not apply; two targets, none preselected.
    $screen->call('open', [$aktif->getKey()])->assertSet('statusTarget', '');
    expect(array_keys($screen->viewData('dialog')['targets']))->toBe([UbahStatusSiswa::TARGET_DISABLED, UbahStatusSiswa::TARGET_ARCHIVED]);

    // A preselection the host passes wins when it applies, and is dropped when it does not.
    $screen->call('open', [$aktif->getKey()], UbahStatusSiswa::TARGET_ARCHIVED)->assertSet('statusTarget', UbahStatusSiswa::TARGET_ARCHIVED)
        ->call('open', [$aktif->getKey()], UbahStatusSiswa::TARGET_ACTIVE)->assertSet('statusTarget', '');

    // Several: "N siswa dipilih" and the names.
    $screen->call('open', [$arsip->getKey(), (string) $aktif->getKey()])
        ->assertSet('userIds', [(int) $arsip->getKey(), (int) $aktif->getKey()])
        ->assertSee('2 siswa dipilih')
        ->assertSee('Bima Alumni, Raka Pratama');
});

it('refuses a staff, unknown, malformed, empty or too large selection with 404', function (array $ids): void {
    Livewire::test(UbahStatusSiswa::class)->call('open', $ids)->assertNotFound();
})->with([
    'staff' => fn () => [ubahStatusSiswa('aktif')->getKey(), A::staff(RoleName::Guru)->getKey()],
    'the Admin' => fn () => [test()->admin->getKey()],
    'unknown' => [[999999]],
    'malformed' => [['12; drop']],
    'empty' => [[]],
    'too many' => fn () => [range(1, UbahStatusSiswa::MAX_STUDENTS + 1)],
]);

it('is Admin only: the component and every action refuse other roles with 403', function (): void {
    $student = ubahStatusSiswa('nonaktif');
    $ask = fn () => Livewire::test(UbahStatusSiswa::class)
        ->call('open', [$student->getKey()])
        ->set('statusReason', 'Siswa kembali bersekolah.');
    [$first, $second] = [$ask(), $ask()];

    $this->actingAs(A::staff(RoleName::Kesiswaan));
    Livewire::test(UbahStatusSiswa::class)->assertForbidden();
    $first->call('save')->assertForbidden();
    $second->call('open', [$student->getKey()])->assertForbidden();

    expect($student->fresh()->status)->toBe(UserStatus::Disabled);
});

// --- Every target for every state ---------------------------------------------------------------------------------

it('changes or skips each state for each target', function (string $state, string $target, ?string $result): void {
    $student = ubahStatusSiswa($state);

    $screen = Livewire::test(UbahStatusSiswa::class)->call('open', [$student->getKey()]);

    if ($result === null) {
        expect($screen->viewData('dialog')['targets'])->not->toHaveKey($target);

        return;
    }

    $screen->set('statusTarget', $target)
        ->set('statusReason', 'Perubahan status oleh Admin.')
        ->set('statusArchiveDate', ubahStatusTomorrow())
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('userIds', [])
        ->assertDispatched('sth-modal-close', id: UbahStatusSiswa::DIALOG)
        ->assertDispatched(UbahStatusSiswa::DONE_EVENT)
        ->assertDispatched('sth-toast', message: '1 akun diubah.', tone: 'success');

    expect(AccountControls::state($student->fresh()))->toBe($result);
})->with([
    // Aktif kembali
    'aktif → aktif kembali' => ['aktif', UbahStatusSiswa::TARGET_ACTIVE, null],
    'belum → aktif kembali' => ['belum', UbahStatusSiswa::TARGET_ACTIVE, null],
    'kedaluwarsa → aktif kembali' => ['kedaluwarsa', UbahStatusSiswa::TARGET_ACTIVE, AccountControls::STATE_PENDING],
    'dibekukan → aktif kembali' => ['dibekukan', UbahStatusSiswa::TARGET_ACTIVE, AccountControls::STATE_PENDING],
    'nonaktif → aktif kembali' => ['nonaktif', UbahStatusSiswa::TARGET_ACTIVE, AccountControls::STATE_ACTIVE],
    'nonaktif, date passed → aktif kembali' => ['nonaktif-lewat', UbahStatusSiswa::TARGET_ACTIVE, AccountControls::STATE_ACTIVE],
    'arsip → aktif kembali' => ['arsip', UbahStatusSiswa::TARGET_ACTIVE, AccountControls::STATE_ACTIVE],
    'arsip, never activated → aktif kembali' => ['arsip-belum', UbahStatusSiswa::TARGET_ACTIVE, AccountControls::STATE_PENDING],
    // Nonaktif
    'aktif → nonaktif' => ['aktif', UbahStatusSiswa::TARGET_DISABLED, AccountControls::STATE_DISABLED],
    'belum → nonaktif' => ['belum', UbahStatusSiswa::TARGET_DISABLED, AccountControls::STATE_DISABLED],
    'kedaluwarsa → nonaktif' => ['kedaluwarsa', UbahStatusSiswa::TARGET_DISABLED, AccountControls::STATE_DISABLED],
    'dibekukan → nonaktif' => ['dibekukan', UbahStatusSiswa::TARGET_DISABLED, AccountControls::STATE_DISABLED],
    'nonaktif → nonaktif' => ['nonaktif', UbahStatusSiswa::TARGET_DISABLED, null],
    'arsip → nonaktif' => ['arsip', UbahStatusSiswa::TARGET_DISABLED, AccountControls::STATE_DISABLED],
    // Arsip (alumni)
    'aktif → arsip' => ['aktif', UbahStatusSiswa::TARGET_ARCHIVED, AccountControls::STATE_ARCHIVED],
    'belum → arsip' => ['belum', UbahStatusSiswa::TARGET_ARCHIVED, AccountControls::STATE_ARCHIVED],
    'kedaluwarsa → arsip' => ['kedaluwarsa', UbahStatusSiswa::TARGET_ARCHIVED, AccountControls::STATE_ARCHIVED],
    'dibekukan → arsip' => ['dibekukan', UbahStatusSiswa::TARGET_ARCHIVED, AccountControls::STATE_ARCHIVED],
    'nonaktif → arsip' => ['nonaktif', UbahStatusSiswa::TARGET_ARCHIVED, AccountControls::STATE_ARCHIVED],
    'arsip → arsip' => ['arsip', UbahStatusSiswa::TARGET_ARCHIVED, null],
]);

it('runs the Action of each state and audits it', function (): void {
    $students = collect(['arsip', 'nonaktif', 'dibekukan', 'kedaluwarsa'])->mapWithKeys(fn (string $state, int $i) => [$state => ubahStatusSiswa($state, 'Siswa '.($i + 1))]);
    $date = ubahStatusTomorrow();

    Livewire::test(UbahStatusSiswa::class)
        ->call('open', $students->map->getKey()->values()->all())
        ->assertSet('statusTarget', UbahStatusSiswa::TARGET_ACTIVE)
        ->assertSee('1 akun diarsipkan dipulihkan dengan tanggal arsip otomatis baru.')
        ->assertSee('1 akun nonaktif diaktifkan lagi.')
        ->assertSee('1 akun dibekukan dibuka pembekuannya.')
        ->assertSee('1 akun kedaluwarsa mendapat 72 jam lagi untuk aktivasi.')
        ->set('statusReason', 'Siswa kembali bersekolah.')
        ->set('statusArchiveDate', $date)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: '4 akun diubah.', tone: 'success');

    $event = fn (string $name, User $user): ?Activity => Activity::query()->where('event', $name)->where('subject_id', $user->getKey())->first();

    expect($event('account.unarchived', $students['arsip'])?->properties['dates']['to']['archive_at'])->toBe($date)
        ->and($event('account.enabled', $students['nonaktif'])?->properties['reason'])->toBe('Siswa kembali bersekolah.')
        ->and($event('account.unfrozen', $students['dibekukan']))->not->toBeNull()
        ->and($event('account.activation_reopened', $students['kedaluwarsa']))->not->toBeNull()
        // A date the nonaktif student did not need is not written.
        ->and($students['nonaktif']->student->fresh()->archive_at->toDateString())->toBe($students['nonaktif']->student->archive_at->toDateString())
        ->and($students->every(fn (User $user): bool => $user->fresh()->canLogIn()))->toBeTrue();
});

it('brings an archived account that never activated back with a fresh activation window', function (): void {
    $student = ubahStatusSiswa('arsip-belum');

    Livewire::test(UbahStatusSiswa::class)
        ->call('open', [$student->getKey()])
        ->set('statusReason', 'Siswa kembali bersekolah.')
        ->set('statusArchiveDate', ubahStatusTomorrow())
        ->call('save')
        ->assertDispatched('sth-toast', message: '1 akun diubah.', tone: 'success');

    $fresh = $student->fresh();

    expect($fresh->status)->toBe(UserStatus::PendingActivation)
        ->and(AccountControls::initialPasswordExpired($fresh))->toBeFalse()
        ->and(Activity::query()->where('event', 'account.unarchived')->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'account.activation_reopened')->exists())->toBeTrue();
});

it('disables and archives with sessions ended', function (string $target, string $event): void {
    $student = ubahStatusSiswa('aktif');
    DB::table('sessions')->insert(['id' => 'sesi-ubah-status', 'user_id' => $student->getKey(), 'ip_address' => '127.0.0.1', 'user_agent' => 'Pest', 'payload' => '', 'last_activity' => time()]);

    Livewire::test(UbahStatusSiswa::class)
        ->call('open', [$student->getKey()])
        ->set('statusTarget', $target)
        ->assertSee($target === UbahStatusSiswa::TARGET_DISABLED ? 'Nonaktifkan' : 'Arsipkan')
        ->set('statusReason', 'Pindah sekolah ke kota lain.')
        ->call('save')
        ->assertHasNoErrors();

    expect(Activity::query()->where('event', $event)->sole()->properties['reason'])->toBe('Pindah sekolah ke kota lain.')
        ->and(DB::table('sessions')->where('user_id', $student->getKey())->count())->toBe(0);
})->with([
    'nonaktif' => [UbahStatusSiswa::TARGET_DISABLED, 'account.disabled'],
    'arsip' => [UbahStatusSiswa::TARGET_ARCHIVED, 'account.archived'],
]);

// --- Mixed selections, skips, the date field ----------------------------------------------------------------------

it('counts what changes and what is skipped in a mixed selection', function (): void {
    $aktif = ubahStatusSiswa('aktif', 'Anisa');
    $belum = ubahStatusSiswa('belum', 'Bayu');
    $nonaktif = ubahStatusSiswa('nonaktif', 'Citra');

    $screen = Livewire::test(UbahStatusSiswa::class)
        ->call('open', [$aktif->getKey(), $belum->getKey(), $nonaktif->getKey()])
        ->assertSet('statusTarget', '')
        ->set('statusTarget', UbahStatusSiswa::TARGET_DISABLED)
        ->assertSee('2 akun akan diubah. 1 dilewati karena sudah nonaktif.')
        ->set('statusTarget', UbahStatusSiswa::TARGET_ACTIVE)
        ->assertSee('1 akun akan diubah. 2 dilewati karena sudah aktif atau masih dalam masa aktivasi.');

    expect(array_keys($screen->viewData('dialog')['targets']))->toBe(array_keys(UbahStatusSiswa::TARGETS));

    $screen->set('statusTarget', UbahStatusSiswa::TARGET_DISABLED)
        ->set('statusReason', 'Siswa pindah sekolah.')
        ->call('save')
        ->assertDispatched('sth-toast', message: '2 akun diubah. 1 dilewati.', tone: 'success');

    expect($aktif->fresh()->status)->toBe(UserStatus::Disabled)
        ->and($belum->fresh()->status)->toBe(UserStatus::Disabled)
        ->and(Activity::query()->where('event', 'account.disabled')->count())->toBe(2);
});

it('asks the archive date only when the chosen target needs it, and checks it', function (): void {
    $ahead = ubahStatusSiswa('nonaktif', 'Anisa');
    $passed = ubahStatusSiswa('nonaktif-lewat', 'Bayu');

    $screen = Livewire::test(UbahStatusSiswa::class)
        ->call('open', [$ahead->getKey()])
        ->assertDontSeeHtml('id="f-ubah-status-tanggal"');
    expect($screen->viewData('dialog')['chosen']['needsDate'])->toBe(0);

    $screen->call('open', [$ahead->getKey(), $passed->getKey()])
        ->assertSeeHtml('id="f-ubah-status-tanggal"')
        ->assertSee('Untuk 1 siswa yang diarsipkan atau tanggal arsipnya sudah lewat.')
        ->set('statusTarget', UbahStatusSiswa::TARGET_ARCHIVED)
        ->assertDontSeeHtml('id="f-ubah-status-tanggal"')
        ->set('statusTarget', UbahStatusSiswa::TARGET_ACTIVE)
        ->set('statusReason', 'Siswa kembali bersekolah.')
        ->call('save')
        ->assertHasErrors(['statusArchiveDate'])
        ->assertSee('Isi tanggal arsip otomatis baru.')
        ->set('statusArchiveDate', CarbonImmutable::now('Asia/Jakarta')->toDateString())
        ->call('save')
        ->assertSee('Pilih tanggal setelah hari ini.')
        ->set('statusArchiveDate', 'bukan tanggal')
        ->call('save')
        ->assertSee('Tanggal tidak valid.')
        ->assertNotDispatched('sth-toast');

    expect($passed->fresh()->status)->toBe(UserStatus::Disabled)
        ->and($ahead->fresh()->status)->toBe(UserStatus::Disabled);

    $date = ubahStatusTomorrow();
    $screen->set('statusArchiveDate', $date)->call('save')->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: '2 akun diubah.', tone: 'success');

    expect($passed->student->fresh()->archive_at->toDateString())->toBe($date)
        ->and($ahead->student->fresh()->archive_at->toDateString())->not->toBe($date);
});

it('needs a reason of 10 to 500 characters and a target, all named at once', function (): void {
    $student = ubahStatusSiswa('aktif');

    Livewire::test(UbahStatusSiswa::class)
        ->call('open', [$student->getKey()])
        ->call('save')
        ->assertHasErrors(['statusTarget', 'statusReason'])
        ->assertSee('Pilih status baru.')
        ->assertSee('Isi alasannya.')
        ->set('statusTarget', UbahStatusSiswa::TARGET_DISABLED)
        ->set('statusReason', '  Pindah.  ')
        ->call('save')
        ->assertSee('Alasan minimal 10 karakter.')
        ->set('statusReason', str_repeat('a', 501))
        ->call('save')
        ->assertSee('Alasan maksimal 500 karakter.')
        ->set('statusTarget', 'aktif-semua')
        ->set('statusReason', 'Pindah sekolah ke kota lain.')
        ->call('save')
        ->assertHasErrors(['statusTarget'])
        ->assertNotDispatched('sth-toast');

    expect($student->fresh()->status)->toBe(UserStatus::Active)
        ->and(Activity::query()->whereIn('event', ['account.disabled', 'account.archived'])->exists())->toBeFalse();
});

// --- Password, the question, stale screens --------------------------------------------------------------------------

it('sends the Admin to the password confirmation first and changes nothing', function (): void {
    session()->forget('auth.password_confirmed_at');
    $student = ubahStatusSiswa('nonaktif');

    Livewire::test(UbahStatusSiswa::class, ['returnTo' => route('admin.kelas.index')])
        ->call('open', [$student->getKey()])
        ->set('statusReason', 'Siswa kembali bersekolah.')
        ->call('save')
        ->assertRedirect(route('password.confirm'));

    expect($student->fresh()->status)->toBe(UserStatus::Disabled)
        ->and(session('url.intended'))->toBe(route('admin.kelas.index'));
});

it('does nothing on "Simpan" without the question, and forgets the question when the dialog closes', function (): void {
    $student = ubahStatusSiswa('aktif');

    Livewire::test(UbahStatusSiswa::class)
        ->set('statusTarget', UbahStatusSiswa::TARGET_DISABLED)
        ->set('statusReason', 'Pindah sekolah ke kota lain.')
        ->call('save')
        ->assertOk()
        ->assertHasNoErrors()
        ->assertNotDispatched('sth-toast')
        ->assertNotDispatched(UbahStatusSiswa::DONE_EVENT)
        ->call('open', [$student->getKey()])
        ->call('forget')
        ->assertSet('userIds', [])
        ->set('statusTarget', UbahStatusSiswa::TARGET_DISABLED)
        ->set('statusReason', 'Pindah sekolah ke kota lain.')
        ->call('save')
        ->assertNotDispatched('sth-toast');

    expect($student->fresh()->status)->toBe(UserStatus::Active);
});

it('re-reads every student on "Simpan": one changed elsewhere is skipped, never an error page', function (): void {
    $raka = ubahStatusSiswa('aktif', 'Raka Pratama');
    $nadia = ubahStatusSiswa('aktif', 'Nadia Putri Anindya');

    $screen = Livewire::test(UbahStatusSiswa::class)
        ->call('open', [$raka->getKey(), $nadia->getKey()])
        ->set('statusTarget', UbahStatusSiswa::TARGET_DISABLED)
        ->set('statusReason', 'Pindah sekolah ke kota lain.');

    // Another tab disabled Raka meanwhile.
    app(DisableAccount::class)->handle($this->admin, $raka, 'Tab lain.');

    $screen->call('save')
        ->assertOk()
        ->assertDispatched('sth-toast', message: '1 akun diubah. 1 dilewati.', tone: 'success');

    expect(Activity::query()->where('event', 'account.disabled')->count())->toBe(2)
        ->and($nadia->fresh()->status)->toBe(UserStatus::Disabled);

    // Everybody changed meanwhile: no password page, one warning toast, the host redraws.
    $citra = ubahStatusSiswa('aktif', 'Citra');
    $screen->call('open', [$citra->getKey()])
        ->set('statusTarget', UbahStatusSiswa::TARGET_DISABLED)
        ->set('statusReason', 'Pindah sekolah ke kota lain.');
    app(DisableAccount::class)->handle($this->admin, $citra, 'Tab lain.');
    session()->forget('auth.password_confirmed_at');

    $screen->call('save')
        ->assertNoRedirect()
        ->assertDispatched('sth-toast', message: 'Tidak ada akun yang diubah. 1 dilewati karena statusnya sudah berubah. Daftar sudah diperbarui.', tone: 'warning')
        ->assertDispatched(UbahStatusSiswa::DONE_EVENT)
        ->assertSet('userIds', []);

    // A student deleted meanwhile drops out of the dialog.
    $dimas = ubahStatusSiswa('aktif', 'Dimas');
    $screen->call('open', [$dimas->getKey()]);
    $dimas->delete();
    $screen->call('$refresh')->assertOk()->assertViewHas('dialog', null)
        ->set('statusTarget', UbahStatusSiswa::TARGET_DISABLED)
        ->set('statusReason', 'Pindah sekolah ke kota lain.')
        ->call('save')
        ->assertDispatched('sth-toast', message: 'Tidak ada akun yang diubah. 1 dilewati karena statusnya sudah berubah. Daftar sudah diperbarui.', tone: 'warning');
});

it('words the one toast after "Simpan"', function (): void {
    expect(UbahStatusSiswa::resultMessage(3, 0, 0, null))->toBe('3 akun diubah.')
        ->and(UbahStatusSiswa::resultMessage(2, 1, 0, null))->toBe('2 akun diubah. 1 dilewati.')
        ->and(UbahStatusSiswa::resultMessage(1, 1, 2, 'Tanggal arsip otomatis siswa ini sudah lewat. Isi tanggal arsip baru.'))
        ->toBe('1 akun diubah. 2 gagal: Tanggal arsip otomatis siswa ini sudah lewat. Isi tanggal arsip baru. 1 dilewati.')
        ->and(UbahStatusSiswa::resultMessage(0, 2, 0, null))->toBe('Tidak ada akun yang diubah. 2 dilewati karena statusnya sudah berubah.');
});
