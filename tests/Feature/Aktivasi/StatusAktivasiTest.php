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
| K2 reopen, Admin unfreeze + window setting; role × route matrix. M1-E — the class dialog behind "Lihat"
| (`?kelas=`), the list filter `?kelas_daftar=`. Fictional data only.
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
        ->set('daftarKelas', $this->x1->getKey())
        ->assertSee('Dimas Saputra')->assertDontSee('Raka Pratama')
        ->call('resetFilters')
        ->assertSet('daftarKelas', '')
        ->set('q', $nis)
        ->assertSee('Raka Pratama')->assertDontSee('Salsa Maharani');
});

// --- M1-E: "Lihat" opens the class in a dialog (lead 2026-10-04, item 16) -------------------------------

it('opens the students of a class in a dialog instead of filtering the list at the bottom', function (): void {
    $this->actingAs($this->kesiswaan);
    $inDialog = fn (Student $s): string => 'wire:key="kelas-siswa-'.$s->user_id.'"';
    $inList = fn (Student $s): string => 'wire:key="siswa-'.$s->user_id.'"';

    Livewire::test(StatusAktivasi::class)
        ->assertSet('kelas', '')
        ->assertDontSeeHtml('data-sth-autoopen')
        ->assertDontSeeHtml($inDialog($this->raka))
        ->call('showClass', $this->xi4->getKey())
        ->assertDispatched('sth-modal-open', id: 'aktivasi-kelas')
        ->assertSet('kelas', $this->xi4->getKey())
        ->assertSee('Kelas XI-4')
        ->assertSeeHtml($inDialog($this->nadia))
        ->assertSeeHtml($inDialog($this->raka))
        ->assertSeeHtml($inDialog($this->salsa))
        ->assertDontSeeHtml($inDialog($this->dimas))
        // Masked in the dialog too.
        ->assertSee(ActivationReport::maskNis((string) $this->raka->nis))
        ->assertDontSee((string) $this->raka->nis)
        ->assertDontSee('nadia.anindya@example.test')
        // The list at the bottom keeps every class: "Lihat" no longer touches it.
        ->assertSet('daftarKelas', '')
        ->assertSeeHtml($inList($this->dimas))
        ->assertSeeHtml($inList($this->raka))
        // Tabs only for the states this class has.
        ->assertSeeHtml("setClassStatus('kedaluwarsa')")
        ->assertDontSeeHtml("setClassStatus('dibekukan')")
        ->call('setClassStatus', AccountControls::STATE_EXPIRED)
        ->assertSeeHtml($inDialog($this->salsa))
        ->assertDontSeeHtml($inDialog($this->raka))
        ->assertSeeHtml($inList($this->raka))
        ->call('setClassStatus', 'tidak-ada')
        ->assertSet('kelasStatus', '')
        ->assertSeeHtml($inDialog($this->raka))
        // Another class replaces the content and resets the tab.
        ->call('setClassStatus', AccountControls::STATE_ACTIVE)
        ->call('showClass', $this->x1->getKey())
        ->assertSet('kelasStatus', '')
        ->assertSee('Kelas X-1')
        ->assertSeeHtml($inDialog($this->dimas))
        ->assertDontSeeHtml($inDialog($this->nadia))
        ->call('closeClass')
        ->assertDispatched('sth-modal-close', id: 'aktivasi-kelas')
        ->assertSet('kelas', '')
        ->assertDontSeeHtml($inDialog($this->dimas));
});

it('resets ?kelas= however the class dialog is closed, not only with Tutup (QA5 retest, #63)', function (): void {
    $this->actingAs($this->kesiswaan)
        ->get(route('kesiswaan.aktivasi.index'))
        ->assertOk()
        ->assertSee('id="aktivasi-kelas"', false)
        ->assertSee('data-sth-on-close="closeClass"', false);
});

