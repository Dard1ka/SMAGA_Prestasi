<?php

declare(strict_types=1);

use App\Actions\Accounts\ImportStudents;
use App\Actions\Classrooms\ClassroomName;
use App\Actions\Classrooms\CreateClassroom;
use App\Actions\Classrooms\DeleteClassroom;
use App\Actions\Classrooms\RenameClassroom;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\AuditLog;
use App\Livewire\Admin\Kelas;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    $this->withoutVite();
    $this->admin = A::admin();
    $this->xa = Classroom::factory()->create(['name' => 'X-A', 'level' => 10]);
    $this->xib = Classroom::factory()->level(11)->create(['name' => 'XI-B']);
});

/** The error message of one key, or null when the call passed. */
function kelasError(Closure $call, string $key = 'suffix'): ?string
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors()[$key][0] ?? null;
    }

    return null;
}

function kelasPublicVersion(): int
{
    return (int) Cache::get(PublicAchievements::VERSION_KEY, 1);
}

// --- Permission and policy ---------------------------------------------------------------------------------------

it('grants classroom.manage to Admin only', function (RoleName $role): void {
    expect(in_array(Permission::ClassroomManage, $role->defaultPermissions(), true))->toBe($role === RoleName::Admin);
})->with(RoleName::cases());

it('lets only Admin view, add, rename and delete classes', function (RoleName $role): void {
    $user = $role === RoleName::Siswa ? H::activeStudent()->user->fresh() : A::staff($role);
    $allowed = $role === RoleName::Admin;

    expect($user->can('viewAny', Classroom::class))->toBe($allowed)
        ->and($user->can('create', Classroom::class))->toBe($allowed)
        ->and($user->can('update', $this->xa))->toBe($allowed)
        ->and($user->can('delete', $this->xa))->toBe($allowed);
})->with(RoleName::cases());

it('refuses an Admin who can no longer log in', function (): void {
    $this->admin->forceFill(['status' => UserStatus::Disabled])->save();

    expect($this->admin->fresh()->can('viewAny', Classroom::class))->toBeFalse();
});

// --- Routes ------------------------------------------------------------------------------------------------------

it('opens Admin › Kelas for an Admin, without a password confirmation', function (): void {
    $this->actingAs($this->admin)->get(route('admin.kelas.index'))
        ->assertOk()
        ->assertSee('Tambah kelas')
        ->assertSee('X-A')
        ->assertSee('XI-B')
        ->assertSee('Import siswa hanya memasukkan siswa ke kelas yang ada di daftar ini.');
});

it('refuses Admin › Kelas to every other role', function (RoleName $role): void {
    $user = $role === RoleName::Siswa ? H::activeStudent()->user->fresh() : A::staff($role);

    $this->actingAs($user)->get(route('admin.kelas.index'))->assertForbidden();
})->with([RoleName::Siswa, RoleName::Guru, RoleName::Kesiswaan, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas]);

it('sends a guest from Admin › Kelas to the login page', function (): void {
    $this->get(route('admin.kelas.index'))->assertRedirect(route('login'));
});

it('lists Kelas in the Admin menu right before Import siswa', function (): void {
    $nav = Navigation::for(['name' => 'X', 'roles' => ['admin']], 'admin', [], 'admin.kelas.index');
    $data = collect($nav['sections'])->firstWhere('label', 'Data');
    $keys = array_column($data['items'], 'key');
    $kelas = collect($data['items'])->firstWhere('key', 'kelas');

    expect(array_search('kelas', $keys, true) + 1)->toBe(array_search('import', $keys, true))
        ->and($kelas['label'])->toBe('Kelas')
        ->and($kelas['href'])->toBe(route('admin.kelas.index'))
        ->and($kelas['current'])->toBeTrue();
});

// --- CreateClassroom ---------------------------------------------------------------------------------------------

it('adds a class as "<TINGKAT>-<PART>" in capitals with single spaces, and audits it', function (): void {
    $classroom = app(CreateClassroom::class)->handle($this->admin, 11, "  ipa \t  1 ");

    expect($classroom->name)->toBe('XI-IPA 1')
        ->and($classroom->level)->toBe(11)
        ->and($classroom->active)->toBeTrue();

    $row = Activity::query()->where('event', 'classroom.created')->sole();

    expect($row->causer_id)->toBe($this->admin->getKey())
        ->and((string) $row->subject_id)->toBe((string) $classroom->getKey())
        ->and($row->properties->all())->toBe(['name' => 'XI-IPA 1', 'level' => 11]);
});

it('adds X-, XI- and XII- classes by level', function (int $level, string $name): void {
    expect(app(CreateClassroom::class)->handle($this->admin, $level, 'k')->name)->toBe($name);
})->with([[10, 'X-K'], [11, 'XI-K'], [12, 'XII-K']]);

