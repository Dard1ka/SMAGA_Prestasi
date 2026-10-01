<?php

declare(strict_types=1);

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\ActivationReport;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Akun\StatusAktivasi;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| B1 — Status aktivasi (kesiswaan.aktivasi.index, admin.aktivasi.index): K9 report, filters, CSV export,
| K2 reopen, Admin unfreeze + window setting; role × route matrix. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();

    $this->admin = A::admin();
    $this->kesiswaan = A::staff(RoleName::Kesiswaan);

    $this->xi4 = Classroom::factory()->level(11)->create(['name' => 'XI-4']);
    $this->x1 = Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);

    $make = function (Classroom $class, string $name, array $user = []): Student {
        $student = Student::factory()->create(['classroom_id' => $class->getKey()]);
        $student->user->forceFill(array_merge(['name' => $name], $user))->saveQuietly();

        return $student;
    };

    $this->nadia = $make($this->xi4, 'Nadia Putri Anindya', [
        'status' => UserStatus::Active, 'must_change_password' => false, 'email' => 'nadia.anindya@example.test', 'email_verified_at' => now(),
        'activated_at' => now()->setTimezone('Asia/Jakarta')->next('Saturday')->setTime(21, 0), 'last_login_at' => now(),
    ]);
    $this->raka = $make($this->xi4, 'Raka Pratama', ['initial_password_expires_at' => now()->addDays(5)]);
    $this->salsa = $make($this->xi4, 'Salsa Maharani', ['initial_password_expires_at' => now()->subDay()]);
    $this->dimas = $make($this->x1, 'Dimas Saputra', ['frozen_at' => now(), 'frozen_reason' => AccountControls::FROZEN_FAILED_ATTEMPTS, 'failed_activation_attempts' => 10]);
    $this->bayu = $make($this->x1, '=HYPERLINK("http://evil.example","klik")', ['status' => UserStatus::Archived]);
});

