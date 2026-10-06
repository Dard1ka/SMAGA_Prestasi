<?php

declare(strict_types=1);

use App\Actions\Publications\AchievementFacts;
use App\Actions\Publications\FlyerData;
use App\Actions\Students\UpdateConsent;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
| Withdrawn publish consent (SECURITY §3i, §3m-7): Humas (no `student.view`) loses the student's name/class on
| Arsip, Apresiasi and Jadwal; the row stays with the chip "Izin dicabut" so the post can be taken down.
| Kesiswaan and Admin (`student.view`) keep the name on the screens they already open.
| Flyers (M3-E): STH generates none any more. A flyer file from before keeps its FilePolicy rule (Humas while
| consent stands, Admin also after a withdrawal, D-75), but no Humas screen links to it.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    config(['fortify.sth.require_staff_2fa' => false]);

    $this->humas = HumasFixtures::humas();
    $this->admin = AuthTestHelpers::staff(RoleName::Admin);

    // A published post of Hana (XI-4) with a flyer file from before M3-E, also on an upcoming appreciation event.
    $this->publication = HumasFixtures::publication(
        publicationState: ['status' => PublicationStatus::Dipublikasikan, 'published_at' => now()->subDays(3), 'channels' => ['instagram']],
        studentName: 'Hana Lestari',
        competitionName: 'Kimia Jateng 2026',
    );
    $this->flyer = HumasFixtures::legacyFlyer($this->publication, $this->humas);

    $this->event = HumasFixtures::event();
    $appreciation = new Appreciation(['achievement_id' => $this->publication->achievement_id, 'appreciation_event_id' => $this->event->getKey()]);
    $appreciation->forceFill(['status' => AppreciationStatus::Dijadwalkan])->save();

    $this->withdraw = function (): void {
        app(UpdateConsent::class)->handle($this->admin, $this->publication->achievement->student, false, false, 'Formulir pencabutan dari orang tua.');
    };
});

// --- Flyer files made before M3-E (FilePolicy → FileController answers 404) ------------------------------

it('still serves a flyer made before M3-E to publication.manage holders while consent stands', function (): void {
    $otherHumas = AuthTestHelpers::staff(RoleName::Humas, ['email' => 'humas.dua@example.test']);

    $this->actingAs($this->humas)->get(route('files.show', $this->flyer))->assertOk();
    $this->actingAs($otherHumas)->get(route('files.show', $this->flyer))->assertOk();
    $this->actingAs($this->admin)->get(route('files.show', $this->flyer))->assertOk();
    $this->actingAs(AuthTestHelpers::staff(RoleName::Kesiswaan))->get(route('files.show', $this->flyer))->assertNotFound();
    $this->actingAs(AuthTestHelpers::staff(RoleName::Guru))->get(route('files.show', $this->flyer))->assertNotFound();
});

