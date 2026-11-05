<?php

declare(strict_types=1);

use App\Actions\Trash\TrashStudents;
use App\Enums\RoleName;
use App\Livewire\Admin\HapusSiswa;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\TrashEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 * SV6, D-185 (the lead, 5 Nov 2026): the shared dialog "Hapus siswa?" of Admin › Kelas and Status aktivasi
 * (App\Livewire\Admin\HapusSiswa). Admin and Kesiswaan (student.trash) put students into the Sampah; the Sampah and
 * "Pulihkan" stay with the Admin. Fictional people only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00', 'Asia/Jakarta'));
    $this->admin = A::admin();
    $this->kesiswaan = A::staff(RoleName::Kesiswaan);
    $this->x4 = Classroom::factory()->create(['name' => 'X-4', 'level' => 10]);
    $this->xi1 = Classroom::factory()->level(11)->create(['name' => 'XI-1']);

    $make = function (Classroom $class, string $name): Student {
        $student = Student::factory()->activated()->create(['classroom_id' => $class->getKey()]);
        $student->user->forceFill(['name' => $name])->saveQuietly();

        return $student->fresh();
    };

    $this->raka = $make($this->x4, 'Raka Pratama Wijaya');
    $this->sinta = $make($this->x4, 'Sinta Ayu Lestari');
    $this->bima = $make($this->xi1, 'Bima Satria Nugraha');
});

it('is mounted only for an account that may put students into the trash', function (RoleName $role, bool $allowed): void {
    $this->actingAs(A::staff($role));

    $allowed
        ? Livewire::test(HapusSiswa::class)->assertOk()
        : Livewire::test(HapusSiswa::class)->assertForbidden();
})->with([
    'admin' => [RoleName::Admin, true],
    'kesiswaan' => [RoleName::Kesiswaan, true],
    'tata usaha' => [RoleName::TataUsaha, false],
    'kepala sekolah' => [RoleName::KepalaSekolah, false],
    'humas' => [RoleName::Humas, false],
    'guru' => [RoleName::Guru, false],
]);

it('refuses a student account', function (): void {
    $this->actingAs(User::query()->findOrFail($this->raka->user_id));

    Livewire::test(HapusSiswa::class)->assertForbidden();
});

it('asks for the password first and keeps nothing until it is confirmed', function (): void {
    $this->actingAs($this->kesiswaan);

    Livewire::test(HapusSiswa::class, ['returnTo' => route('kesiswaan.aktivasi.index')])
        ->call('open', [(int) $this->raka->user_id])
        ->assertRedirect(route('password.confirm'))
        ->assertSet('asked', [])
        ->assertNotDispatched('sth-modal-open');

    expect(session('url.intended'))->toBe(route('kesiswaan.aktivasi.index'));
});

it('names the students and their class, or "dari N kelas" when the ticks come from several classes', function (): void {
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(HapusSiswa::class)
        ->call('open', [(int) $this->sinta->user_id, (string) $this->raka->user_id])
        ->assertDispatched('sth-modal-open', id: HapusSiswa::DIALOG)
        ->assertSee('Hapus 2 siswa?')
        ->assertSee('Raka Pratama Wijaya, Sinta Ayu Lestari')
        ->assertSee('Kelas X-4')
        ->assertSee('Semua siswa yang dipilih dipindahkan ke Sampah');

    Livewire::test(HapusSiswa::class)
        ->call('open', [(int) $this->raka->user_id, (int) $this->bima->user_id, (int) $this->sinta->user_id])
        ->assertSee('Hapus 3 siswa?')
        ->assertSee('dari 2 kelas')
        ->assertDontSee('Kelas X-4');
});

it('lets Kesiswaan delete, says the Admin can restore within 7 days, audits without names', function (): void {
    $this->actingAs($this->kesiswaan);
    session(A::passwordConfirmed());

    Livewire::test(HapusSiswa::class)
        ->call('open', [(int) $this->raka->user_id, (int) $this->bima->user_id])
        ->assertSee('Selama 7 hari Admin masih bisa memulihkannya di menu Kelas. Mulai 13 November 2026 akun dan seluruh data siswanya')
        ->call('trash')
        ->assertHasErrors(['reason'])
        ->set('reason', 'Data ganda dari import.')
        ->call('trash')
        ->assertHasNoErrors()
        ->assertSet('asked', [])
        ->assertDispatched('sth-modal-close', id: HapusSiswa::DIALOG)
        ->assertDispatched(HapusSiswa::DONE_EVENT)
        ->assertDispatched('sth-toast', message: '2 siswa dipindahkan ke Sampah. Admin masih bisa memulihkannya di menu Kelas selama 7 hari.', tone: 'success');

    $row = Activity::query()->where('event', TrashStudents::EVENT)->sole();

    expect(Student::query()->whereKey([$this->raka->getKey(), $this->bima->getKey()])->count())->toBe(0)
        ->and(Student::query()->whereKey($this->sinta->getKey())->exists())->toBeTrue()
        ->and(TrashEntry::query()->where('deleted_by', $this->kesiswaan->getKey())->count())->toBe(2)
        ->and($row->causer_id)->toBe($this->kesiswaan->getKey())
        ->and(str_contains((string) json_encode($row->toArray()), 'Raka'))->toBeFalse()
        ->and(str_contains((string) json_encode($row->toArray()), (string) $this->raka->nisn))->toBeFalse();
});

