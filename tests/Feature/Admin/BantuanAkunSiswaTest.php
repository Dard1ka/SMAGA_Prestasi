<?php

declare(strict_types=1);

use App\Actions\Accounts\DetermineActivationStep;
use App\Actions\Accounts\ResetStudentAccount;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\Pengaturan\Pengguna;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\TalentApplication;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 * D-158 (the lead, 2026-10-19): "Bantuan akun siswa" finds a student by name as well as by the full NISN. The lead on
 * 2026-10-21: its reset is "Reset kata sandi & email" again, with a temporary password the Admin types. Fictional data.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    $this->admin = A::admin();
    $this->actingAs($this->admin);
    $this->class = Classroom::factory()->level(11)->create(['name' => 'XI-D']);
});

function bantuanStudent(object $t, string $name, array $user = []): Student
{
    $student = Student::factory()->create(['classroom_id' => $t->class->getKey()]);
    $student->user->forceFill(array_merge(['name' => $name], $user))->saveQuietly();

    return $student->fresh();
}

it('opens the only student whose name matches part of the search', function (): void {
    $nadia = bantuanStudent($this, 'Nadia Putri Anindya');
    bantuanStudent($this, 'Raka Pratama');

    Livewire::test(Pengguna::class)
        ->set('studentQuery', '  putri  ')
        ->call('findStudent')
        ->assertHasNoErrors()
        ->assertSet('studentQuery', 'putri')
        ->assertSet('studentUserId', (int) $nadia->user_id)
        ->assertSet('studentMatches', [])
        ->assertSee('Nadia Putri Anindya')
        ->assertSee('Reset kata sandi & email');
});

it('lists several name matches to pick from, at most ten, and opens the chosen one', function (): void {
    $first = bantuanStudent($this, 'Dimas Saputra');
    bantuanStudent($this, 'Bima Saputra');

    $component = Livewire::test(Pengguna::class)
        ->set('studentQuery', 'saputra')
        ->call('findStudent')
        ->assertSet('studentUserId', null)
        ->assertSee('2 siswa cocok. Pilih salah satu.')
        ->assertSee('Dimas Saputra')
        ->assertSee((string) $first->nisn)
        ->assertSeeHtml('wire:click="chooseStudent('.$first->user_id.')"')
        ->call('chooseStudent', (int) $first->user_id)
        ->assertSet('studentUserId', (int) $first->user_id)
        ->assertSet('studentMatches', [])
        ->assertDontSee('siswa cocok. Pilih salah satu.');

    foreach (range(1, 11) as $i) {
        bantuanStudent($this, 'Siswa Contoh '.$i);
    }

    $component->set('studentQuery', 'contoh')
        ->call('findStudent')
        ->assertSet('studentUserId', null)
        ->assertCount('studentMatches', Pengguna::MATCH_LIMIT)
        ->assertSet('studentMatchesMore', true)
        ->assertSee('Lebih dari 10 siswa cocok. Ketik nama lebih lengkap.');
});

it('still finds a student by the full NISN and refuses a short or unknown search', function (): void {
    $nadia = bantuanStudent($this, 'Nadia Putri Anindya');

    Livewire::test(Pengguna::class)
        ->set('studentQuery', (string) $nadia->nisn)
        ->call('findStudent')
        ->assertSet('studentUserId', (int) $nadia->user_id)
        ->set('studentQuery', '123')
        ->call('findStudent')
        ->assertHasErrors(['studentQuery' => 'Isi NISN (10 digit) lengkap.'])
        ->set('studentQuery', 'na')
        ->call('findStudent')
        ->assertHasErrors(['studentQuery' => 'Ketik minimal 3 huruf nama siswa.'])
        ->set('studentQuery', '')
        ->call('findStudent')
        ->assertHasErrors(['studentQuery' => Pengguna::MSG_QUERY])
        ->set('studentQuery', 'Tidak Ada Orangnya')
        ->call('findStudent')
        ->assertHasErrors(['studentQuery' => 'Siswa tidak ditemukan.'])
        ->assertSet('studentUserId', null);
});

it('never opens a staff account through a forged choice', function (): void {
    $guru = A::staff(RoleName::Guru);

    Livewire::test(Pengguna::class)->call('chooseStudent', (int) $guru->getKey())->assertNotFound();
});

// The lead on 2026-10-21 (replacing the password-only reset of D-158): "Reset kata sandi & email" is for a taken-over
// account. The Admin types the temporary password; the email goes and the student activates again.

function bantuanActive(object $t): Student
{
    return bantuanStudent($t, 'Nadia Putri Anindya', [
        'status' => UserStatus::Active, 'email' => 'nadia.anindya@example.test', 'email_verified_at' => now(),
        'must_change_password' => false, 'activated_at' => now()->subMonth(),
    ]);
}

it('says in the reset dialog that the email goes, the student activates again and Data diri stays', function (): void {
    $nadia = bantuanActive($this);

    Livewire::test(Pengguna::class)
        ->set('studentQuery', (string) $nadia->nisn)
        ->call('findStudent')
        ->assertSee('Untuk akun siswa yang diambil alih orang lain.')
        ->assertSee('Reset kata sandi & email')
        ->assertSee('Reset kata sandi & email?')
        ->assertSee('Email yang terhubung dilepas.')
        ->assertSee('Dalam 72 jam siswa masuk dengan NISN dan kata sandi sementara, lalu menghubungkan emailnya sendiri, memasukkan kode, dan membuat kata sandi baru.')
        ->assertSee('Data diri dan pilihan talenta siswa tetap tersimpan.')
        ->assertSee('Minimal 8 karakter. Siswa wajib menggantinya saat login.')
        ->assertSeeHtml('wire:model="studentPassword"')
        // The generated-password dialog and its copy button are gone.
        ->assertDontSeeHtml('id="siswa-sandi"')
        ->assertDontSeeHtml('kata-sandi-sementara')
        ->assertDontSeeHtml('data-sth-copy-flash')
        ->assertDontSee('kamu');
});

