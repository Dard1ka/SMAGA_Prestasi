<?php

declare(strict_types=1);

use App\Actions\Publications\AchievementFacts;
use App\Actions\Publications\FlyerData;
use App\Enums\AppreciationStatus;
use App\Enums\PublicationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Humas\Apresiasi;
use App\Livewire\Humas\Arsip;
use App\Livewire\Humas\Jadwal;
use App\Livewire\Humas\Publikasi;
use App\Models\Appreciation;
use App\Models\File;
use App\Models\Publication;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
| D-162 (the lead, 2026-10-20): no consent any more. Every student is named on the Humas screens, whatever the old
| (unused) columns `photo_consent` / `publish_consent` say. The one student who is not named is a disabled or deleted
| account (NamedStudent): Humas (no `student.view`) loses that student's name/class on Arsip, Apresiasi and Jadwal; the
| row stays (no chip). Kesiswaan and Admin (`student.view`) keep the name on the screens they already open.
| Flyers (M3-E): STH generates none any more. A flyer file from before keeps its FilePolicy rule (Humas while the
| student may be named, Admin always, D-75), but no Humas screen links to it.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    config(['fortify.sth.require_staff_2fa' => false]);

    $this->humas = HumasFixtures::humas();
    $this->admin = AuthTestHelpers::staff(RoleName::Admin);

    // A published post of Hana (XI-D) with a flyer file from before M3-E, also on an upcoming appreciation event.
    $this->publication = HumasFixtures::publication(
        publicationState: ['status' => PublicationStatus::Dipublikasikan, 'published_at' => now()->subDays(3), 'channels' => ['instagram']],
        studentName: 'Hana Lestari',
        competitionName: 'Kimia Jateng 2026',
    );
    $this->flyer = HumasFixtures::legacyFlyer($this->publication, $this->humas);

    $this->event = HumasFixtures::event();
    $appreciation = new Appreciation(['achievement_id' => $this->publication->achievement_id, 'appreciation_event_id' => $this->event->getKey()]);
    $appreciation->forceFill(['status' => AppreciationStatus::Dijadwalkan])->save();

    $this->disable = function (UserStatus $status = UserStatus::Disabled): void {
        $this->publication->achievement->student->user->forceFill(['status' => $status])->save();
    };
});

// --- D-162: the old consent columns are ignored -----------------------------------------------------------

it('names a student whose old consent columns are false on every Humas screen (D-162)', function (): void {
    Student::query()->whereKey($this->publication->achievement->student_id)->update(['photo_consent' => false, 'publish_consent' => false]);

    Livewire::actingAs($this->humas)->test(Arsip::class)
        ->assertSee('Hana Lestari')->assertSee('XI-D')->assertDontSee('Siswa kelas XI')->assertDontSee('Izin dicabut')
        ->set('q', 'hana')->assertSee('Kimia Jateng 2026');
    Livewire::actingAs($this->humas)->test(Apresiasi::class)
        ->assertSee('Hana Lestari')->assertDontSee('Izin dicabut');
    Livewire::actingAs($this->humas)->test(Jadwal::class)
        ->assertSee('Hana Lestari')->assertDontSee('Izin dicabut');

    $facts = AchievementFacts::of($this->publication->achievement->fresh());

    expect($facts->named)->toBeTrue()
        ->and($facts->visibleTo($this->humas))->toBe($facts)
        ->and($this->humas->can('view', File::query()->findOrFail($this->flyer->getKey())))->toBeTrue();
});

// --- Flyer files made before M3-E (FilePolicy → FileController answers 404) ------------------------------

it('still serves a flyer made before M3-E to publication.manage holders while the student may be named', function (): void {
    $otherHumas = AuthTestHelpers::staff(RoleName::Humas, ['email' => 'humas.dua@example.test']);

    $this->actingAs($this->humas)->get(route('files.show', $this->flyer))->assertOk();
    $this->actingAs($otherHumas)->get(route('files.show', $this->flyer))->assertOk();
    $this->actingAs($this->admin)->get(route('files.show', $this->flyer))->assertOk();
    $this->actingAs(AuthTestHelpers::staff(RoleName::Kesiswaan))->get(route('files.show', $this->flyer))->assertNotFound();
    $this->actingAs(AuthTestHelpers::staff(RoleName::Guru))->get(route('files.show', $this->flyer))->assertNotFound();
});