it('opens the dialog on page load with ?kelas= and drops an unknown class', function (): void {
    $this->actingAs($this->kesiswaan);
    $empty = Classroom::factory()->create(['name' => 'X-9', 'level' => 10]);

    Livewire::withQueryParams(['kelas' => $this->xi4->getKey()])
        ->test(StatusAktivasi::class)
        ->assertSet('kelas', $this->xi4->getKey())
        ->assertSeeHtml('data-sth-autoopen')
        ->assertSee('Kelas XI-4')
        ->assertSeeHtml('wire:key="kelas-siswa-'.$this->salsa->user_id.'"')
        ->assertSet('daftarKelas', '');

    foreach (['tidak-ada', $empty->getKey()] as $unknown) {
        Livewire::withQueryParams(['kelas' => $unknown])
            ->test(StatusAktivasi::class)
            ->assertSet('kelas', '')
            ->assertDontSeeHtml('data-sth-autoopen')
            ->assertDontSeeHtml('wire:key="kelas-siswa-');
    }

    Livewire::test(StatusAktivasi::class)->call('showClass', 'tidak-ada')->assertNotFound();
    Livewire::test(StatusAktivasi::class)->call('showClass', $empty->getKey())->assertNotFound();

    // Both routes of the shared component.
    $this->get(route('kesiswaan.aktivasi.index', ['kelas' => $this->xi4->getKey()]))
        ->assertOk()->assertSee('data-sth-autoopen', false)->assertSee('Kelas XI-4');
    $this->actingAs($this->admin)->get(route('admin.aktivasi.index', ['kelas' => $this->x1->getKey()]))
        ->assertOk()->assertSee('data-sth-autoopen', false)->assertSee('Kelas X-1');
    $this->actingAs($this->admin)->get(route('admin.aktivasi.index'))
        ->assertOk()->assertDontSee('data-sth-autoopen', false);
});

it('keeps the list filter in its own address parameter and forgets the dialog when the list is used', function (): void {
    $this->actingAs($this->kesiswaan);

    Livewire::withQueryParams(['kelas_daftar' => $this->x1->getKey(), 'status' => AccountControls::STATE_FROZEN])
        ->test(StatusAktivasi::class)
        ->assertSet('daftarKelas', $this->x1->getKey())
        ->assertSet('kelas', '')
        ->assertDontSeeHtml('data-sth-autoopen')
        ->assertSeeHtml('wire:key="siswa-'.$this->dimas->user_id.'"')
        ->assertDontSeeHtml('wire:key="siswa-'.$this->raka->user_id.'"')
        ->call('showClass', $this->xi4->getKey())
        ->assertSet('kelas', $this->xi4->getKey())
        // The dialog was closed with Esc or the X: the next use of the list drops its class.
        ->call('setStatus', '')
        ->assertSet('kelas', '')
        ->assertSet('daftarKelas', $this->x1->getKey())
        ->call('showClass', $this->xi4->getKey())
        ->set('q', 'Dimas')
        ->assertSet('kelas', '');
});

it('shows a class in steps of 40 inside the dialog', function (): void {
    $this->actingAs($this->kesiswaan);
    $big = Classroom::factory()->create(['name' => 'X-2', 'level' => 10]);
    Student::factory()->count(45)->create(['classroom_id' => $big->getKey()]);

    expect(fn () => Livewire::test(StatusAktivasi::class)->set('kelasLimit', 5000))->toThrow(Exception::class);

    $component = Livewire::test(StatusAktivasi::class)
        ->call('showClass', $big->getKey())
        ->assertSee('45 siswa, 40 tampil.')
        ->assertSeeHtml('wire:click="showMoreClass"');

    expect(substr_count($component->html(), 'wire:key="kelas-siswa-'))->toBe(40);

    $component->call('showMoreClass')
        ->assertSee('45 siswa.')
        ->assertDontSeeHtml('wire:click="showMoreClass"');

    expect(substr_count($component->html(), 'wire:key="kelas-siswa-'))->toBe(45);
});

