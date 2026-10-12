<?php

declare(strict_types=1);

use App\Actions\Accounts\DisableAccount;
use App\Actions\Students\UpdateConsent;
use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\Pengaturan\Pengguna;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Student;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;
use Tests\Feature\Publik\PublikFixtures;

/*
| W3-A: consent flags (photo_consent / publish_consent) only through App\Actions\Students\UpdateConsent
| (Admin › Pengguna › Bantuan akun siswa). Fictional data only (DESIGN-SPEC-v2 §5).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    $this->admin = A::admin();
    $this->update = app(UpdateConsent::class);
});

it('updates both flags, audits the basis and flushes the public cache', function (): void {
    $student = H::activeStudent();
    $student->forceFill(['photo_consent' => false, 'publish_consent' => false])->save();
    $version = (int) Cache::get(PublicAchievements::VERSION_KEY, 1);

    $this->update->handle($this->admin, $student, true, true, 'Formulir izin orang tua 1 Okt 2026.');

    $student->refresh();
    $log = Activity::query()->where('event', 'student.consent_updated')->sole();

    expect($student->photo_consent)->toBeTrue()
        ->and($student->publish_consent)->toBeTrue()
        ->and($log->causer_id)->toBe($this->admin->id)
        ->and($log->properties['from'])->toBe(['photo_consent' => false, 'publish_consent' => false])
        ->and($log->properties['to'])->toBe(['photo_consent' => true, 'publish_consent' => true])
        ->and($log->properties['note'])->toBe('Formulir izin orang tua 1 Okt 2026.')
        ->and($log->properties['slug_rotated'])->toBeFalse()
        ->and((int) Cache::get(PublicAchievements::VERSION_KEY, 1))->toBe($version + 1);
});

it('does nothing when the flags do not change', function (): void {
    $student = H::activeStudent();
    $student->forceFill(['photo_consent' => true, 'publish_consent' => true])->save();

    $this->update->handle($this->admin, $student, true, true, 'Tidak ada perubahan.');

    expect(Activity::query()->where('event', 'student.consent_updated')->exists())->toBeFalse();
});

it('rotates the public slug when publish consent is revoked, and the old link stops working', function (): void {
    $f = new PublikFixtures;
    $nadia = $f->student('Nadia Putri Anindya', 'XI-4');
    $f->prestasi($nadia, $f->lomba('OPSI 2026', Bidang::Riset, Level::Nasional, '2026-09-20'), ResultType::Juara2);
    $oldSlug = $f->slug($nadia);

    $this->withoutVite();
    $this->get(route('public.prestasi.siswa', $oldSlug))->assertOk()->assertSee('Nadia Putri Anindya');

    $this->update->handle($this->admin, $nadia, (bool) $nadia->photo_consent, false, 'Orang tua mencabut izin publikasi.');

    $newSlug = Student::query()->whereKey($nadia->id)->value('public_slug');

    expect($newSlug)->toMatch(PublicAchievements::SLUG_PATTERN)
        ->and($newSlug)->not->toBe($oldSlug)
        ->and(Activity::query()->where('event', 'student.consent_updated')->sole()->properties['slug_rotated'])->toBeTrue()
        ->and(json_encode(Activity::query()->where('event', 'student.consent_updated')->sole()->properties))->not->toContain($oldSlug);

    $this->get(route('public.prestasi.siswa', $oldSlug))->assertNotFound();
    $this->get(route('public.prestasi.siswa', $newSlug))->assertNotFound(); // no consent → no student page
    $this->get(route('public.peringkat'))->assertOk()->assertDontSee('Nadia Putri Anindya')->assertSee('Siswa kelas XI');
});

it('requires a basis for the change', function (): void {
    $student = H::activeStudent();

    expect(fn () => $this->update->handle($this->admin, $student, true, true, '   '))->toThrow(ValidationException::class)
        ->and(fn () => $this->update->handle($this->admin, $student, true, true, str_repeat('a', 501)))->toThrow(ValidationException::class);
});

it('lets only student.update holders change consent', function (RoleName $role): void {
    $student = H::activeStudent();

    expect(fn () => $this->update->handle(A::staff($role), $student, true, true, 'Coba ubah.'))->toThrow(AuthorizationException::class);
})->with([RoleName::Kesiswaan, RoleName::Humas, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Guru]);

it('refuses students and disabled admins', function (): void {
    $student = H::activeStudent();
    $disabled = A::staff(RoleName::Admin, ['username' => 'operator2']);
    $disabled->forceFill(['status' => UserStatus::Disabled])->save();

    expect(fn () => $this->update->handle($student->user, $student, true, true, 'Saya setuju.'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->update->handle($disabled, $student, true, true, 'Coba ubah.'))->toThrow(AuthorizationException::class);
});

it('edits consent from Admin › Pengguna › Bantuan akun siswa', function (): void {
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());
    $student = H::activeStudent();
    $student->forceFill(['photo_consent' => false, 'publish_consent' => true])->save();

    Livewire::test(Pengguna::class)
        ->set('studentQuery', H::NISN)
        ->call('findStudent')
        ->assertSet('photoConsent', false)
        ->assertSet('publishConsent', true)
        ->assertSee('Persetujuan publikasi')
        ->set('photoConsent', true)
        ->call('saveConsent')
        ->assertHasErrors('consentNote')
        ->set('consentNote', 'Formulir izin orang tua 1 Okt 2026.')
        ->call('saveConsent')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast');

    expect($student->fresh()->photo_consent)->toBeTrue()
        ->and($student->fresh()->publish_consent)->toBeTrue();
});

it('flushes the public page cache when Admin disables or archives a student account', function (): void {
    $student = H::activeStudent();
    $version = (int) Cache::get(PublicAchievements::VERSION_KEY, 1);

    app(DisableAccount::class)->handle($this->admin, $student->user, 'Pindah sekolah.');

    expect((int) Cache::get(PublicAchievements::VERSION_KEY, 1))->toBe($version + 1);
});
