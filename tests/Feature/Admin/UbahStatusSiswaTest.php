<?php

declare(strict_types=1);

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\ActivateStudentAccount;
use App\Actions\Accounts\DisableAccount;
use App\Actions\Accounts\ExpireActivationWindow;
use App\Actions\Accounts\FreezeAccount;
use App\Actions\Accounts\ResetStudentAccount;
use App\Actions\Fortify\AuthenticateUser;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\UbahStatusSiswa;
use App\Livewire\Auth\Activation;
use App\Mail\OtpCodeMail;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 * SV5 (the lead, 2026-11-04) and the lead's choice of 2026-11-05: the shared dialog "Ubah status" of Admin › Kelas and
 * Status aktivasi offers every state of Status aktivasi (Belum aktivasi, Aktif, Kedaluwarsa, Dibekukan, Nonaktif,
 * Diarsipkan); one reason, a new archive date and a temporary password when needed, a password confirmation, one chain
 * of Actions per student in its own transaction. Fictional data only.
 */

uses(RefreshDatabase::class);

const UBAH_STATUS_SANDI = 'Sementara-2026';

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();
    $this->withoutVite();
    $this->admin = A::admin();
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());
    $this->classroom = Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
});

/**
 * A student in one K9 state: aktif, belum, kedaluwarsa, dibekukan (pending, K3), dibekukan-aktif (active, "Bukan saya?"),
 * nonaktif (archive date ahead), nonaktif-lewat (archive date passed), arsip (activated), arsip-belum (never activated,
 * window long over). The password is "password"; the NISN of the first student 0098765432.
 */
function ubahStatusSiswa(string $state, string $name = 'Raka Pratama'): User
{
    $activated = ['must_change_password' => false, 'email' => 'siswa.'.fake()->unique()->numerify('####').'@example.test', 'email_verified_at' => now(), 'password_changed_at' => now()];
    $pending = ['must_change_password' => true, 'email' => null, 'email_verified_at' => null];
    $ahead = CarbonImmutable::now('Asia/Jakarta')->addYear()->toDateString();

    [$user, $student] = match ($state) {
        'aktif' => [['status' => UserStatus::Active] + $activated, []],
        'belum' => [['status' => UserStatus::PendingActivation, 'initial_password_expires_at' => now()->addDays(5)] + $pending, []],
        'kedaluwarsa' => [['status' => UserStatus::PendingActivation, 'initial_password_expires_at' => now()->subDay()] + $pending, []],
        'dibekukan' => [['status' => UserStatus::PendingActivation, 'initial_password_expires_at' => now()->addDays(5), 'frozen_at' => now(), 'frozen_reason' => AccountControls::FROZEN_FAILED_ATTEMPTS, 'failed_activation_attempts' => 10] + $pending, []],
        'dibekukan-aktif' => [['status' => UserStatus::Active, 'frozen_at' => now(), 'frozen_reason' => AccountControls::FROZEN_NOT_ME] + $activated, []],
        'nonaktif' => [['status' => UserStatus::Disabled] + $activated, ['archive_at' => $ahead]],
        'nonaktif-lewat' => [['status' => UserStatus::Disabled] + $activated, ['archive_at' => CarbonImmutable::now('Asia/Jakarta')->subDay()->toDateString()]],
        'arsip' => [['status' => UserStatus::Archived] + $activated, ['archive_at' => CarbonImmutable::now('Asia/Jakarta')->subMonth()->toDateString()]],
        'arsip-belum' => [['status' => UserStatus::Archived, 'initial_password_expires_at' => now()->subYear()] + $pending, ['archive_at' => CarbonImmutable::now('Asia/Jakarta')->subMonth()->toDateString()]],
    };

    $nisn = Student::query()->where('nisn', '0098765432')->exists() ? fake()->unique()->numerify('00########') : '0098765432';
    $row = Student::factory()->create(array_merge(['classroom_id' => test()->classroom->getKey(), 'nisn' => $nisn], $student));
    $row->user->forceFill(array_merge(['name' => $name], $user))->saveQuietly();

    return $row->user->fresh();
}