it('refuses a temporary password under 8 characters under its field, together with the reason', function (): void {
    session(A::passwordConfirmed());
    $nadia = bantuanActive($this);
    $hash = $nadia->user->password;

    Livewire::test(Pengguna::class)
        ->set('studentQuery', (string) $nadia->nisn)
        ->call('findStudent')
        ->call('openStudent', 'siswa-reset')
        ->set('studentPassword', 'pendek7')
        ->call('resetStudent')
        ->assertHasErrors([
            'studentPassword' => ResetStudentAccount::MSG_PASSWORD,
            'studentReason' => ResetStudentAccount::MSG_REASON,
        ])
        ->assertSee(ResetStudentAccount::MSG_PASSWORD)
        ->assertNotDispatched('sth-toast');

    expect($nadia->user->fresh()->password)->toBe($hash)
        ->and(Activity::query()->where('event', 'account.reset')->exists())->toBeFalse();
});

it('resets password and email from the dialog, empties the field and keeps Data diri and talents', function (): void {
    session(A::passwordConfirmed());
    $nadia = bantuanActive($this);
    $nadia->forceFill(['phone' => '081234567890', 'profile_confirmed_at' => now()->subMonth()])->save();
    $talent = TalentApplication::factory()->create(['student_id' => $nadia->getKey()]);

    Livewire::test(Pengguna::class)
        ->set('studentQuery', 'nadia')
        ->call('findStudent')
        ->call('openStudent', 'siswa-reset')
        ->assertDispatched('sth-modal-open', id: 'siswa-reset')
        ->set('studentReason', 'Siswa datang ke TU, akun diambil alih.')
        ->set('studentPassword', 'tujuhbelas17')
        ->call('resetStudent')
        ->assertHasNoErrors()
        ->assertSet('studentPassword', '')
        ->assertSet('studentReason', '')
        ->assertSet('resetAskedFor', null)
        ->assertDispatched('sth-modal-close', id: 'siswa-reset')
        ->assertDispatched('sth-toast', tone: 'success')
        ->assertDontSee('tujuhbelas17')
        ->assertSee('belum terhubung');

    $user = $nadia->user->fresh();
    expect(Hash::check('tujuhbelas17', $user->password))->toBeTrue()
        ->and($user->email)->toBeNull()
        ->and($user->status)->toBe(UserStatus::PendingActivation)
        ->and($user->activated_at)->toBeNull()
        ->and($nadia->fresh()->profile_confirmed_at)->not->toBeNull()
        ->and($talent->fresh())->not->toBeNull()
        ->and(app(DetermineActivationStep::class)($user))->toBe(DetermineActivationStep::EMAIL)
        ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain('tujuhbelas17')
        ->and(Activity::query()->where('event', 'account.reset')->sole()->properties['email_detached'])->toBeTrue();
});

it('does nothing on a reset call without the dialog question', function (): void {
    session(A::passwordConfirmed());
    $nadia = bantuanActive($this);

    Livewire::test(Pengguna::class)
        ->set('studentQuery', (string) $nadia->nisn)
        ->call('findStudent')
        ->set('studentReason', 'Siswa datang ke TU.')
        ->set('studentPassword', 'sementara8')
        ->call('resetStudent')
        ->assertHasNoErrors()
        ->assertNotDispatched('sth-toast')
        ->assertNoRedirect();

    expect($nadia->user->fresh()->email)->toBe('nadia.anindya@example.test')
        ->and(Activity::query()->where('event', 'account.reset')->exists())->toBeFalse();

    // The asked id is locked against tampering.
    expect(fn () => Livewire::test(Pengguna::class)->set('resetAskedFor', (int) $nadia->user_id))->toThrow(Exception::class);
});

it('answers with a toast when the student was disabled after the dialog opened', function (): void {
    session(A::passwordConfirmed());
    $nadia = bantuanActive($this);

    $screen = Livewire::test(Pengguna::class)
        ->set('studentQuery', (string) $nadia->nisn)
        ->call('findStudent')
        ->call('openStudent', 'siswa-reset')
        ->set('studentReason', 'Siswa datang ke TU.')
        ->set('studentPassword', 'sementara8');

    $nadia->user->forceFill(['status' => UserStatus::Disabled])->saveQuietly();

    $screen->call('resetStudent')
        ->assertDispatched('sth-toast', message: 'Akun nonaktif tidak bisa login, jadi tidak bisa direset.', tone: 'warning')
        ->assertDispatched('sth-modal-close', id: 'siswa-reset')
        ->assertSet('studentPassword', '');

    expect(Activity::query()->where('event', 'account.reset')->exists())->toBeFalse();
});

it('lets only an Admin open Pengguna & peran and reset a student', function (RoleName $role): void {
    $nadia = bantuanActive($this);
    $actor = A::staff($role);
    $this->actingAs($actor);

    Livewire::test(Pengguna::class)->assertForbidden();
    expect(fn () => app(ResetStudentAccount::class)->handle($actor, $nadia->user, 'Alasan', 'sementara8'))
        ->toThrow(AuthorizationException::class);
})->with([RoleName::Kesiswaan, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas, RoleName::Guru]);
