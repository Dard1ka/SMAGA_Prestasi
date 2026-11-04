<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Akun\StatusAktivasi;
use App\Models\Classroom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| r3 (second browser check, 2026-10-31), Status aktivasi › Daftar siswa: at the 1.000-row cap (StatusAktivasi::MAX)
| "Tampilkan lebih banyak" stayed on the page and did nothing (showMore() raised the limit, render() cut it back to the
| cap, hasMore ignored the cap). Now the button stops at the cap and a note says how to see the others. Fictional data.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
    Notification::fake();

    $this->kesiswaan = A::staff(RoleName::Kesiswaan);
    $this->classroom = Classroom::factory()->create(['name' => 'X-1', 'level' => 10]);
});

/** $count students waiting for their activation in one class, written in bulk (the factories would take minutes). */
function r3ManyStudents(Classroom $classroom, int $count): void
{
    $now = now();
    $password = Hash::make('password');

    foreach (array_chunk(range(1, $count), 90) as $chunk) {
        DB::table('users')->insert(array_map(fn (int $n): array => [
            'name' => sprintf('Siswa Contoh %04d', $n),
            'password' => $password,
            'status' => UserStatus::PendingActivation->value,
            'must_change_password' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $chunk));
    }

    $userIds = DB::table('users')->where('name', 'like', 'Siswa Contoh %')->orderBy('id')->pluck('id')->all();

    foreach (array_chunk($userIds, 90, true) as $chunk) {
        $rows = [];

        foreach ($chunk as $n => $userId) {
            $rows[] = [
                'id' => strtolower((string) Str::ulid()),
                'user_id' => $userId,
                'nis' => sprintf('9%08d', $n),
                'nisn' => sprintf('09%08d', $n),
                'birth_date' => '2010-05-05',
                'classroom_id' => $classroom->getKey(),
                'import_school_year' => '2026/2027',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('students')->insert($rows);
    }
}

it('stops "Tampilkan lebih banyak" at the 1.000-row cap of Daftar siswa and says how to see the others (r3)', function (): void {
    r3ManyStudents($this->classroom, StatusAktivasi::MAX + 5);
    $this->actingAs($this->kesiswaan);

    // Mounted on "Daftar siswa" 10 rows below the cap (as after 49 clicks from 10 rows); the next click reaches it.
    // Each render of about 1.000 rows costs some seconds in the test process, so the test renders two of them only.
    $component = Livewire::test(StatusAktivasi::class, ['limit' => StatusAktivasi::MAX - 10, 'tampil' => StatusAktivasi::VIEW_STUDENTS]);

    $component->assertSet('limit', StatusAktivasi::MAX - 10)
        ->assertSee('Menampilkan 990 dari 1.005 siswa.')
        ->assertSeeHtml('wire:click="showMore"')
        ->assertDontSee(StatusAktivasi::capNote())
        ->call('showMore')
        ->assertSet('limit', StatusAktivasi::MAX)
        ->assertSee('Menampilkan 1.000 dari 1.005 siswa.')
        ->assertDontSeeHtml('wire:click="showMore"')
        ->assertSee('Menampilkan 1.000 siswa pertama. Persempit dengan kelas, status, atau pencarian untuk melihat lainnya.');

    // A list that fits shows neither the button nor the note.
    $component->set('q', 'Siswa Contoh 0001')
        ->assertSet('limit', StatusAktivasi::PREVIEW)
        ->assertSee('Menampilkan 1 dari 1 siswa.')
        ->assertDontSeeHtml('wire:click="showMore"')
        ->assertDontSee(StatusAktivasi::capNote());
});

it('keeps every "Tampilkan lebih banyak" counter inside its cap (r3)', function (): void {
    $this->actingAs($this->kesiswaan);
    $component = Livewire::test(StatusAktivasi::class);

    // 3 + 6 × 10 would be 63: the boxes stop at the 50 rows their queries can list.
    foreach (range(1, 6) as $click) {
        $component->call('showMoreFrozen')->call('showMoreK7');
    }

    $component->assertSet('frozenLimit', StatusAktivasi::BOX_MAX)
        ->assertSet('k7Limit', StatusAktivasi::BOX_MAX);

    // A "Tampilkan lebih banyak" click sent anyway at the cap (a stale page) stays there; an empty list renders fast.
    Livewire::test(StatusAktivasi::class, ['limit' => StatusAktivasi::MAX - 10, 'tampil' => StatusAktivasi::VIEW_STUDENTS])
        ->call('showMore')
        ->assertSet('limit', StatusAktivasi::MAX)
        ->call('showMore')
        ->assertSet('limit', StatusAktivasi::MAX)
        ->assertDontSee(StatusAktivasi::capNote());

    expect(StatusAktivasi::capNote())
        ->toBe('Menampilkan 1.000 siswa pertama. Persempit dengan kelas, status, atau pencarian untuk melihat lainnya.');
});