function ubahStatusTomorrow(): string
{
    return CarbonImmutable::now('Asia/Jakarta')->addDay()->toDateString();
}

// --- Asking -------------------------------------------------------------------------------------------------------

it('offers the six states of Status aktivasi in their order, labelled as there', function (): void {
    expect(UbahStatusSiswa::TARGETS)->toBe([
        'belum' => 'Belum aktivasi',
        'aktif' => 'Aktif',
        'kedaluwarsa' => 'Kedaluwarsa',
        'dibekukan' => 'Dibekukan',
        'nonaktif' => 'Nonaktif',
        'arsip' => 'Diarsipkan',
    ]);

    foreach (UbahStatusSiswa::TARGETS as $state => $label) {
        expect(AccountControls::stateLabel($state))->toBe($label);
    }
});

it('opens for the given students with every target that changes one of them and a preselection', function (): void {
    $arsip = ubahStatusSiswa('arsip', 'Bima Alumni');
    $aktif = ubahStatusSiswa('aktif', 'Raka Pratama');

    $screen = Livewire::test(UbahStatusSiswa::class)
        ->call('open', [$arsip->getKey()])
        ->assertSet('userIds', [(int) $arsip->getKey()])
        ->assertSet('statusTarget', UbahStatusSiswa::TARGET_ACTIVE)
        ->assertDispatched('sth-modal-open', id: UbahStatusSiswa::DIALOG)
        ->assertSee('Bima Alumni')
        ->assertSee('Kelas X-1')
        ->assertSee('Aktifkan');

    expect(array_keys($screen->viewData('dialog')['targets']))->toBe(['belum', 'aktif', 'kedaluwarsa', 'dibekukan', 'nonaktif']);

    // An active student: "Aktif" does not apply; five targets, none preselected.
    $screen->call('open', [$aktif->getKey()])->assertSet('statusTarget', '');
    expect(array_keys($screen->viewData('dialog')['targets']))->toBe(['belum', 'kedaluwarsa', 'dibekukan', 'nonaktif', 'arsip']);

    // A preselection the host passes wins when it applies, and is dropped when it does not.
    $screen->call('open', [$aktif->getKey()], UbahStatusSiswa::TARGET_ARCHIVED)->assertSet('statusTarget', UbahStatusSiswa::TARGET_ARCHIVED)
        ->call('open', [$aktif->getKey()], UbahStatusSiswa::TARGET_ACTIVE)->assertSet('statusTarget', '');

    // Several: "N siswa dipilih", the names, and all six targets (each changes one of them).
    $screen->call('open', [$arsip->getKey(), (string) $aktif->getKey()])
        ->assertSet('userIds', [(int) $arsip->getKey(), (int) $aktif->getKey()])
        ->assertSee('2 siswa dipilih')
        ->assertSee('Bima Alumni, Raka Pratama');
    expect(array_keys($screen->viewData('dialog')['targets']))->toBe(array_keys(UbahStatusSiswa::TARGETS));
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

it('is Admin only: the component and every action refuse other roles with 403', function (RoleName $role): void {
    $student = ubahStatusSiswa('nonaktif');
    $ask = fn () => Livewire::test(UbahStatusSiswa::class)
        ->call('open', [$student->getKey()])
        ->set('statusTarget', UbahStatusSiswa::TARGET_FROZEN)
        ->set('statusReason', 'Siswa kembali bersekolah.');
    [$first, $second] = [$ask(), $ask()];

    $this->actingAs(A::staff($role));
    Livewire::test(UbahStatusSiswa::class)->assertForbidden();
    $first->call('save')->assertForbidden();
    $second->call('open', [$student->getKey()])->assertForbidden();

    expect($student->fresh()->status)->toBe(UserStatus::Disabled)
        ->and(Activity::query()->where('event', 'like', 'account.%')->exists())->toBeFalse();
})->with([RoleName::Kesiswaan, RoleName::TataUsaha, RoleName::KepalaSekolah, RoleName::Humas]);

// --- Every target from every state --------------------------------------------------------------------------------

it('brings each state to each target, or does not offer a target the student is already in', function (string $source, string $target): void {
    $student = ubahStatusSiswa($source);
    $from = AccountControls::state($student);

    $screen = Livewire::test(UbahStatusSiswa::class)->call('open', [$student->getKey()]);

    if ($from === $target) {
        expect($screen->viewData('dialog')['targets'])->not->toHaveKey($target);

        return;
    }

    $screen->set('statusTarget', $target)
        ->set('statusReason', 'Perubahan status oleh Admin.')
        ->set('statusArchiveDate', ubahStatusTomorrow())
        ->set('statusTemporaryPassword', UBAH_STATUS_SANDI)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('userIds', [])
        ->assertSet('statusTemporaryPassword', '')
        ->assertDispatched('sth-modal-close', id: UbahStatusSiswa::DIALOG)
        ->assertDispatched(UbahStatusSiswa::DONE_EVENT)
        ->assertDispatched('sth-toast', message: '1 akun diubah.', tone: 'success');

    $fresh = $student->fresh();

    expect(AccountControls::state($fresh))->toBe($target)
        ->and($fresh->canLogIn())->toBe(! in_array($target, [UbahStatusSiswa::TARGET_DISABLED, UbahStatusSiswa::TARGET_ARCHIVED], true));

    Notification::assertNothingSent();
})->with(['aktif', 'belum', 'kedaluwarsa', 'dibekukan', 'dibekukan-aktif', 'nonaktif', 'nonaktif-lewat', 'arsip', 'arsip-belum'])
    ->with(array_keys(UbahStatusSiswa::TARGETS));

it('runs the Actions of each state on the way to "Aktif" and audits them', function (): void {
    $students = collect(['arsip', 'nonaktif', 'dibekukan', 'kedaluwarsa', 'belum'])->mapWithKeys(fn (string $state, int $i) => [$state => ubahStatusSiswa($state, 'Siswa '.($i + 1))]);
    $date = ubahStatusTomorrow();

    Livewire::test(UbahStatusSiswa::class)
        ->call('open', $students->map->getKey()->values()->all())
        ->assertSet('statusTarget', '')
        ->set('statusTarget', UbahStatusSiswa::TARGET_ACTIVE)
        ->assertSee('1 akun diarsipkan dipulihkan dengan tanggal arsip otomatis baru.')
        ->assertSee('1 akun nonaktif diaktifkan lagi.')
        ->assertSee('1 akun dibekukan dibuka pembekuannya.')
        ->assertSee('3 akun yang belum selesai aktivasi dijadikan aktif.')
        ->assertSee('Saat login, siswa yang belum selesai aktivasi tetap menghubungkan email, memasukkan kode, dan membuat kata sandi baru.')
        ->assertDontSeeHtml('id="f-ubah-status-sandi"')
        ->set('statusReason', 'Siswa kembali bersekolah.')
        ->set('statusArchiveDate', $date)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: '5 akun diubah.', tone: 'success');

    $event = fn (string $name, User $user): ?Activity => Activity::query()->where('event', $name)->where('subject_id', $user->getKey())->first();

    expect($event('account.unarchived', $students['arsip'])?->properties['dates']['to']['archive_at'])->toBe($date)
        ->and($event('account.enabled', $students['nonaktif'])?->properties['reason'])->toBe('Siswa kembali bersekolah.')
        ->and($event('account.unfrozen', $students['dibekukan']))->not->toBeNull()
        ->and($event(ActivateStudentAccount::EVENT, $students['dibekukan'])?->properties['from'])->toBe('belum')
        ->and($event(ActivateStudentAccount::EVENT, $students['kedaluwarsa'])?->properties['from'])->toBe('kedaluwarsa')
        ->and($event(ActivateStudentAccount::EVENT, $students['belum'])?->properties['reason'])->toBe('Siswa kembali bersekolah.')
        // A date the nonaktif student did not need is not written.
        ->and($students['nonaktif']->student->fresh()->archive_at->toDateString())->toBe($students['nonaktif']->student->archive_at->toDateString())
        ->and($students->every(fn (User $user): bool => AccountControls::state($user->fresh()) === AccountControls::STATE_ACTIVE))->toBeTrue();
});

it('sends a never-activated student the Admin made "Aktif" through email, code and a new password, without Data diri', function (): void {
    Mail::fake();
    config(['fortify.sth.otp_test_mode' => false]);
    $student = ubahStatusSiswa('kedaluwarsa');

    Livewire::test(UbahStatusSiswa::class)
        ->call('open', [$student->getKey()])
        ->assertSet('statusTarget', UbahStatusSiswa::TARGET_ACTIVE)
        ->set('statusReason', 'Siswa sudah dicek di TU.')
        ->call('save')
        ->assertDispatched('sth-toast', message: '1 akun diubah.', tone: 'success');

    auth()->logout();
    $this->from('/login')->post('/login', ['identifier' => '0098765432', 'password' => 'password'])->assertRedirect(route('aktivasi'));
    $this->assertAuthenticatedAs($student->fresh());

    $screen = Livewire::test(Activation::class)
        ->assertSet('step', Activation::STEP_EMAIL)
        ->set('email', 'raka.pratama@example.test')
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_CODE);

    $code = null;
    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code): bool {
        $code = $mail->code;

        return true;
    });

    $screen->set('code', (string) $code)
        ->call('verifyCode')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_PASSWORD)
        ->set('password', 'Pelangi-senja-2026')
        ->set('password_confirmation', 'Pelangi-senja-2026')
        ->call('savePassword')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_DONE);

    $fresh = $student->fresh();

    expect($fresh->status)->toBe(UserStatus::Active)
        ->and($fresh->must_change_password)->toBeFalse()
        ->and($fresh->email)->toBe('raka.pratama@example.test')
        ->and(Hash::check('Pelangi-senja-2026', $fresh->password))->toBeTrue()
        ->and($fresh->student->profile_confirmed_at)->toBeNull();
});

