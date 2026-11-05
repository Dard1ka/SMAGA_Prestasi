<?php

declare(strict_types=1);

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\EnableAccount;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\UbahStatusSiswa;
use App\Livewire\Akun\StatusAktivasi;
use App\Models\Classroom;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 * SV5 (the lead, 2026-11-04): on Status aktivasi a student who is nonaktif, archived, frozen or expired "bisa di undo".
 * Admin only: "Aktifkan lagi" / "Ubah status" on every student row of "Daftar siswa" and of the class dialog, opening the
 * shared dialog UbahStatusSiswa. Kesiswaan sees nothing new. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();
    $this->withoutVite();

    $this->admin = A::admin();
    $this->kesiswaan = A::staff(RoleName::Kesiswaan);
    $this->x1 = Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);

    $make = function (string $name, array $user): Student {
        $student = Student::factory()->create(['classroom_id' => $this->x1->getKey()]);
        $student->user->forceFill(array_merge(['name' => $name], $user))->saveQuietly();

        return $student->fresh();
    };
    $activated = ['must_change_password' => false, 'email_verified_at' => now()];

    $this->students = [
        'aktif' => $make('Anisa Aktif', ['status' => UserStatus::Active, 'email' => 'anisa@example.test'] + $activated),
        'belum' => $make('Bayu Belum', ['initial_password_expires_at' => now()->addDays(5)]),
        'kedaluwarsa' => $make('Citra Kedaluwarsa', ['initial_password_expires_at' => now()->subDay()]),
        'dibekukan' => $make('Dimas Dibekukan', ['initial_password_expires_at' => now()->addDays(5), 'frozen_at' => now(), 'frozen_reason' => AccountControls::FROZEN_NOT_ME]),
        'nonaktif' => $make('Eka Nonaktif', ['status' => UserStatus::Disabled, 'email' => 'eka@example.test'] + $activated),
        'arsip' => $make('Fajar Arsip', ['status' => UserStatus::Archived, 'email' => 'fajar@example.test'] + $activated),
    ];
});

it('gives Admin "Aktifkan lagi" on rows that can be brought back and "Ubah status" on the others, in the list and the class dialog', function (): void {
    $this->actingAs($this->admin);

    $list = Livewire::test(StatusAktivasi::class)->call('setView', 'siswa');
    $dialog = Livewire::test(StatusAktivasi::class)->call('showClass', $this->x1->getKey());

    $listRows = collect($list->viewData('rows'))->keyBy('name');
    $dialogRows = collect($dialog->viewData('classDialog')['rows'])->keyBy('name');

    foreach ([$listRows, $dialogRows] as $rows) {
        expect($rows->map(fn (array $row): ?string => $row['change'])->all())->toBe([
            'Anisa Aktif' => 'Ubah status',
            'Bayu Belum' => 'Ubah status',
            'Citra Kedaluwarsa' => 'Aktifkan lagi',
            'Dimas Dibekukan' => 'Aktifkan lagi',
            'Eka Nonaktif' => 'Aktifkan lagi',
            'Fajar Arsip' => 'Aktifkan lagi',
        ]);
    }

    foreach ($this->students as $student) {
        $list->assertSeeHtml('wire:click="askStatus('.$student->user_id.')"');
        $dialog->assertSeeHtml('wire:click="askStatus('.$student->user_id.')"');
    }

    // "Buka ulang" and "Buka pembekuan" stay where they were.
    $list->assertSeeHtml('wire:click="openReopen('.$this->students['kedaluwarsa']->user_id.')"')
        ->assertSeeHtml('wire:click="openUnfreeze('.$this->students['dibekukan']->user_id.')"');

    $this->get(route('admin.aktivasi.index', ['tampil' => 'siswa']))
        ->assertOk()
        ->assertSee('Aktifkan lagi')
        ->assertSee('id="'.UbahStatusSiswa::DIALOG.'"', false);
});

it('gives Kesiswaan "Aktifkan lagi" and the shared dialog too (student.status, 2026-11-05)', function (): void {
    $this->actingAs($this->kesiswaan);
    $student = $this->students['nonaktif'];

    Livewire::test(StatusAktivasi::class)->call('setView', 'siswa')
        ->assertSee('Aktifkan lagi')
        ->assertSeeHtml('askStatus(')
        ->call('askStatus', $student->user_id)
        ->assertDispatchedTo(UbahStatusSiswa::class, UbahStatusSiswa::OPEN_EVENT, ids: [(int) $student->user_id]);

    $this->get(route('kesiswaan.aktivasi.index', ['tampil' => 'siswa']))
        ->assertOk()
        ->assertSee('id="'.UbahStatusSiswa::DIALOG.'"', false);
});