it('refuses an invalid tingkat or part', function (int $level, string $part, string $key, string $message): void {
    expect(kelasError(fn () => app(CreateClassroom::class)->handle($this->admin, $level, $part), $key))->toBe($message)
        ->and(Classroom::query()->count())->toBe(2);
})->with([
    'no tingkat' => [9, 'A', 'level', ClassroomName::MSG_LEVEL],
    'empty' => [10, '   ', 'suffix', ClassroomName::MSG_EMPTY],
    'too long' => [10, 'ABCDEFGHIJKLM', 'suffix', ClassroomName::MSG_LONG],
    'dash' => [10, 'A-1', 'suffix', ClassroomName::MSG_CHARS],
    'dot' => [10, 'IPA.1', 'suffix', ClassroomName::MSG_CHARS],
    'accent' => [10, 'É', 'suffix', ClassroomName::MSG_CHARS],
]);

it('accepts 12 characters, the longest name fits the column', function (): void {
    $name = app(CreateClassroom::class)->handle($this->admin, 12, 'ABCDEFGH IJK')->name;

    expect($name)->toBe('XII-ABCDEFGH IJK')
        ->and(strlen($name))->toBeLessThanOrEqual(20);
});

it('refuses a name another class has, without case and spaces', function (int $level, string $part, string $taken): void {
    Classroom::factory()->level(11)->create(['name' => 'XI-IPA1']);

    expect(kelasError(fn () => app(CreateClassroom::class)->handle($this->admin, $level, $part)))->toBe("Kelas {$taken} sudah ada.");
})->with([
    'same' => [10, 'A', 'X-A'],
    'lower case' => [10, 'a', 'X-A'],
    'with a space' => [11, 'ipa 1', 'XI-IPA1'],
]);

it('refuses an Admin action to anyone without classroom.manage', function (RoleName $role): void {
    $user = A::staff($role);

    expect(fn () => app(CreateClassroom::class)->handle($user, 10, 'K'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RenameClassroom::class)->handle($user, $this->xa, 'K'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteClassroom::class)->handle($user, $this->xa))->toThrow(AuthorizationException::class)
        ->and($this->xa->fresh()->name)->toBe('X-A');
})->with([RoleName::Kesiswaan, RoleName::TataUsaha, RoleName::KepalaSekolah]);

// --- RenameClassroom ---------------------------------------------------------------------------------------------

it('renames only the part after the dash, keeps the level and the students, audits from/to and flushes the public cache', function (): void {
    $student = Student::factory()->create(['classroom_id' => $this->xib->getKey()]);
    $version = kelasPublicVersion();

    $renamed = app(RenameClassroom::class)->handle($this->admin, $this->xib, ' ips  2 ');

    expect($renamed->name)->toBe('XI-IPS 2')
        ->and($renamed->level)->toBe(11)
        ->and($student->fresh()->classroom_id)->toBe($this->xib->getKey())
        ->and(kelasPublicVersion())->toBe($version + 1);

    $row = Activity::query()->where('event', 'classroom.renamed')->sole();

    expect($row->properties->all())->toBe(['from' => 'XI-B', 'to' => 'XI-IPS 2']);
});

it('refuses a rename to an unchanged or taken name and audits nothing', function (string $part, string $message): void {
    expect(kelasError(fn () => app(RenameClassroom::class)->handle($this->admin, $this->xib, $part)))->toBe($message)
        ->and($this->xib->fresh()->name)->toBe('XI-B')
        ->and(Activity::query()->where('event', 'classroom.renamed')->exists())->toBeFalse();
})->with([
    'unchanged' => ['b', RenameClassroom::MSG_SAME],
    'empty' => ['', ClassroomName::MSG_EMPTY],
]);

it('refuses a rename to the name of another class of the same tingkat', function (): void {
    Classroom::factory()->level(11)->create(['name' => 'XI-C']);

    expect(kelasError(fn () => app(RenameClassroom::class)->handle($this->admin, $this->xib, 'c ')))->toBe('Kelas XI-C sudah ada.');
});

it('lets a rename change only the spacing of its own name', function (): void {
    $class = Classroom::factory()->level(11)->create(['name' => 'XI-IPA1']);

    expect(app(RenameClassroom::class)->handle($this->admin, $class, 'IPA 1')->name)->toBe('XI-IPA 1');
});

// --- DeleteClassroom ---------------------------------------------------------------------------------------------