it('resets an active student to "Belum aktivasi" with the temporary password the Admin typed', function (): void {
    $student = ubahStatusSiswa('aktif');

    Livewire::test(UbahStatusSiswa::class)
        ->call('open', [$student->getKey()])
        ->set('statusTarget', UbahStatusSiswa::TARGET_PENDING)
        ->assertSee('1 akun aktif di-reset: email dilepas, siswa masuk dengan NISN dan kata sandi sementara dalam 72 jam, lalu aktivasi lagi.')
        ->assertSeeHtml('id="f-ubah-status-sandi"')
        ->assertSee('Minimal 8 karakter. Siswa wajib menggantinya saat login.')
        ->assertSee('Jadikan belum aktivasi')
        ->set('statusReason', 'Akun diambil alih orang lain.')
        ->set('statusTemporaryPassword', UBAH_STATUS_SANDI)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: '1 akun diubah.', tone: 'success');

    $fresh = $student->fresh();

    expect(AccountControls::state($fresh))->toBe(AccountControls::STATE_PENDING)
        ->and($fresh->email)->toBeNull()
        ->and($fresh->must_change_password)->toBeTrue()
        ->and(Hash::check(UBAH_STATUS_SANDI, $fresh->password))->toBeTrue()
        ->and(Activity::query()->where('event', 'account.reset')->sole()->properties['reason'])->toBe('Akun diambil alih orang lain.');

    // The temporary password logs in (within 72 hours) and leads to the activation.
    auth()->logout();
    $this->from('/login')->post('/login', ['identifier' => '0098765432', 'password' => UBAH_STATUS_SANDI])->assertRedirect(route('aktivasi'));
});