it('reopens activation from a row of the class dialog and keeps the dialog on that class', function (): void {
    $this->actingAs($this->kesiswaan);

    Livewire::test(StatusAktivasi::class)
        ->call('showClass', $this->xi4->getKey())
        ->assertSeeHtml('wire:click="openReopen('.$this->salsa->user_id.')"')
        ->call('openReopen', $this->salsa->user_id)
        ->assertDispatched('sth-modal-open', id: 'aktivasi-aksi')
        ->set('reason', 'Siswa sakit saat aktivasi kelas.')
        ->call('submitAction')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-close', id: 'aktivasi-aksi')
        ->assertNotDispatched('sth-modal-close', id: 'aktivasi-kelas')
        ->assertSet('kelas', $this->xi4->getKey())
        ->assertSeeHtml('wire:key="kelas-siswa-'.$this->salsa->user_id.'"')
        ->assertDontSeeHtml("setClassStatus('kedaluwarsa')");

    expect(AccountControls::initialPasswordExpired(User::query()->findOrFail($this->salsa->user_id)))->toBeFalse();
});

it('refuses the class dialog to every other role', function (RoleName $role, bool $holdsStudentView): void {
    $this->actingAs(A::staff($role));

    // The routes are role:kesiswaan|admin (and role:admin), as before.
    $this->get(route('kesiswaan.aktivasi.index', ['kelas' => $this->xi4->getKey()]))->assertForbidden();
    $this->get(route('admin.aktivasi.index', ['kelas' => $this->xi4->getKey()]))->assertForbidden();

    // The component itself asks for student.view.
    if (! $holdsStudentView) {
        Livewire::test(StatusAktivasi::class)->assertForbidden();
        Livewire::withQueryParams(['kelas' => $this->xi4->getKey()])->test(StatusAktivasi::class)->assertForbidden();
    }
})->with([
    'humas' => [RoleName::Humas, false],
    'guru' => [RoleName::Guru, false],
    'tata_usaha' => [RoleName::TataUsaha, true],
    'kepala_sekolah' => [RoleName::KepalaSekolah, true],
]);

it('refuses the class dialog to a student', function (): void {
    $student = User::query()->findOrFail($this->nadia->user_id);

    $this->actingAs($student)->get(route('kesiswaan.aktivasi.index', ['kelas' => $this->xi4->getKey()]))->assertForbidden();
    Livewire::actingAs($student)->test(StatusAktivasi::class)->assertForbidden();
});

