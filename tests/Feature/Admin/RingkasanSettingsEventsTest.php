<?php

declare(strict_types=1);

use App\Actions\Accounts\UpdateActivationWindow;
use App\Actions\Classrooms\CreateClassroom;
use App\Actions\Classrooms\DeleteClassroom;
use App\Actions\Classrooms\RenameClassroom;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Livewire\Admin\AuditLog;
use App\Livewire\Admin\Ringkasan;
use App\Models\Classroom;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

uses(RefreshDatabase::class);

/*
 | SV1 browser check (2026-10-30): Admin › Status sistem, "Aktivitas Admin terbaru", left out the change of the
 | activation window ("Masa aktivasi" on Status aktivasi), and with it Admin › Kelas (D-166) and "Buka pembekuan": Admin
 | actions that are audited but were not in Ringkasan::EVENTS. Fictional data only.
 */

beforeEach(function (): void {
    A::seedRoles();
    Notification::fake();
    $this->withoutVite();
    $this->admin = A::admin();
    Activity::query()->delete(); // test DB only: drop the model events of the fixtures above
});

it('lists the activation window an Admin changed, with its label (SV1)', function (): void {
    Setting::put(SettingKey::ActivationWindowDays, 14);
    app(UpdateActivationWindow::class)->handle($this->admin, 21);

    $this->actingAs($this->admin);

    Livewire::test(Ringkasan::class)
        ->assertViewHas('audit', fn (Collection $audit): bool => $audit->pluck('event')->contains('setting.activation_window'))
        ->assertSee('Masa aktivasi diubah')
        ->assertDontSee('setting.activation_window');
});

it('lists the class changes of Admin › Kelas, with the words of Log audit (SV1)', function (): void {
    $this->actingAs($this->admin);
    $kelas = Classroom::factory()->create(['name' => 'XI-B', 'level' => 11]);

    foreach ([CreateClassroom::EVENT, RenameClassroom::EVENT, DeleteClassroom::EVENT] as $event) {
        activity('classroom')->causedBy($this->admin)->performedOn($kelas)->event($event)->log($event);
    }

    Livewire::test(Ringkasan::class)
        ->assertSee('Kelas ditambahkan')
        ->assertSee('Nama kelas diganti')
        ->assertSee('Kelas dihapus')
        ->assertDontSee('classroom.');

    foreach ([CreateClassroom::EVENT, RenameClassroom::EVENT, DeleteClassroom::EVENT, 'setting.activation_window'] as $event) {
        expect(Ringkasan::EVENTS[$event])->toBe(AuditLog::EVENT_LABELS[$event]);
    }
});

it('lists an unfrozen account, an Admin-only action (SV1)', function (): void {
    $guru = A::staff(RoleName::Guru, ['name' => 'Pak Hendra Kusuma']);
    activity('account')->causedBy($this->admin)->performedOn($guru)->event('account.unfrozen')
        ->withProperties(['reason' => 'Siswa datang ke TU.'])->log('account.unfrozen');

    $this->actingAs($this->admin);

    // The words of Log audit, like every label of this list (AdminAccessTest).
    Livewire::test(Ringkasan::class)
        ->assertViewHas('audit', fn (Collection $audit): bool => $audit->pluck('event')->all() === ['account.unfrozen'])
        ->assertSee('Akun dibuka')
        ->assertDontSee('account.unfrozen');
});

it('still leaves out what an Admin did not do: the nightly archive and a Kesiswaan reopening an activation', function (): void {
    $kesiswaan = A::staff(RoleName::Kesiswaan, ['name' => 'Ibu Dwi Rahayu']);
    activity('account')->causedBy($kesiswaan)->event('account.activation_reopened')->log('account.activation_reopened');
    activity('account')->event('account.archived')->withProperties(['automatic' => true])->log('account.archived');
    app(UpdateActivationWindow::class)->handle($this->admin, 21);

    $this->actingAs($this->admin);

    Livewire::test(Ringkasan::class)
        ->assertViewHas('audit', fn (Collection $audit): bool => $audit->pluck('event')->all() === ['setting.activation_window'])
        ->assertSee('Aktivitas Admin terbaru, 1 entri');
});