it('asks the temporary password only when a picked student is reset, once for all of them', function (): void {
    $raka = ubahStatusSiswa('aktif', 'Raka Pratama');
    $nadia = ubahStatusSiswa('arsip', 'Nadia Putri Anindya');
    $bayu = ubahStatusSiswa('kedaluwarsa', 'Bayu');

    $screen = Livewire::test(UbahStatusSiswa::class)
        ->call('open', [$bayu->getKey()])
        ->set('statusTarget', UbahStatusSiswa::TARGET_PENDING)
        ->assertSee('1 akun kedaluwarsa mendapat 72 jam lagi untuk aktivasi.')
        ->assertDontSeeHtml('id="f-ubah-status-sandi"');
    expect($screen->viewData('dialog')['chosen']['needsPassword'])->toBe(0);

    $screen->call('open', [$raka->getKey(), $nadia->getKey(), $bayu->getKey()])
        ->set('statusTarget', UbahStatusSiswa::TARGET_DISABLED)
        ->assertDontSeeHtml('id="f-ubah-status-sandi"')
        ->set('statusTarget', UbahStatusSiswa::TARGET_EXPIRED)
        ->assertSeeHtml('id="f-ubah-status-sandi"')
        ->assertSee('2 akun di-reset dulu: email dilepas dan kata sandi sementara dipasang.')
        ->set('statusTarget', UbahStatusSiswa::TARGET_PENDING)
        ->assertSee('2 akun aktif di-reset')
        ->set('statusReason', 'Semua siswa aktivasi ulang.')
        ->set('statusArchiveDate', ubahStatusTomorrow())
        ->set('statusTemporaryPassword', 'pendek')
        ->call('save')
        ->assertHasErrors(['statusTemporaryPassword'])
        ->assertSee(ResetStudentAccount::MSG_PASSWORD)
        ->assertNotDispatched('sth-toast')
        ->set('statusTemporaryPassword', UBAH_STATUS_SANDI)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: '3 akun diubah.', tone: 'success');

    expect(Hash::check(UBAH_STATUS_SANDI, $raka->fresh()->password))->toBeTrue()
        ->and(Hash::check(UBAH_STATUS_SANDI, $nadia->fresh()->password))->toBeTrue()
        ->and(Hash::check(UBAH_STATUS_SANDI, $bayu->fresh()->password))->toBeFalse()
        ->and(collect([$raka, $nadia, $bayu])->every(fn (User $user): bool => AccountControls::state($user->fresh()) === AccountControls::STATE_PENDING))->toBeTrue()
        ->and(Activity::query()->where('event', 'account.reset')->count())->toBe(2)
        ->and(Activity::query()->where('event', 'account.activation_reopened')->where('subject_id', $bayu->getKey())->exists())->toBeTrue();
});