it('exports the list filtered by its own class filter', function (): void {
    $this->actingAs($this->kesiswaan);
    session(A::passwordConfirmed());

    $component = Livewire::test(StatusAktivasi::class)
        ->call('showClass', $this->xi4->getKey())
        ->set('daftarKelas', $this->x1->getKey())
        ->call('export');

    $csv = base64_decode((string) ($component->effects['download']['content'] ?? ''));
    $log = Activity::query()->where('event', 'account.activation_report_exported')->sole();

    expect($csv)->toContain('Dimas Saputra')->not->toContain('Raka Pratama')
        ->and($log->properties['rows'])->toBe(2)
        ->and($log->properties['filters'])->toBe(['kelas' => $this->x1->getKey()]);
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

// --- M2-B: "Buka ulang" never shortens a deadline (QA 2026-10-05, Q2-09) -------------------------------

it('offers "Buka ulang" only where it changes something and says what will happen', function (): void {
    $this->travelTo(now()->startOfSecond());
    $this->actingAs($this->kesiswaan);

    $soon = Student::factory()->create(['classroom_id' => $this->xi4->getKey()]);
    $soon->user->forceFill(['name' => 'Citra Ayu Lestari', 'initial_password_expires_at' => now()->addHours(20)])->saveQuietly();
    $button = fn (Student $s): string => 'wire:click="openReopen('.$s->user_id.')"';
    $rakaDeadline = AccountControls::date(User::query()->findOrFail($this->raka->user_id), 'initial_password_expires_at');

    Livewire::test(StatusAktivasi::class)
        ->assertSeeHtml($button($this->salsa))    // expired
        ->assertSeeHtml($button($soon))           // 20 hours left: a reopen gives more
        ->assertSeeHtml($button($this->dimas))    // frozen after failed logins
        ->assertDontSeeHtml($button($this->raka)) // 5 days left: a reopen would give less
        ->assertDontSeeHtml($button($this->nadia))
        // The class dialog draws the same buttons.
        ->call('showClass', $this->xi4->getKey())
        ->assertSeeHtml('wire:key="kelas-siswa-'.$this->raka->user_id.'"')
        ->assertDontSeeHtml($button($this->raka))
        // A stale list or a forged call: the reason, no dialog.
        ->call('openReopen', $this->raka->user_id)
        ->assertNotDispatched('sth-modal-open', id: 'aktivasi-aksi')
        ->assertDispatched('sth-toast', tone: 'error')
        ->assertSet('targetId', null)
        ->call('openReopen', $soon->user_id)
        ->assertDispatched('sth-modal-open', id: 'aktivasi-aksi')
        ->assertSee('Dibuka lagi sampai')
        ->assertSee(now()->addHours(72)->translatedFormat('j M Y H:i'))
        ->assertDontSee('Buka ulang 72 jam');

    expect(AccountControls::date(User::query()->findOrFail($this->raka->user_id), 'initial_password_expires_at')->equalTo($rakaDeadline))->toBeTrue()
        ->and(Activity::query()->where('event', 'account.activation_reopened')->exists())->toBeFalse();
});

it('needs a reason of at least 10 characters for "Buka ulang" on the screen', function (): void {
    $this->travelTo(now()->startOfSecond());
    $this->actingAs($this->kesiswaan);

    Livewire::test(StatusAktivasi::class)
        ->call('openReopen', $this->salsa->user_id)
        ->assertSee('Minimal 10 karakter.')
        ->set('reason', 'ab')
        ->call('submitAction')
        ->assertHasErrors('reason')
        ->assertSee('Alasan minimal 10 karakter.')
        ->assertNotDispatched('sth-modal-close', id: 'aktivasi-aksi')
        // Spaces do not make a reason longer.
        ->set('reason', 'ab                  ')
        ->call('submitAction')
        ->assertHasErrors('reason')
        ->set('reason', 'Siswa izin sakit, aktivasi susulan.')
        ->call('submitAction')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Aktivasi dibuka lagi sampai '.now()->addHours(72)->translatedFormat('j M Y H:i').'.', tone: 'success');

    expect(Activity::query()->where('event', 'account.activation_reopened')->count())->toBe(1)
        ->and(AccountControls::initialPasswordExpired(User::query()->findOrFail($this->salsa->user_id)))->toBeFalse();
});

it('lifts a freeze from the screen and tells that a longer deadline stays', function (): void {
    $this->travelTo(now()->startOfSecond());
    $this->actingAs($this->kesiswaan);
    $deadline = now()->addDays(9);
    User::query()->findOrFail($this->dimas->user_id)->forceFill(['initial_password_expires_at' => $deadline])->saveQuietly();

    Livewire::test(StatusAktivasi::class)
        ->call('openReopen', $this->dimas->user_id)
        ->assertDispatched('sth-modal-open', id: 'aktivasi-aksi')
        ->assertSee('Pembekuan dibuka. Batas aktivasi tetap')
        ->assertSee($deadline->translatedFormat('j M Y H:i'))
        ->set('reason', 'Sudah dicek wali kelas.')
        ->call('submitAction')
        ->assertHasNoErrors();

    $dimas = User::query()->findOrFail($this->dimas->user_id);
    expect(AccountControls::isFrozen($dimas))->toBeFalse()
        ->and(AccountControls::date($dimas, 'initial_password_expires_at')->equalTo($deadline))->toBeTrue();
});

it('refuses "Buka ulang" to roles without student.import', function (RoleName $role): void {
    $this->actingAs(A::staff($role));

    Livewire::test(StatusAktivasi::class)->call('openReopen', $this->salsa->user_id)->assertForbidden();

    expect(AccountControls::initialPasswordExpired(User::query()->findOrFail($this->salsa->user_id)))->toBeTrue();
})->with([RoleName::TataUsaha, RoleName::KepalaSekolah]);

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