it('lets Kesiswaan and Admin open the report and refuses every other role', function (): void {
    $this->get(route('kesiswaan.aktivasi.index'))->assertRedirect(route('login'));

    $this->actingAs($this->kesiswaan)->get(route('kesiswaan.aktivasi.index'))->assertOk()->assertSee('Per kelas');
    $this->actingAs($this->kesiswaan)->get(route('admin.aktivasi.index'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.aktivasi.index'))->assertOk()->assertSee('Jendela aktivasi');
    $this->actingAs($this->admin)->get(route('kesiswaan.aktivasi.index'))->assertOk();

    foreach ([RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas, RoleName::Guru] as $role) {
        $staff = A::staff($role);
        $this->actingAs($staff)->get(route('kesiswaan.aktivasi.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.aktivasi.index'))->assertForbidden();
    }

    $this->actingAs(User::query()->findOrFail($this->nadia->user_id))->get(route('kesiswaan.aktivasi.index'))->assertForbidden();
});

it('derives the K9 state, flags and per-class counts', function (): void {
    $rows = app(ActivationReport::class)->rows()->keyBy('name');

    expect($rows['Nadia Putri Anindya']['state'])->toBe(AccountControls::STATE_ACTIVE)
        ->and($rows['Nadia Putri Anindya']['flags'])->toContain(ActivationReport::FLAG_OUTSIDE_HOURS)
        ->and($rows['Nadia Putri Anindya']['email'])->toBe('n•••@example.test')
        ->and($rows['Raka Pratama']['state'])->toBe(AccountControls::STATE_PENDING)
        ->and($rows['Salsa Maharani']['state'])->toBe(AccountControls::STATE_EXPIRED)
        ->and($rows['Dimas Saputra']['state'])->toBe(AccountControls::STATE_FROZEN)
        ->and($rows['Dimas Saputra']['flags'])->toContain('Gagal masuk 10 kali');

    $summary = app(ActivationReport::class)->summary()->keyBy('classroom');
    expect($summary->keys()->all())->toBe(['X-1', 'XI-4'])
        ->and($summary['XI-4']['total'])->toBe(3)
        ->and($summary['XI-4']['counts']['aktif'])->toBe(1)
        ->and($summary['XI-4']['activePct'])->toBe(33)
        ->and($summary['X-1']['counts']['arsip'])->toBe(1);
});

it('shows masked NIS and email only, and filters by class, status and exact NIS', function (): void {
    $this->actingAs($this->kesiswaan);
    $nis = (string) $this->raka->nis;

    Livewire::test(StatusAktivasi::class)
        ->assertSee(ActivationReport::maskNis($nis))
        ->assertDontSee($nis)
        ->assertSee('n•••@example.test')
        ->assertDontSee('nadia.anindya@example.test')
        ->call('setStatus', AccountControls::STATE_EXPIRED)
        ->assertSee('Salsa Maharani')->assertDontSee('Raka Pratama')
        ->call('setStatus', '')
        ->set('kelas', $this->x1->getKey())
        ->assertSee('Dimas Saputra')->assertDontSee('Raka Pratama')
        ->call('resetFilters')
        ->set('q', $nis)
        ->assertSee('Raka Pratama')->assertDontSee('Salsa Maharani');
});

it('exports a formula-safe CSV after a recent password confirmation, audited', function (): void {
    $this->actingAs($this->kesiswaan);

    Livewire::test(StatusAktivasi::class)->call('export')->assertRedirect(route('password.confirm'));
    expect(Activity::query()->where('event', 'account.activation_report_exported')->exists())->toBeFalse();

    session(A::passwordConfirmed());

    $component = Livewire::test(StatusAktivasi::class)->call('export');
    $download = $component->effects['download'] ?? null;
    expect($download)->not->toBeNull();

    $csv = base64_decode((string) $download['content']);
    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->toContain('Kelas;Nama;NIS;Status')
        ->toContain('"\'=HYPERLINK(""http://evil.example"",""klik"")"')
        ->toContain('Kedaluwarsa')
        ->not->toContain((string) $this->raka->nis)
        ->not->toContain('nadia.anindya@example.test');

    expect(Activity::query()->where('event', 'account.activation_report_exported')->sole()->properties['rows'])->toBe(5);
});

it('reopens activation from the report (Kesiswaan) with a reason', function (): void {
    $this->actingAs($this->kesiswaan);

    Livewire::test(StatusAktivasi::class)
        ->call('openReopen', $this->salsa->user_id)
        ->assertDispatched('sth-modal-open', id: 'aktivasi-aksi')
        ->call('submitAction')
        ->assertHasErrors('reason')
        ->set('reason', 'Siswa sakit saat aktivasi kelas.')
        ->call('submitAction')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast');

    expect(AccountControls::initialPasswordExpired(User::query()->findOrFail($this->salsa->user_id)))->toBeFalse()
        ->and(Activity::query()->where('event', 'account.activation_reopened')->sole()->causer_id)->toBe($this->kesiswaan->getKey());
});

it('keeps unfreeze and the window setting Admin-only and the target locked', function (): void {
    $this->actingAs($this->kesiswaan);

    Livewire::test(StatusAktivasi::class)->call('openUnfreeze', $this->dimas->user_id)->assertForbidden();
    Livewire::test(StatusAktivasi::class)->set('windowDays', '20')->call('saveWindow')->assertForbidden();
    expect(fn () => Livewire::test(StatusAktivasi::class)->set('targetId', $this->dimas->user_id))->toThrow(Exception::class);

    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(StatusAktivasi::class)
        ->call('openUnfreeze', $this->dimas->user_id)
        ->set('reason', 'Pemilik datang ke TU.')
        ->call('submitAction')
        ->assertHasNoErrors();

    expect(AccountControls::isFrozen(User::query()->findOrFail($this->dimas->user_id)))->toBeFalse();

    Livewire::test(StatusAktivasi::class)
        ->set('windowDays', '31')->call('saveWindow')->assertHasErrors('windowDays')
        ->set('windowDays', '20')->call('saveWindow')->assertHasNoErrors();

    expect(app(AccountControls::class)->windowDays())->toBe(20);
});