it('answers 404 for the flyer once the account is disabled, also for the Humas user who generated it; Admin keeps it (D-75)', function (): void {
    ($this->disable)();

    $file = File::query()->findOrFail($this->flyer->getKey());
    $otherHumas = AuthTestHelpers::staff(RoleName::Humas, ['email' => 'humas.dua@example.test']);

    expect((int) $file->owner_user_id)->toBe((int) $this->humas->getKey())
        ->and($this->humas->can('view', $file))->toBeFalse()
        ->and($otherHumas->can('view', $file))->toBeFalse()
        ->and($this->admin->can('view', $file))->toBeTrue();

    $this->actingAs($this->humas)->get(route('files.show', $file))->assertNotFound();
    $this->actingAs($otherHumas)->get(route('files.show', $file))->assertNotFound();
    $this->actingAs($this->admin)->get(route('files.show', $file))->assertOk();
    $this->actingAs(AuthTestHelpers::staff(RoleName::Kesiswaan))->get(route('files.show', $file))->assertNotFound();
});

it('serves the flyer again when the account is active again', function (): void {
    ($this->disable)();
    ($this->disable)(UserStatus::Active);

    $this->actingAs($this->humas)->get(route('files.show', $this->flyer))->assertOk();
});

it('does not serve a flyer that no publication uses, even to its uploader', function (): void {
    Publication::query()->whereKey($this->publication->getKey())->update(['flyer_file_id' => null]);

    expect($this->humas->can('view', $this->flyer->fresh()))->toBeFalse()
        ->and($this->admin->can('view', $this->flyer->fresh()))->toBeFalse();
    $this->actingAs($this->humas)->get(route('files.show', $this->flyer))->assertNotFound();
});

// --- Arsip -----------------------------------------------------------------------------------------------

it('shows name and class on Arsip, with "Lihat" and no flyer column or link (M3-E)', function (): void {
    Livewire::actingAs($this->humas)
        ->test(Arsip::class)
        ->assertSee('Kimia Jateng 2026')
        ->assertSee('Hana Lestari')
        ->assertSee('XI-D')
        ->assertSeeInOrder(['Lomba', 'Siswa', 'Capaian', 'Kanal', 'Terbit'])
        ->assertDontSee('Flyer')
        ->assertDontSee(route('files.show', $this->flyer), false)
        // "Lihat" opens the panel with the caption and the data for the flyer.
        ->assertSeeHtml('href="'.route('humas.beranda', ['pilih' => $this->publication->getKey()]).'"')
        ->assertSee('Lihat')
        ->assertDontSee('Siswa kelas XI');

    Livewire::withQueryParams(['pilih' => $this->publication->getKey()])
        ->actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSee('Sudah terbit')
        ->assertSee('Data untuk flyer')
        ->assertSee('Hana Lestari');
});

it('hides name and class on Arsip once the account is disabled, and offers no "Lihat"', function (): void {
    ($this->disable)();

    Livewire::actingAs($this->humas)
        ->test(Arsip::class)
        ->assertSee('Kimia Jateng 2026')
        ->assertSee('Siswa kelas XI')
        ->assertDontSee('Izin dicabut')
        ->assertDontSee('Hana Lestari')
        ->assertDontSee('XI-D')
        ->assertDontSee('pilih='.$this->publication->getKey(), false)
        ->assertDontSee(route('files.show', $this->flyer), false);

    // The old address no longer opens the card: the queue no longer holds this publication (QA10: a warning toast
    // instead of a full-screen 404).
    Livewire::withQueryParams(['pilih' => $this->publication->getKey()])
        ->actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertDispatched('sth-toast', tone: 'warning')
        ->assertNotSet('selectedId', $this->publication->getKey())
        ->assertDontSee('Hana Lestari');
});

it('does not find a disabled student by name on Arsip, only by lomba', function (): void {
    ($this->disable)();

    Livewire::actingAs($this->humas)
        ->test(Arsip::class)
        ->set('q', 'hana')
        ->assertSee('Tidak ada publikasi yang cocok')
        ->assertDontSee('Kimia Jateng 2026')
        ->set('q', 'kimia')
        ->assertSee('Kimia Jateng 2026')
        ->assertDontSee('Hana Lestari');
});

it('keeps the name on Arsip for Admin (student.view); the old flyer is not linked there (M3-E)', function (): void {
    ($this->disable)();

    Livewire::actingAs($this->admin)
        ->test(Arsip::class)
        ->assertSee('Hana Lestari')
        ->assertSee('XI-D')
        ->assertDontSee('Siswa kelas XI')
        ->assertDontSee('Flyer')
        ->assertDontSee(route('files.show', $this->flyer), false)
        ->assertDontSee('pilih='.$this->publication->getKey(), false)
        ->set('q', 'hana')
        ->assertSee('Kimia Jateng 2026');

    // The file itself is kept and Admin can still fetch it by its address (D-75).
    expect($this->publication->fresh()->flyerFile?->getKey())->toBe($this->flyer->getKey())
        ->and(Storage::disk('local')->exists($this->flyer->path))->toBeTrue();
    $this->actingAs($this->admin)->get(route('files.show', $this->flyer))->assertOk();
});

