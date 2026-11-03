<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Admin\Pengaturan\Pengguna;
use App\Models\Classroom;
use App\Models\Student;
use App\Notifications\Admin\PrincipalReplacementNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
| r3 (second browser check, 2026-10-31), Admin screens:
| 1. The dialogs that are always on the page built a field's id from its wire:model ("f-reason"), so three dialogs of
|    Pengguna & peran (and two of Arsip otomatis, and the role-change list embedded in Pengguna) gave several fields one
|    id: the label, hint and error of the open dialog pointed at a field in a hidden one. Each field has its own id now.
| 4. "Ganti Kepala Sekolah" said the current Kepala Sekolah "bisa membatalkan"; only this Admin screen can.
| Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();
    $this->withoutVite();
    $this->admin = A::admin();
});

/**
 * How often each id stands on the page.
 *
 * @return array<string, int>
 */
function r3IdCounts(string $html): array
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $counts = [];

    foreach ((new DOMXPath($dom))->query('//*[@id]') as $node) {
        /** @var DOMElement $node */
        $counts[$node->getAttribute('id')] = ($counts[$node->getAttribute('id')] ?? 0) + 1;
    }

    return $counts;
}

/** @return list<string> the ids that stand on more than one element */
function r3RepeatedIds(string $html): array
{
    return array_keys(array_filter(r3IdCounts($html), fn (int $count): bool => $count > 1));
}

it('gives every field of the Pengguna & peran dialogs its own id, with its label and hint (r3)', function (): void {
    A::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);

    $html = (string) $this->actingAs($this->admin)->withSession(A::passwordConfirmed())
        ->get(route('admin.pengguna.index'))->assertOk()->getContent();
    $ids = r3IdCounts($html);

    expect(r3RepeatedIds($html))->toBe([]);

    foreach (['f-staf-peran-reason', 'f-staf-reset-reason', 'f-staf-nonaktif-reason', 'f-batal-kepsek-reason',
        'f-batal-peran-reason', 'f-siswa-reset-reason', 'f-siswa-nonaktif-reason'] as $id) {
        expect($ids[$id] ?? 0)->toBe(1, $id)
            ->and($html)->toContain('for="'.$id.'"');
    }

    // The hints belong to their own field.
    foreach (['f-staf-nonaktif-reason', 'f-siswa-reset-reason', 'f-siswa-nonaktif-reason'] as $id) {
        expect($ids[$id.'-hint'] ?? 0)->toBe(1, $id)
            ->and($html)->toContain('aria-describedby="'.$id.'-hint"');
    }

    expect($html)->not->toContain('id="f-reason"')
        ->not->toContain('id="f-studentReason"')
        ->not->toContain('id="f-cancelReason"');
});

// The shared 'reason' error also renders in the hidden dialogs; what this checks is that the sent dialog's error has its
// own id and that its field points at it (r3 review: the old name claimed more than that).
it('links the error of the sent dialog to its own field (r3)', function (): void {
    $guru = A::staff(RoleName::Guru, ['name' => 'Ibu Retno Wulandari']);
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    $html = Livewire::test(Pengguna::class)
        ->call('openStaff', $guru->id, 'staf-peran')
        ->set('role', RoleName::Humas->value)
        ->call('changeRole')
        ->assertHasErrors(['reason'])
        ->html();

    expect(r3IdCounts($html)['f-staf-peran-reason-error'] ?? 0)->toBe(1)
        ->and($html)->toContain('aria-describedby="f-staf-peran-reason-error"')
        ->and(r3RepeatedIds($html))->toBe([]);
});

it('gives the two Arsip otomatis dialogs their own date and reason fields (r3)', function (): void {
    $classroom = Classroom::factory()->create(['name' => 'XII-A', 'level' => 12]);
    Student::factory()->create(['classroom_id' => $classroom->getKey(), 'archive_at' => now()->addDays(10)->toDateString()]);

    $html = (string) $this->actingAs($this->admin)->get(route('admin.arsip.index'))->assertOk()->getContent();
    $ids = r3IdCounts($html);

    expect(r3RepeatedIds($html))->toBe([]);

    foreach (['f-arsip-tanggal-date', 'f-arsip-tanggal-reason', 'f-arsip-pulihkan-date', 'f-arsip-pulihkan-reason'] as $id) {
        expect($ids[$id] ?? 0)->toBe(1, $id)
            ->and($html)->toContain('for="'.$id.'"');
    }

    expect($ids['f-arsip-tanggal-date-hint'] ?? 0)->toBe(1)
        ->and($ids['f-arsip-pulihkan-date-hint'] ?? 0)->toBe(1)
        ->and($ids['f-arsip-pulihkan-reason-hint'] ?? 0)->toBe(1)
        ->and($html)->not->toContain('id="f-archiveDate"')
        ->and($html)->not->toContain('id="f-archiveReason"');
});

it('says in "Ganti Kepala Sekolah" that an Admin cancels on this screen, not the Kepala Sekolah (r3)', function (): void {
    $current = A::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
    $next = A::staff(RoleName::Guru, ['name' => 'Ibu Retno Wulandari']);
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    $screen = Livewire::test(Pengguna::class)
        ->assertSee('Berlaku 24 jam setelah disimpan. Kepala Sekolah saat ini, Kepala Sekolah baru, dan semua Admin diberi tahu. Sebelum berlaku, Admin bisa membatalkannya di halaman ini.')
        ->assertDontSee('diberi tahu dan bisa membatalkan');

    // The people the sentence names are the ones told.
    $screen->call('openPrincipal')
        ->set('principalTarget', (string) $next->id)
        ->set('principalReason', 'SK pengangkatan Kepala Sekolah baru.')
        ->call('requestPrincipal')
        ->assertHasNoErrors();

    Notification::assertSentTo([$current, $next, $this->admin], PrincipalReplacementNotice::class);

    // The Kepala Sekolah is asked to turn to an Admin, which is why the dialog no longer says it can cancel.
    $lines = (new PrincipalReplacementNotice(PrincipalReplacementNotice::REQUESTED, 'A', 'B', '1 November 2026 10:00'))->linesFor($current);
    expect($lines[1])->toBe('Jika ini keliru, minta Admin membatalkannya sebelum waktu itu.');
});

it('keeps the first-time sentence when there is no Kepala Sekolah yet (r3)', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(Pengguna::class)
        ->assertSee('Belum ada Kepala Sekolah, jadi langsung berlaku.')
        ->assertDontSee('Admin bisa membatalkannya di halaman ini.');
});