it('deletes an empty class, audits its name and flushes the public cache', function (): void {
    $version = kelasPublicVersion();

    app(DeleteClassroom::class)->handle($this->admin, $this->xa);

    expect(Classroom::query()->whereKey($this->xa->getKey())->exists())->toBeFalse()
        ->and(kelasPublicVersion())->toBe($version + 1);

    $row = Activity::query()->where('event', 'classroom.deleted')->sole();

    expect($row->properties->all())->toBe(['name' => 'X-A', 'level' => 10])
        ->and($row->causer_id)->toBe($this->admin->getKey());
});

it('refuses to delete a class that any student row points at, archived and soft-deleted included', function (string $kind): void {
    $student = Student::factory()->create(['classroom_id' => $this->xa->getKey()]);
    Student::factory()->create(['classroom_id' => $this->xa->getKey()]);

    match ($kind) {
        'archived' => User::query()->whereKey($student->user_id)->update(['status' => UserStatus::Archived->value]),
        'soft-deleted' => $student->delete(),
        default => null,
    };

    expect(kelasError(fn () => app(DeleteClassroom::class)->handle($this->admin, $this->xa), 'classroom'))
        ->toBe('Kelas ini masih berisi 2 siswa. Pindahkan siswanya lewat import siswa dulu.')
        ->and($this->xa->fresh())->not->toBeNull()
        ->and(Activity::query()->where('event', 'classroom.deleted')->exists())->toBeFalse();
})->with(['active', 'archived', 'soft-deleted']);

it('has an Indonesian label for the three class events in the audit log', function (): void {
    expect(AuditLog::EVENT_LABELS)->toHaveKeys(['classroom.created', 'classroom.renamed', 'classroom.deleted']);
});

// --- Import ------------------------------------------------------------------------------------------------------

it('lets the student import accept a class the Admin just added', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 21)->setTime(9, 0));
    $row = ['Nadia Putri Anindya', '225226201', '0098765432', '02-03-2010', 'x-k', ''];
    $path = tempnam(sys_get_temp_dir(), 'sth-kelas-');
    $handle = fopen($path, 'wb');
    fputcsv($handle, ImportStudents::TEMPLATE_HEADER, ',', '"', '');
    fputcsv($handle, $row, ',', '"', '');
    fclose($handle);

    try {
        $before = app(ImportStudents::class)->handle($this->admin, $path, 'csv', '2026/2027');
        app(CreateClassroom::class)->handle($this->admin, 10, 'K');
        $after = app(ImportStudents::class)->handle($this->admin, $path, 'csv', '2026/2027');
    } finally {
        @unlink($path);
    }

    expect($before->rows[0]['errors'])->toContain('Kelas tidak ditemukan.')
        ->and($after->rows[0]['errors'])->toBe([])
        ->and($after->rows[0]['classroom'])->toBe('X-K');
});

// --- Screen ------------------------------------------------------------------------------------------------------

it('shows the classes per tingkat with active and total students', function (): void {
    Student::factory()->count(2)->create(['classroom_id' => $this->xa->getKey()]);
    $archived = Student::factory()->create(['classroom_id' => $this->xa->getKey()]);
    User::query()->whereKey($archived->user_id)->update(['status' => UserStatus::Archived->value]);

    $this->actingAs($this->admin);
    $groups = Livewire::test(Kelas::class)->viewData('groups');

    expect(array_column($groups, 'label'))->toBe(['Kelas X', 'Kelas XI'])
        ->and($groups[0]['rows'][0])->toMatchArray(['name' => 'X-A', 'active' => 2, 'total' => 3])
        ->and($groups[1]['rows'][0])->toMatchArray(['name' => 'XI-B', 'active' => 0, 'total' => 0]);
});

it('adds a class from the form and shows the errors under the fields', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(Kelas::class)
        ->set('level', '12')
        ->set('suffix', 'ipa 1')
        ->call('add')
        ->assertHasNoErrors()
        ->assertSet('suffix', '')
        ->assertDispatched('sth-toast', message: 'Kelas XII-IPA 1 ditambahkan.', tone: 'success')
        ->set('level', '10')
        ->set('suffix', 'A')
        ->call('add')
        ->assertHasErrors(['suffix'])
        ->set('level', '9')
        ->set('suffix', 'B')
        ->call('add')
        ->assertHasErrors(['level']);

    expect(Classroom::query()->where('name', 'XII-IPA 1')->exists())->toBeTrue();
});