it('shows a Kesiswaan whose role lost student.status nothing of it and refuses its call', function (): void {
    Role::findByName(RoleName::Kesiswaan->value)->revokePermissionTo('student.status');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($this->kesiswaan->fresh());

    $list = Livewire::test(StatusAktivasi::class)->call('setView', 'siswa')
        ->assertDontSee('Aktifkan lagi')
        ->assertDontSee('Ubah status')
        ->assertDontSeeHtml('askStatus(')
        ->assertDontSeeHtml('id="'.UbahStatusSiswa::DIALOG.'"');

    expect(collect($list->viewData('rows'))->pluck('change')->filter()->all())->toBe([]);

    Livewire::test(StatusAktivasi::class)->call('showClass', $this->x1->getKey())
        ->assertDontSeeHtml('askStatus(')
        ->assertDontSee('Aktifkan lagi');

    $list->call('askStatus', $this->students['nonaktif']->user_id)->assertForbidden();

    $this->get(route('kesiswaan.aktivasi.index', ['tampil' => 'siswa']))
        ->assertOk()
        ->assertDontSee('Aktifkan lagi')
        ->assertDontSee('id="'.UbahStatusSiswa::DIALOG.'"', false);
});

it('opens the shared dialog with "Aktif" preselected for a row that can be brought back', function (string $state, ?string $target): void {
    $this->actingAs($this->admin);
    $student = $this->students[$state];

    Livewire::test(StatusAktivasi::class)
        ->call('setView', 'siswa')
        ->call('askStatus', $student->user_id)
        // (assertDispatchedTo() has its own $target parameter, so the event's `target` is checked by assertDispatched().)
        ->assertDispatched(UbahStatusSiswa::OPEN_EVENT, ids: [(int) $student->user_id], target: $target)
        ->assertDispatchedTo(UbahStatusSiswa::class, UbahStatusSiswa::OPEN_EVENT, ids: [(int) $student->user_id]);

    Livewire::test(UbahStatusSiswa::class)
        ->call('open', [(int) $student->user_id], $target)
        ->assertSet('statusTarget', $target ?? '')
        ->assertDispatched('sth-modal-open', id: UbahStatusSiswa::DIALOG);
})->with([
    'aktif' => ['aktif', null],
    'belum' => ['belum', null],
    'kedaluwarsa' => ['kedaluwarsa', UbahStatusSiswa::TARGET_ACTIVE],
    'dibekukan' => ['dibekukan', UbahStatusSiswa::TARGET_ACTIVE],
    'nonaktif' => ['nonaktif', UbahStatusSiswa::TARGET_ACTIVE],
    'arsip' => ['arsip', UbahStatusSiswa::TARGET_ACTIVE],
]);

it('refuses a staff or unknown id with 404', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(StatusAktivasi::class)->call('askStatus', $this->kesiswaan->getKey())->assertNotFound();
    Livewire::test(StatusAktivasi::class)->call('askStatus', 999999)->assertNotFound();
});

it('redraws the lists and the counts after the shared dialog changed a student', function (): void {
    $this->actingAs($this->admin);
    $eka = $this->students['nonaktif'];
    $eka->forceFill(['archive_at' => CarbonImmutable::now('Asia/Jakarta')->addYear()->toDateString()])->save();

    $screen = Livewire::test(StatusAktivasi::class)->call('setView', 'siswa');
    expect($screen->viewData('totals')['nonaktif'])->toBe(1)
        ->and($screen->viewData('totals')['aktif'])->toBe(1);

    app(EnableAccount::class)->handle($this->admin, $eka->user, 'Salah menonaktifkan akun.');

    $screen->dispatch(UbahStatusSiswa::DONE_EVENT);
    $row = collect($screen->viewData('rows'))->firstWhere('name', 'Eka Nonaktif');

    expect($screen->viewData('totals')['nonaktif'])->toBe(0)
        ->and($screen->viewData('totals')['aktif'])->toBe(2)
        ->and($row['state'])->toBe(AccountControls::STATE_ACTIVE)
        ->and($row['change'])->toBe('Ubah status');
});
