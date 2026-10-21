<?php

declare(strict_types=1);

use App\Actions\Accounts\DisableAccount;
use App\Livewire\Admin\Pengaturan\Pengguna;
use App\Livewire\Admin\Pengaturan\Publik;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Student;
use App\Policies\StudentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| D-162 (the lead, 2026-10-20): the consent concept is gone. Admin › Pengguna › Bantuan akun siswa has no
| "Persetujuan publikasi" box any more, the Action and the policy ability are deleted, and the "Halaman publik" tab
| no longer speaks of a consent. The DB columns stay (forward-only migrations) but are neither fillable nor read.
| Fictional data only (DESIGN-SPEC-v2 §5).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    $this->admin = A::admin();
});

it('shows no consent box on Bantuan akun siswa and has no consent method any more', function (): void {
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());
    H::activeStudent();

    $component = Livewire::test(Pengguna::class)
        ->set('studentQuery', H::NISN)
        ->call('findStudent')
        ->assertSee('Reset kata sandi')
        ->assertDontSee('Persetujuan publikasi')
        ->assertDontSee('Foto boleh dipakai di publikasi')
        ->assertDontSee('formulir izin orang tua')
        ->assertDontSee('Simpan persetujuan');

    expect(method_exists(Pengguna::class, 'saveConsent'))->toBeFalse()
        ->and(property_exists(Pengguna::class, 'photoConsent'))->toBeFalse()
        ->and(property_exists(Pengguna::class, 'publishConsent'))->toBeFalse()
        ->and(property_exists(Pengguna::class, 'consentNote'))->toBeFalse()
        ->and($component->get('studentUserId'))->not->toBeNull();
});

it('has deleted the consent Action and the policy ability', function (): void {
    $student = H::activeStudent();

    // Checked as a file: a stale Composer classmap may still list the name.
    expect(file_exists(app_path('Actions/Students/UpdateConsent.php')))->toBeFalse()
        ->and(method_exists(StudentPolicy::class, 'updateConsent'))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('updateConsent', $student))->toBeFalse();
});

it('no longer mass-assigns or casts the old consent columns', function (): void {
    $student = new Student;

    expect($student->getFillable())->not->toContain('photo_consent')->not->toContain('publish_consent')
        ->and($student->getCasts())->not->toHaveKey('photo_consent')->not->toHaveKey('publish_consent');
});

it('words the Halaman publik tab without consent', function (): void {
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(Publik::class)
        // Static Blade text: the quotes stand in the HTML as typed.
        ->assertSee('Siswa dengan akun nonaktif tampil sebagai "Siswa kelas …" dan tetap dihitung.', false)
        ->assertSee('Foto, NIS, NISN, HP, email, dan tanggal lahir tidak pernah tampil.')
        ->assertDontSee('izin publikasi')
        ->assertDontSee('persetujuan orang tua');
});

it('flushes the public page cache when Admin disables or archives a student account', function (): void {
    $student = H::activeStudent();
    $version = (int) Cache::get(PublicAchievements::VERSION_KEY, 1);

    app(DisableAccount::class)->handle($this->admin, $student->user, 'Pindah sekolah.');

    expect((int) Cache::get(PublicAchievements::VERSION_KEY, 1))->toBe($version + 1);
});