it('never shows back, logs or audits the temporary password', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
        $logged[] = $e->message.' '.json_encode($e->context);
    });
    $student = ubahStatusSiswa('aktif');

    Livewire::test(UbahStatusSiswa::class)
        ->call('open', [$student->getKey()])
        ->set('statusTarget', UbahStatusSiswa::TARGET_EXPIRED)
        ->set('statusReason', 'Akun diambil alih orang lain.')
        ->set('statusTemporaryPassword', UBAH_STATUS_SANDI)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('statusTemporaryPassword', '')
        ->assertDontSeeHtml(UBAH_STATUS_SANDI)
        // The one toast is exactly this sentence: no password in it.
        ->assertDispatched('sth-toast', message: '1 akun diubah.', tone: 'success');

    expect(AccountControls::state($student->fresh()))->toBe(AccountControls::STATE_EXPIRED)
        ->and(Hash::check(UBAH_STATUS_SANDI, $student->fresh()->password))->toBeTrue()
        ->and(json_encode(Activity::query()->get(['description', 'event', 'properties'])))->not->toContain(UBAH_STATUS_SANDI)
        ->and(implode("\n", $logged))->not->toContain(UBAH_STATUS_SANDI)
        ->and(Activity::query()->where('event', ExpireActivationWindow::EVENT)->where('subject_id', $student->getKey())->exists())->toBeTrue();

    // Kedaluwarsa: the temporary password does not log in.
    auth()->logout();
    $this->from('/login')->post('/login', ['identifier' => '0098765432', 'password' => UBAH_STATUS_SANDI])
        ->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    $this->assertGuest();
});