// --- Apresiasi (shared with Kesiswaan) -------------------------------------------------------------------

it('hides name and class on Apresiasi once the account is disabled', function (): void {
    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->assertSee('Hana Lestari')
        ->assertSee('XI-D');

    ($this->disable)();

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->assertSee('Kimia Jateng 2026')
        ->assertSee('Siswa kelas XI')
        ->assertDontSee('Izin dicabut')
        ->assertDontSee('Hana Lestari')
        ->assertDontSee('XI-D');
});

it('keeps the name on Apresiasi for Kesiswaan and Admin (student.view)', function (RoleName $role): void {
    ($this->disable)();

    $user = $role === RoleName::Admin ? $this->admin : AuthTestHelpers::staff($role);

    Livewire::actingAs($user)
        ->test(Apresiasi::class)
        ->assertSee('Hana Lestari')
        ->assertSee('XI-D')
        ->assertDontSee('Siswa kelas XI');
})->with([RoleName::Kesiswaan, RoleName::Admin]);

// --- Jadwal ----------------------------------------------------------------------------------------------

it('hides name and class in the appreciation agenda of Jadwal once the account is disabled', function (): void {
    Livewire::actingAs($this->humas)
        ->test(Jadwal::class)
        ->assertSee('Hana Lestari');

    ($this->disable)();

    Livewire::actingAs($this->humas)
        ->test(Jadwal::class)
        ->assertSee('Kimia Jateng 2026')
        ->assertSee('Siswa kelas XI')
        ->assertDontSee('Izin dicabut')
        ->assertDontSee('Hana Lestari')
        ->assertDontSee('XI-D');
});

it('keeps the name on Jadwal for Admin (student.view)', function (): void {
    ($this->disable)();

    Livewire::actingAs($this->admin)
        ->test(Jadwal::class)
        ->assertSee('Hana Lestari')
        ->assertDontSee('Siswa kelas XI');
});

// --- Neutral label ---------------------------------------------------------------------------------------

it('uses the neutral label of the public pages for a disabled account and a viewer without student.view', function (): void {
    $named = HumasFixtures::achievement(['publish_consent' => false, 'photo_consent' => false], studentName: 'Raka Pradipta');
    $alumni = HumasFixtures::achievement(['publish_consent' => false], studentName: 'Bima Satria Wicaksono');
    $alumni->student->user->forceFill(['status' => UserStatus::Archived])->save();
    $hidden = HumasFixtures::achievement(studentName: 'Fajar Ramadhan', disabled: true);

    $facts = AchievementFacts::of($named);
    $masked = AchievementFacts::of($hidden)->visibleTo($this->humas);

    expect($facts->named)->toBeTrue()
        ->and($facts->visibleTo($this->humas))->toBe($facts)
        // An alumnus stays named (D-29), consent columns or not.
        ->and(AchievementFacts::of($alumni->fresh())->visibleTo($this->humas)->studentName)->toBe('Bima Satria Wicaksono')
        ->and($masked->studentName)->toBe('Siswa kelas XI')
        ->and($masked->className)->toBeNull()
        ->and($masked->named)->toBeFalse()
        ->and($masked->competitionName)->toBe('OPSI 2026')
        ->and(AchievementFacts::of($hidden)->visibleTo($this->admin)->studentName)->toBe('Fajar Ramadhan')
        ->and(AchievementFacts::of($hidden)->visibleTo($this->admin)->className)->toBe('XI-D');
});

// --- Data untuk flyer (M3-E) -----------------------------------------------------------------------------

it('takes the name and class out of the data for the flyer once the account is disabled', function (): void {
    $before = FlyerData::of($this->publication->achievement->fresh());

    expect($before->rows[0])->toBe(['label' => 'Nama', 'values' => ['Hana Lestari'], 'list' => false])
        ->and($before->rows[1])->toBe(['label' => 'Kelas', 'values' => ['XI-D'], 'list' => false])
        ->and($before->notes)->toBe([]);

    ($this->disable)();

    // Data meant for a public flyer: no viewer is asked, so nobody gets the name through this block.
    $after = FlyerData::of($this->publication->achievement->fresh());
    $text = (string) json_encode([$after->rows, $after->notes], JSON_UNESCAPED_UNICODE);

    expect($after->rows[0])->toBe(['label' => 'Nama', 'values' => ['Siswa kelas XI'], 'list' => false])
        ->and(array_column($after->rows, 'label'))->not->toContain('Kelas')
        ->and($text)->not->toContain('Hana Lestari')->not->toContain('XI-D')->not->toContain('izin')->not->toContain('mengizinkan')
        ->and($after->notes)->toBe(['Akun siswa ini nonaktif, jadi namanya tidak ditampilkan.']);
});
