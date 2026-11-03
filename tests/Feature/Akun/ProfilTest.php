<?php

declare(strict_types=1);

use App\Actions\Accounts\UpdateStudentProfile;
use App\Domain\Accounts\PhoneNumber;
use App\Domain\Accounts\StudentProfileInput;
use App\Enums\RoleName;
use App\Livewire\Akun\Keamanan;
use App\Livewire\Akun\Profil;
use App\Models\Student;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| D-152 (PS1, lead 2026-10-15): Profil saya (`akun.profil`), the account menu item above Notifikasi: the tabs Data diri
| (this page) and Keamanan akun (`akun.keamanan`). A student edits the own birth date and No HP; staff read their data.
| Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
    Mail::fake();
    Notification::fake();
});

it('sends a guest to the login and an unfinished activation to the activation screen', function (): void {
    $this->get(route('akun.profil'))->assertRedirect(route('login'));

    $this->actingAs(H::pendingStudent()->user)->get(route('akun.profil'))->assertRedirect(route('aktivasi'));
});

it('shows a student the read-only data, the two fields and both tabs', function (): void {
    $student = H::activeStudent();
    $student->forceFill(['phone' => '081234567890'])->save();

    $this->actingAs($student->user->fresh())
        ->get(route('akun.profil'))
        ->assertOk()
        ->assertSee('Profil saya')
        ->assertSee('Data diri')
        ->assertSee('Keamanan akun')
        ->assertSee('href="'.route('akun.keamanan').'"', false)
        ->assertSee($student->nisn)
        ->assertSee('XI-D')
        ->assertSee('nadia.anindya@example.test')
        ->assertSee('Tanggal lahir')
        ->assertSee('No HP')
        ->assertDontSee($student->nis);

    Livewire::actingAs($student->user->fresh())
        ->test(Profil::class)
        ->assertSet('birth_date', '2010-03-02')
        ->assertSet('phone', '081234567890');
});

it('saves a corrected birth date and No HP, audited with the field names only', function (): void {
    $student = H::activeStudent();

    Livewire::actingAs($student->user->fresh())
        ->test(Profil::class)
        ->set('birth_date', '2010-04-02')
        ->set('phone', '0812-3456-7890')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Data diri tersimpan.', tone: 'success')
        ->assertSet('phone', '081234567890');

    $fresh = $student->fresh();
    expect($fresh->birth_date->format('Y-m-d'))->toBe('2010-04-02')
        ->and($fresh->phone)->toBe('081234567890');

    $row = Activity::query()->where('event', UpdateStudentProfile::EVENT)->sole();
    expect($row->properties->toArray())->toBe(['fields' => ['birth_date', 'phone']])
        ->and((int) $row->causer_id)->toBe((int) $student->user_id)
        ->and((string) json_encode($row->properties))->not->toContain('0812')->not->toContain('2010');
});

it('writes nothing and says so when nothing changed', function (): void {
    $student = H::activeStudent();
    $student->forceFill(['phone' => '081234567890'])->save();

    Livewire::actingAs($student->user->fresh())
        ->test(Profil::class)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Tidak ada perubahan.', tone: 'info');

    expect(Activity::query()->where('event', UpdateStudentProfile::EVENT)->exists())->toBeFalse();
});

it('shows every problem under its field and keeps the stored values', function (): void {
    $student = H::activeStudent();
    $student->forceFill(['phone' => '081234567890'])->save();

    Livewire::actingAs($student->user->fresh())
        ->test(Profil::class)
        ->set('birth_date', '1909-03-02')
        ->set('phone', '0212345678')
        ->call('save')
        ->assertHasErrors(['birth_date', 'phone'])
        ->assertSee(StudentProfileInput::BIRTH_DATE_RANGE)
        ->assertSee(PhoneNumber::INVALID)
        ->assertNotDispatched('sth-toast');

    $fresh = $student->fresh();
    expect($fresh->birth_date->format('Y-m-d'))->toBe('2010-03-02')
        ->and($fresh->phone)->toBe('081234567890');
});

it('shows staff a read-only page without a form, and refuses a forged save', function (): void {
    // A::staff has a confirmed TOTP, so `staff.2fa` lets the account through like a real session after setup.
    $guru = A::staff(RoleName::Guru, ['name' => 'Hendra Kusuma', 'username' => 'hendra.k']);

    $this->actingAs($guru)
        ->get(route('akun.profil'))
        ->assertOk()
        ->assertSee('Hendra Kusuma')
        ->assertSee('Guru')
        ->assertSee('hendra.k')
        ->assertSee($guru->email)
        ->assertSee('Nama dan peran diubah oleh Admin.')
        ->assertDontSee('wire:submit="save"', false)
        ->assertDontSee('Tanggal lahir');

    Livewire::actingAs($guru)->test(Profil::class)->call('save')->assertForbidden();
});

it('lets a student change only the own record, never a staff account', function (): void {
    $nadia = H::activeStudent();
    $other = Student::factory()->create();
    $guru = H::staff();
    $update = app(UpdateStudentProfile::class);

    expect(fn () => $update->handle($nadia->user, $other, '2010-03-02', '081234567890'))->toThrow(AuthorizationException::class)
        ->and(fn () => $update->handle($guru, $nadia, '2010-03-02', '081234567890'))->toThrow(AuthorizationException::class)
        ->and($other->fresh()->phone)->toBeNull()
        ->and($nadia->fresh()->phone)->toBeNull();
});

it('draws the tab Keamanan akun under the title Profil saya', function (): void {
    $guru = A::staff(RoleName::Guru);

    $this->actingAs($guru)
        ->get(route('akun.keamanan'))
        ->assertOk()
        ->assertSee('Profil saya')
        ->assertSee('href="'.route('akun.profil').'"', false)
        ->assertSee('Kata sandi');

    expect(Profil::tabs())->toBe([
        ['key' => 'profil', 'label' => 'Data diri', 'href' => route('akun.profil')],
        ['key' => 'keamanan', 'label' => 'Keamanan akun', 'href' => route('akun.keamanan')],
    ]);

    Livewire::actingAs($guru)->test(Keamanan::class)->assertViewHas('tabs', Profil::tabs());
});

it('puts Profil saya above Notifikasi in the account menu of a real page', function (): void {
    $html = $this->actingAs(A::staff(RoleName::Guru))->get(route('notifikasi.index'))->assertOk()->getContent();
    $menu = Str::of($html)->after('id="sth-user-menu"')->before('Keluar')->toString();

    expect(strpos($menu, 'Profil saya'))->toBeInt()
        ->and(strpos($menu, 'Profil saya'))->toBeLessThan((int) strpos($menu, 'Notifikasi'))
        ->and($menu)->not->toContain('Keamanan akun');
});

it('lets a kelas X student without a talent open Profil saya', function (): void {
    $student = H::activeStudent();
    $student->classroom->forceFill(['level' => 10])->save();

    $this->actingAs($student->user->fresh())->get(route('akun.profil'))->assertOk();
});