it('forgets the temporary password when the dialog closes or sends the Admin to the password confirmation', function (): void {
    $student = ubahStatusSiswa('aktif');

    Livewire::test(UbahStatusSiswa::class)
        ->call('open', [$student->getKey()])
        ->set('statusTarget', UbahStatusSiswa::TARGET_PENDING)
        ->set('statusTemporaryPassword', UBAH_STATUS_SANDI)
        ->call('forget')
        ->assertSet('statusTemporaryPassword', '');

    session()->forget('auth.password_confirmed_at');

    Livewire::test(UbahStatusSiswa::class, ['returnTo' => route('admin.kelas.index')])
        ->call('open', [$student->getKey()])
        ->set('statusTarget', UbahStatusSiswa::TARGET_PENDING)
        ->set('statusReason', 'Akun diambil alih orang lain.')
        ->set('statusTemporaryPassword', UBAH_STATUS_SANDI)
        ->call('save')
        ->assertRedirect(route('password.confirm'))
        ->assertSet('statusTemporaryPassword', '');

    expect(AccountControls::state($student->fresh()))->toBe(AccountControls::STATE_ACTIVE);
});

it('freezes with "Dibekukan": sessions end and the student cannot log in', function (string $source): void {
    $student = ubahStatusSiswa($source);
    DB::table('sessions')->insert(['id' => 'sesi-beku', 'user_id' => $student->getKey(), 'ip_address' => '127.0.0.1', 'user_agent' => 'Pest', 'payload' => '', 'last_activity' => time()]);

    Livewire::test(UbahStatusSiswa::class)
        ->call('open', [$student->getKey()])
        ->set('statusTarget', UbahStatusSiswa::TARGET_FROZEN)
        ->assertSee('Akun yang dibekukan tidak bisa login dan semua sesinya diakhiri sampai Admin membuka pembekuannya.')
        ->assertSee('Bekukan')
        ->set('statusReason', 'Akun dipakai orang lain.')
        ->set('statusArchiveDate', ubahStatusTomorrow())
        ->call('save')
        ->assertDispatched('sth-toast', message: '1 akun diubah.', tone: 'success');

    $fresh = $student->fresh();

    expect(AccountControls::frozenReason($fresh))->toBe(AccountControls::FROZEN_ADMIN)
        ->and(DB::table('sessions')->where('user_id', $student->getKey())->count())->toBe(0)
        ->and(Activity::query()->where('event', FreezeAccount::EVENT)->sole()->properties['reason'])->toBe('Akun dipakai orang lain.');

    auth()->logout();
    $this->from('/login')->post('/login', ['identifier' => '0098765432', 'password' => 'password'])
        ->assertSessionHasErrors(['identifier' => AuthenticateUser::GENERIC_ERROR]);
    $this->assertGuest();
})->with(['aktif', 'belum', 'nonaktif']);

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

it('rolls back the whole chain of a student when one step is refused', function (): void {
    $student = ubahStatusSiswa('aktif');
    $email = $student->email;
    $this->app->bind(ExpireActivationWindow::class, function (): never {
        throw ValidationException::withMessages(['user' => 'Langkah uji ditolak.']);
    });

    Livewire::test(UbahStatusSiswa::class)
        ->call('open', [$student->getKey()])
        ->set('statusTarget', UbahStatusSiswa::TARGET_EXPIRED)
        ->set('statusReason', 'Akun diambil alih orang lain.')
        ->set('statusTemporaryPassword', UBAH_STATUS_SANDI)
        ->call('save')
        ->assertDispatched('sth-toast', message: '0 akun diubah. 1 gagal: Langkah uji ditolak.', tone: 'warning');

    $fresh = $student->fresh();

    expect(AccountControls::state($fresh))->toBe(AccountControls::STATE_ACTIVE)
        ->and($fresh->email)->toBe($email)
        ->and(Hash::check('password', $fresh->password))->toBeTrue()
        ->and(Activity::query()->where('event', 'account.reset')->exists())->toBeFalse();
});

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
        ->assertSee('2 akun akan diubah. 1 dilewati karena sudah aktif.')
        ->set('statusTarget', UbahStatusSiswa::TARGET_PENDING)
        ->assertSee('2 akun akan diubah. 1 dilewati karena masih belum aktivasi.');

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
        ->set('statusTarget', UbahStatusSiswa::TARGET_FROZEN)
        ->assertSeeHtml('id="f-ubah-status-tanggal"')
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
