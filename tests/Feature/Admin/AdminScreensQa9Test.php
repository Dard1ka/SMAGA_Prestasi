<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Admin\AuditLog;
use App\Livewire\Admin\Ringkasan;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

/*
 | QA9 (2026-10-09): Admin › Status sistem and Admin › Log audit. Fictional data only.
 */

beforeEach(function (): void {
    A::seedRoles();
    Notification::fake();
    $this->withoutVite();
    $this->admin = A::admin();
    $this->guru = A::staff(RoleName::Guru, ['name' => 'Pak Hendra Kusuma']);
    Activity::query()->delete(); // test DB only: drop the model events of the fixtures above
});

// Item 9: "Aktivitas Admin terbaru" lists what a person did, not the nightly job ---------------------------------

it('keeps the automatic archive rows out of the latest Admin activity (QA9)', function (): void {
    activity('account')->causedBy($this->admin)->performedOn($this->guru)->event('account.staff_reset')
        ->withProperties(['reason' => 'HP hilang.'])->log('account.staff_reset');

    foreach (Student::factory()->count(3)->create() as $student) {
        activity('account')->performedOn($student->user)->event('account.archived')
            ->withProperties(['automatic' => true])->log('account.archived');
    }

    $this->actingAs($this->admin);

    Livewire::test(Ringkasan::class)
        ->assertViewHas('audit', fn (Collection $audit): bool => $audit->count() === 1 && $audit->first()->event === 'account.staff_reset')
        ->assertSee('Akun staf direset')
        ->assertDontSee('Akun diarsipkan')
        ->assertSee('Aktivitas Admin terbaru, 1 entri');
});

it('still lists a new Admin made on the server, which has no causer either (QA9)', function (): void {
    // sth:create-admin writes account.admin_created without a causer (it runs on the server): a new Admin account
    // belongs in this list. Only the automatic archive rows stay out.
    activity('account')->performedOn($this->guru)->event('account.admin_created')
        ->withProperties(['via' => 'cli'])->log('account.admin_created');
    activity('account')->performedOn(Student::factory()->create()->user)->event('account.archived')
        ->withProperties(['automatic' => true])->log('account.archived');

    $this->actingAs($this->admin);

    Livewire::test(Ringkasan::class)
        ->assertViewHas('audit', fn (Collection $audit): bool => $audit->pluck('event')->all() === ['account.admin_created'])
        ->assertSee('Admin dibuat (CLI)')
        ->assertDontSee('Akun diarsipkan');
});

// Item 13: the "Pelaku" filter lists every staff account that caused a row ---------------------------------------

it('offers every non-student account that caused a row as a Pelaku, with or without a role (QA9)', function (): void {
    $replaced = A::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
    $replaced->syncRoles([]); // a replaced Kepala Sekolah keeps no role
    $newcomer = User::factory()->create(['name' => 'Ibu Laras Kinanti', 'username' => 'laras.k']); // waits for its role
    $student = H::activeStudent();

    foreach ([$this->admin, $replaced, $newcomer, $student->user] as $causer) {
        activity('account')->causedBy($causer)->event('account.password_changed')->log('account.password_changed');
    }

    $this->actingAs($this->admin);

    Livewire::test(AuditLog::class)->assertViewHas('actorOptions', fn (array $options): bool => array_key_exists((string) $this->admin->id, $options)
        && array_key_exists((string) $replaced->id, $options)
        && array_key_exists((string) $newcomer->id, $options)
        && ! array_key_exists((string) $student->user_id, $options));
});

it('keeps a student whose student record was deleted out of the Pelaku list (QA9 review)', function (): void {
    // whereDoesntHave('student') ignored a soft-deleted student row, so that account counted as staff.
    $student = H::activeStudent();
    activity('account')->causedBy($student->user)->event('account.password_changed')->log('account.password_changed');
    $student->delete();

    $this->actingAs($this->admin);

    Livewire::test(AuditLog::class)
        ->assertViewHas('actorOptions', fn (array $options): bool => ! array_key_exists((string) $student->user_id, $options));
});