it('answers 404 for the flyer once consent is withdrawn, also for the Humas user who generated it; Admin keeps it (D-75)', function (): void {
    ($this->withdraw)();

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

it('serves the flyer again when consent is given back', function (): void {
    ($this->withdraw)();
    app(UpdateConsent::class)->handle($this->admin, $this->publication->achievement->student->fresh(), true, true, 'Formulir persetujuan baru.');

    $this->actingAs($this->humas)->get(route('files.show', $this->flyer))->assertOk();
});

it('does not serve a flyer that no publication uses, even to its uploader', function (): void {
    Publication::query()->whereKey($this->publication->getKey())->update(['flyer_file_id' => null]);

    expect($this->humas->can('view', $this->flyer->fresh()))->toBeFalse()
        ->and($this->admin->can('view', $this->flyer->fresh()))->toBeFalse();
    $this->actingAs($this->humas)->get(route('files.show', $this->flyer))->assertNotFound();
});

// --- Arsip -----------------------------------------------------------------------------------------------

it('shows name and class on Arsip while consent stands, with "Lihat" and no flyer column or link (M3-E)', function (): void {
    Livewire::actingAs($this->humas)
        ->test(Arsip::class)
        ->assertSee('Kimia Jateng 2026')
        ->assertSee('Hana Lestari')
        ->assertSee('XI-4')
        ->assertSeeInOrder(['Lomba', 'Siswa', 'Capaian', 'Kanal', 'Terbit'])
        ->assertDontSee('Flyer')
        ->assertDontSee(route('files.show', $this->flyer), false)
        // "Lihat" opens the panel with the caption and the data for the flyer.
        ->assertSeeHtml('href="'.route('humas.beranda', ['pilih' => $this->publication->getKey()]).'"')
        ->assertSee('Lihat')
        ->assertDontSee('Izin dicabut')
        ->assertDontSee('Siswa kelas XI');

    Livewire::withQueryParams(['pilih' => $this->publication->getKey()])
        ->actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertSee('Sudah terbit')
        ->assertSee('Data untuk flyer')
        ->assertSee('Hana Lestari');
});

it('hides name and class on Arsip once consent is withdrawn, and offers no "Lihat"', function (): void {
    ($this->withdraw)();

    Livewire::actingAs($this->humas)
        ->test(Arsip::class)
        ->assertSee('Kimia Jateng 2026')
        ->assertSee('Siswa kelas XI')
        ->assertSee('Izin dicabut')
        ->assertDontSee('Hana Lestari')
        ->assertDontSee('XI-4')
        ->assertDontSee('pilih='.$this->publication->getKey(), false)
        ->assertDontSee(route('files.show', $this->flyer), false);

    // The address typed by hand answers 404: the queue no longer holds this publication.
    Livewire::withQueryParams(['pilih' => $this->publication->getKey()])
        ->actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertNotFound();
});

it('does not find a withdrawn student by name on Arsip, only by lomba', function (): void {
    ($this->withdraw)();

    Livewire::actingAs($this->humas)
        ->test(Arsip::class)
        ->set('q', 'hana')
        ->assertSee('Tidak ada publikasi yang cocok')
        ->assertDontSee('Kimia Jateng 2026')
        ->set('q', 'kimia')
        ->assertSee('Kimia Jateng 2026')
        ->assertDontSee('Hana Lestari');
});

it('keeps the name on Arsip for Admin (student.view), with the chip; the old flyer is not linked there (M3-E)', function (): void {
    ($this->withdraw)();

    Livewire::actingAs($this->admin)
        ->test(Arsip::class)
        ->assertSee('Hana Lestari')
        ->assertSee('XI-4')
        ->assertSee('Izin dicabut')
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

it('hides name and class on Apresiasi once consent is withdrawn', function (): void {
    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->assertSee('Hana Lestari')
        ->assertSee('XI-4')
        ->assertDontSee('Izin dicabut');

    ($this->withdraw)();

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->assertSee('Kimia Jateng 2026')
        ->assertSee('Siswa kelas XI')
        ->assertSee('Izin dicabut')
        ->assertDontSee('Hana Lestari')
        ->assertDontSee('XI-4');
});

it('keeps the name on Apresiasi for Kesiswaan and Admin (student.view)', function (RoleName $role): void {
    ($this->withdraw)();

    $user = $role === RoleName::Admin ? $this->admin : AuthTestHelpers::staff($role);

    Livewire::actingAs($user)
        ->test(Apresiasi::class)
        ->assertSee('Hana Lestari')
        ->assertSee('XI-4')
        ->assertSee('Izin dicabut')
        ->assertDontSee('Siswa kelas XI');
})->with([RoleName::Kesiswaan, RoleName::Admin]);

// --- Jadwal ----------------------------------------------------------------------------------------------

it('hides name and class in the appreciation agenda of Jadwal once consent is withdrawn', function (): void {
    Livewire::actingAs($this->humas)
        ->test(Jadwal::class)
        ->assertSee('Hana Lestari')
        ->assertDontSee('Izin dicabut');

    ($this->withdraw)();

    Livewire::actingAs($this->humas)
        ->test(Jadwal::class)
        ->assertSee('Kimia Jateng 2026')
        ->assertSee('Siswa kelas XI')
        ->assertSee('Izin dicabut')
        ->assertDontSee('Hana Lestari')
        ->assertDontSee('XI-4');
});

it('keeps the name on Jadwal for Admin (student.view)', function (): void {
    ($this->withdraw)();

    Livewire::actingAs($this->admin)
        ->test(Jadwal::class)
        ->assertSee('Hana Lestari')
        ->assertSee('Izin dicabut')
        ->assertDontSee('Siswa kelas XI');
});

// --- Neutral label ---------------------------------------------------------------------------------------

it('uses the neutral labels of the public pages for a viewer without student.view', function (): void {
    $named = HumasFixtures::achievement(studentName: 'Raka Pradipta');
    $hidden = HumasFixtures::achievement(['publish_consent' => false], studentName: 'Fajar Ramadhan');
    $alumni = HumasFixtures::achievement(['publish_consent' => false], studentName: 'Bima Satria Wicaksono');
    $alumni->student->user->forceFill(['status' => UserStatus::Archived])->save();

    $facts = AchievementFacts::of($named);
    $masked = AchievementFacts::of($hidden)->visibleTo($this->humas);

    expect($facts->visibleTo($this->humas))->toBe($facts)
        ->and($masked->studentName)->toBe('Siswa kelas XI')
        ->and($masked->className)->toBeNull()
        ->and($masked->photoConsent)->toBeFalse()
        ->and($masked->publishConsent)->toBeFalse()
        ->and($masked->competitionName)->toBe('OPSI 2026')
        ->and(AchievementFacts::of($alumni->fresh())->visibleTo($this->humas)->studentName)->toBe('Alumni')
        ->and(AchievementFacts::of($hidden)->visibleTo($this->admin)->studentName)->toBe('Fajar Ramadhan')
        ->and(AchievementFacts::of($hidden)->visibleTo($this->admin)->className)->toBe('XI-4');
});

// --- Data untuk flyer (M3-E) -----------------------------------------------------------------------------

it('takes the name and class out of the data for the flyer once consent is withdrawn', function (): void {
    $before = FlyerData::of($this->publication->achievement->fresh());

    expect($before->rows[0])->toBe(['label' => 'Nama', 'values' => ['Hana Lestari'], 'list' => false])
        ->and($before->rows[1])->toBe(['label' => 'Kelas', 'values' => ['XI-4'], 'list' => false])
        ->and($before->notes)->toBe([]);

    ($this->withdraw)();

    // Data meant for a public flyer: no viewer is asked, so nobody gets the name through this block.
    $after = FlyerData::of($this->publication->achievement->fresh());
    $text = (string) json_encode([$after->rows, $after->notes], JSON_UNESCAPED_UNICODE);

    expect($after->rows[0])->toBe(['label' => 'Nama', 'values' => ['Siswa kelas XI'], 'list' => false])
        ->and(array_column($after->rows, 'label'))->not->toContain('Kelas')
        ->and($text)->not->toContain('Hana Lestari')->not->toContain('XI-4')
        ->and($after->notes)->toBe([
            'Siswa ini tidak mengizinkan namanya dipublikasikan.',
            'Siswa ini tidak mengizinkan fotonya dipakai.',
        ]);
});