it('renames a class through its dialog', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(Kelas::class)
        ->call('askRename', $this->xib->getKey())
        ->assertSet('renamingId', (string) $this->xib->getKey())
        ->assertSet('renameSuffix', 'B')
        ->assertDispatched('sth-modal-open', id: Kelas::RENAME_DIALOG)
        ->set('renameSuffix', '')
        ->call('rename')
        ->assertHasErrors(['renameSuffix'])
        ->set('renameSuffix', 'ips 2')
        ->call('rename')
        ->assertHasNoErrors()
        ->assertSet('renamingId', null)
        ->assertDispatched('sth-modal-close', id: Kelas::RENAME_DIALOG)
        ->assertDispatched('sth-toast', message: 'Nama kelas diganti menjadi XI-IPS 2.', tone: 'success');

    expect($this->xib->fresh()->name)->toBe('XI-IPS 2');
});

it('does nothing on rename or delete without the question', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(Kelas::class)
        ->set('renameSuffix', 'Z')
        ->call('rename')
        ->call('delete')
        ->assertNotDispatched('sth-toast')
        ->assertHasNoErrors();

    expect(Classroom::query()->count())->toBe(2)
        ->and($this->xa->fresh()->name)->toBe('X-A');
});

it('deletes an empty class after the question', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(Kelas::class)
        ->call('askDelete', $this->xa->getKey())
        ->assertSet('deletingId', (string) $this->xa->getKey())
        ->assertDispatched('sth-modal-open', id: Kelas::DELETE_DIALOG)
        ->assertSee('Hapus kelas ini?')
        ->call('delete')
        ->assertSet('deletingId', null)
        ->assertDispatched('sth-toast', message: 'Kelas X-A dihapus.', tone: 'success');

    expect(Classroom::query()->whereKey($this->xa->getKey())->exists())->toBeFalse();
});

it('disables Hapus for a class with students and refuses its question with a toast', function (): void {
    Student::factory()->create(['classroom_id' => $this->xa->getKey()]);
    $this->actingAs($this->admin);

    Livewire::test(Kelas::class)
        ->assertSeeHtml("wire:click=\"askDelete('{$this->xib->getKey()}')\"")
        ->assertDontSeeHtml("wire:click=\"askDelete('{$this->xa->getKey()}')\"")
        ->assertSee('masih berisi 1 siswa')
        ->call('askDelete', $this->xa->getKey())
        ->assertSet('deletingId', null)
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', message: DeleteClassroom::refusal(1), tone: 'warning');
});

it('answers a class deleted in another tab with a toast, never an error page', function (string $method): void {
    $this->actingAs($this->admin);
    $id = $this->xa->getKey();
    $this->xa->delete();

    Livewire::test(Kelas::class)
        ->call($method, $id)
        ->assertOk()
        ->assertDispatched('sth-toast', message: Kelas::MSG_GONE, tone: 'warning')
        ->assertNotDispatched('sth-modal-open');
})->with(['askRename', 'askDelete']);

it('answers a rename confirmed after another tab renamed or deleted the class with a toast', function (string $change): void {
    $this->actingAs($this->admin);
    $component = Livewire::test(Kelas::class)->call('askRename', $this->xib->getKey())->set('renameSuffix', 'Z');

    $change === 'renamed'
        ? app(RenameClassroom::class)->handle($this->admin, $this->xib, 'C')
        : app(DeleteClassroom::class)->handle($this->admin, $this->xib);

    $component->call('rename')
        ->assertOk()
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-close', id: Kelas::RENAME_DIALOG)
        ->assertDispatched('sth-toast', message: $change === 'renamed' ? Kelas::MSG_RENAMED : Kelas::MSG_GONE, tone: 'warning');

    expect(Classroom::query()->where('name', 'XI-Z')->exists())->toBeFalse();
})->with(['renamed', 'deleted']);

it('answers a delete confirmed after the class changed elsewhere with a toast', function (string $change): void {
    $this->actingAs($this->admin);
    $component = Livewire::test(Kelas::class)->call('askDelete', $this->xa->getKey());

    match ($change) {
        'renamed' => app(RenameClassroom::class)->handle($this->admin, $this->xa, 'Z'),
        'deleted' => app(DeleteClassroom::class)->handle($this->admin, $this->xa),
        'filled' => Student::factory()->create(['classroom_id' => $this->xa->getKey()]),
    };

    $component->call('delete')
        ->assertOk()
        ->assertDispatched('sth-toast', message: match ($change) {
            'renamed' => Kelas::MSG_RENAMED,
            'deleted' => Kelas::MSG_GONE,
            'filled' => DeleteClassroom::refusal(1),
        }, tone: 'warning');

    expect(Classroom::query()->whereKey($this->xa->getKey())->exists())->toBe($change !== 'deleted');
})->with(['renamed', 'deleted', 'filled']);

it('refuses the screen and its actions to Kesiswaan', function (): void {
    $this->actingAs(A::staff(RoleName::Kesiswaan));

    Livewire::test(Kelas::class)->assertForbidden();
});