it('tells the Admin the short toast', function (): void {
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(HapusSiswa::class)
        ->call('open', [(int) $this->raka->user_id])
        ->assertSee('Selama 7 hari masih bisa dipulihkan.')
        ->set('reason', 'Data ganda dari import.')
        ->call('trash')
        ->assertDispatched('sth-toast', message: '1 siswa dipindahkan ke Sampah.', tone: 'success');
});

it('does nothing without its question, also on a second click after the delete', function (): void {
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(HapusSiswa::class)->set('reason', 'Data ganda dari import.')->call('trash')
        ->assertNotDispatched('sth-toast')
        ->assertNotDispatched(HapusSiswa::DONE_EVENT);

    $dialog = Livewire::test(HapusSiswa::class)
        ->call('open', [(int) $this->raka->user_id])
        ->set('reason', 'Data ganda dari import.')
        ->call('trash');

    $dialog->set('reason', 'Data ganda dari import.')->call('trash');

    expect(TrashEntry::query()->count())->toBe(1);

    // "Batal", X, Esc: the question is forgotten.
    Livewire::test(HapusSiswa::class)
        ->call('open', [(int) $this->sinta->user_id])
        ->call('forget')
        ->assertSet('asked', [])
        ->set('reason', 'Data ganda dari import.')
        ->call('trash');

    expect(Student::query()->whereKey($this->sinta->getKey())->exists())->toBeTrue();
});

it('keeps the asked ids locked', function (): void {
    $this->actingAs($this->admin);

    expect(fn () => Livewire::test(HapusSiswa::class)->set('asked', [(int) $this->raka->user_id => (string) $this->x4->getKey()]))
        ->toThrow(Exception::class);
});

it('refuses a staff account, an unknown or malformed id, an empty list and more than 400 students with 404', function (string $case): void {
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    $ids = match ($case) {
        'staff' => [(int) $this->raka->user_id, (int) $this->kesiswaan->getKey()],
        'unknown' => [999999],
        'malformed' => ['1 OR 1=1'],
        'empty' => [],
        'too many' => range(1, TrashStudents::MAX_STUDENTS + 1),
        'trashed' => [(int) $this->raka->user_id],
    };

    if ($case === 'trashed') {
        app(TrashStudents::class)->handle($this->admin, [$this->raka->user_id], 'Dihapus dari tab lain.');
    }

    Livewire::test(HapusSiswa::class)->call('open', $ids)->assertNotFound()->assertNotDispatched('sth-modal-open');

    expect(TrashEntry::query()->count())->toBe($case === 'trashed' ? 1 : 0);
})->with(['staff', 'unknown', 'malformed', 'empty', 'too many', 'trashed']);

it('answers a student who went to the trash or changed class meanwhile with a toast and trashes nobody', function (string $change): void {
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    $dialog = Livewire::test(HapusSiswa::class)
        ->call('open', [(int) $this->raka->user_id, (int) $this->sinta->user_id])
        ->set('reason', 'Data ganda dari import.');

    match ($change) {
        'trashed' => app(TrashStudents::class)->handle($this->admin, [$this->raka->user_id], 'Dihapus dari tab lain.'),
        'moved' => $this->raka->forceFill(['classroom_id' => $this->xi1->getKey()])->save(),
    };

    $dialog->call('trash')
        ->assertOk()
        ->assertSet('asked', [])
        ->assertDispatched('sth-modal-close', id: HapusSiswa::DIALOG)
        ->assertDispatched(HapusSiswa::DONE_EVENT)
        ->assertDispatched('sth-toast', message: HapusSiswa::MSG_CHANGED, tone: 'warning');

    expect(Student::query()->whereKey($this->sinta->getKey())->exists())->toBeTrue()
        ->and(TrashEntry::query()->count())->toBe($change === 'trashed' ? 1 : 0);
})->with(['trashed', 'moved']);

it('asks for the password again on "Hapus siswa" when it ran out meanwhile', function (): void {
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    $dialog = Livewire::test(HapusSiswa::class)
        ->call('open', [(int) $this->raka->user_id])
        ->set('reason', 'Data ganda dari import.');

    session()->put('auth.password_confirmed_at', time() - 2 * HapusSiswa::PASSWORD_WINDOW_SECONDS);

    $dialog->call('trash')->assertRedirect(route('password.confirm'));

    expect(Student::query()->whereKey($this->raka->getKey())->exists())->toBeTrue();
});

it('refuses "Hapus siswa" to an account that lost the right after the question', function (): void {
    $this->actingAs($this->kesiswaan);
    session(A::passwordConfirmed());

    $dialog = Livewire::test(HapusSiswa::class)
        ->call('open', [(int) $this->raka->user_id])
        ->set('reason', 'Data ganda dari import.');

    $this->actingAs(A::staff(RoleName::TataUsaha));
    $dialog->call('trash')->assertForbidden();

    expect(Student::query()->whereKey($this->raka->getKey())->exists())->toBeTrue();
});
