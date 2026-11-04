<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Livewire\Kesiswaan\Talenta;
use App\Models\Classroom;
use App\Models\TalentApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| r3 (second browser check, 2026-10-31), Kesiswaan › Database talenta siswa: after a live search or a filter change the
| detail panel kept a student the filtered list no longer showed, and its "Simpan" still saved that row (updated() never
| picked the #[Locked] panelId again). Now the panel keeps the open row while it still matches, else opens the first
| match as mount() does, else closes.
|
| r3 review (2026-11-01): (1) one request may carry the search that moves the panel together with the guru pembina /
| note typed for the row it left, and the "Simpan" clicked while the 400 ms search was on its way. Livewire applies the
| search first (the snapshot's property order), so the typed text landed on the new row, or was overwritten by its
| stored values, and "Simpan" saved the new row and still said "Data talenta tersimpan.". "Simpan" now names the row it
| showed: another row → a warning toast and nothing saved; a typed field never lands on the new row. (2) "still matches"
| means one of the rows the list shows after $limit went back to the first page.
|
| Final review (2026-11-01): (1) the warning no longer says "Daftar berubah": a click on another row (also another
| choice of the same student, from the panel's own list) queued with "Simpan" moves the panel without any list change.
| (2) a "Simpan" that finds the row the panel holds deleted by the student takes the deleted-choice path of every other
| action (CANCELLED_MESSAGE, then the first row left). Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);

    $this->hana = F::application(F::student('Hana Safira', 'X-8'));
    // Newest first (D-142): Aurelia's choice is the first row, the one mount() opens.
    $this->travel(1)->minutes();
    $this->aurelia = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Terdaftar, 'Kimia');
});

/** One Livewire request as the browser sends it: the deferred and live fields, then the "Simpan" of the panel. */
function r3TalentaRequest(Testable $component, array $updates, ?string $savedRow = null): Testable
{
    $calls = $savedRow === null ? [] : [['method' => 'savePanel', 'params' => [$savedRow], 'path' => '']];

    return $component->update(calls: $calls, updates: $updates);
}

it('opens the first match when a live search hides the open row, and "Simpan" saves the row it shows (r3)', function (): void {
    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertSet('panelId', $this->aurelia->getKey())
        ->call('showPanel', $this->hana->getKey())
        ->assertSet('panelId', $this->hana->getKey())
        ->set('q', 'aurelia')
        ->assertSet('panelId', $this->aurelia->getKey())
        ->assertSet('panelNote', '')
        // Nothing was typed for Hana: no warning.
        ->assertNotDispatched('sth-toast')
        ->assertViewHas('panel', fn (?array $panel): bool => $panel !== null && $panel['name'] === 'Aurelia Maharani')
        ->assertDontSee('Hana Safira');

    // r3 review: the button names the row the panel shows (a literal argument, CSP-safe).
    expect($component->html())->toMatch('/wire:click="savePanel\((?:\'|&#0?39;)'.preg_quote((string) $this->aurelia->getKey(), '/').'(?:\'|&#0?39;)\)"/');

    $component->set('panelNote', 'Latihan soal tiap Jumat.')
        ->call('savePanel', $this->aurelia->getKey())
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: Talenta::SAVED_MESSAGE, tone: 'success');

    expect($this->aurelia->fresh()->note)->toBe('Latihan soal tiap Jumat.')
        ->and($this->hana->fresh()->note)->toBeNull();
    Notification::assertNothingSent();
});

it('keeps the open row and what was typed in the panel while the row still matches (r3)', function (): void {
    $hanaClass = (string) Classroom::query()->where('name', 'X-8')->value('id');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $this->hana->getKey())
        ->set('panelNote', 'Draf catatan')
        ->set('kelas', $hanaClass)
        ->assertSet('panelId', $this->hana->getKey())
        ->assertSet('panelNote', 'Draf catatan')
        ->set('q', 'safira ')
        ->assertSet('panelId', $this->hana->getKey())
        ->assertSet('panelNote', 'Draf catatan')
        ->assertNotDispatched('sth-toast');
});

it('re-picks the panel on the jenis, cabang and kelas filters too (r3)', function (): void {
    $aureliaClass = (string) Classroom::query()->where('name', 'X-3')->value('id');

    // Kelas: Hana's row is open, the filter keeps only Aurelia's class.
    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $this->hana->getKey())
        ->set('kelas', $aureliaClass)
        ->assertSet('panelId', $this->aurelia->getKey());

    // Cabang: Aurelia's row (Kimia) is open, the filter keeps only Biologi (Hana).
    $biologi = (string) $this->hana->talent_branch_id;

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('setJenis', TalentTypeCode::Osn->value)
        ->assertSet('panelId', $this->aurelia->getKey())
        ->set('cabang', $biologi)
        ->assertSet('panelId', $this->hana->getKey());

    // Jenis without any row: the panel closes.
    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('setJenis', TalentTypeCode::Debat->value)
        ->assertSet('panelId', null);
});

it('closes the panel when nothing matches, and a late "Simpan" then saves nothing (r3)', function (): void {
    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('panelNote', 'Tidak boleh tersimpan.')
        ->set('q', 'tidak ada siswa ini')
        ->assertSet('panelId', null)
        ->assertSet('mentorId', '')
        ->assertSet('panelNote', '')
        // r3 review: the note typed for Aurelia was dropped with the panel; the screen says so (it was silent).
        ->assertDispatched('sth-toast', message: Talenta::PANEL_CLOSED_MESSAGE, tone: 'warning')
        ->assertSee('Pilih baris untuk melihat detail.')
        ->assertDontSeeHtml('wire:click="savePanel(');

    // The "Simpan" of the page before that render names Aurelia's row: a warning, no write.
    $component->set('panelNote', 'Tidak boleh tersimpan.')
        ->call('savePanel', $this->aurelia->getKey())
        ->assertOk()
        ->assertDispatched('sth-toast', message: Talenta::PANEL_CLOSED_MESSAGE, tone: 'warning')
        ->assertNotDispatched('sth-toast', message: Talenta::SAVED_MESSAGE, tone: 'success');

    // A call without the row (a page rendered before the r3 review): still a silent no-op, no error page and no write.
    $component->set('panelNote', 'Tidak boleh tersimpan.')
        ->call('savePanel')
        ->assertOk()
        ->assertNotDispatched('sth-toast');

    expect($this->hana->fresh()->note)->toBeNull()
        ->and($this->aurelia->fresh()->note)->toBeNull()
        ->and(Activity::query()->where('event', 'talent.assessment_updated')->exists())->toBeFalse();

    // Clearing the search opens the first row again, as on a fresh page.
    $component->set('q', '')->assertSet('panelId', $this->aurelia->getKey());
});

// r3 review, item 1: one request with the search, the typed fields and "Simpan" ---------------------------------------

it('saves nothing and warns when the search of the same request moved the panel away from the row "Simpan" named (r3 review)', function (array $order): void {
    $guruHana = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $guruAurelia = F::staff(RoleName::Guru, 'Bu Sari Wulandari');
    $this->aurelia->forceFill(['mentor_id' => $guruAurelia->getKey(), 'note' => 'Latihan soal tiap Jumat.'])->save();

    $typed = ['q' => 'aurelia', 'mentorId' => (string) $guruHana->getKey(), 'panelNote' => 'Untuk Hana saja.'];
    $updates = array_combine($order, array_map(fn (string $key): string => $typed[$key], $order));

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $this->hana->getKey())
        ->assertSet('panelId', $this->hana->getKey());

    r3TalentaRequest($component, $updates, $this->hana->getKey())
        ->assertSet('panelId', $this->aurelia->getKey())
        // The panel shows Aurelia's own data, never what was typed for Hana.
        ->assertSet('mentorId', (string) $guruAurelia->getKey())
        ->assertSet('panelNote', 'Latihan soal tiap Jumat.')
        ->assertDispatched('sth-toast', message: Talenta::PANEL_MOVED_MESSAGE, tone: 'warning')
        ->assertNotDispatched('sth-toast', message: Talenta::SAVED_MESSAGE, tone: 'success');

    expect($this->hana->fresh()->note)->toBeNull()
        ->and($this->hana->fresh()->mentor_id)->toBeNull()
        ->and($this->aurelia->fresh()->note)->toBe('Latihan soal tiap Jumat.')
        ->and((int) $this->aurelia->fresh()->mentor_id)->toBe((int) $guruAurelia->getKey())
        ->and(Activity::query()->where('event', 'talent.assessment_updated')->exists())->toBeFalse();

    // The next "Simpan" names Aurelia and saves her row as the panel shows it: Hana's text never reaches it.
    $component->call('savePanel', $this->aurelia->getKey())
        ->assertDispatched('sth-toast', message: Talenta::SAVED_MESSAGE, tone: 'success');

    expect($this->aurelia->fresh()->note)->toBe('Latihan soal tiap Jumat.')
        ->and((int) $this->aurelia->fresh()->mentor_id)->toBe((int) $guruAurelia->getKey());
    Notification::assertNothingSent();
})->with([
    // The browser's order: the snapshot's property order puts the search before the panel fields.
    'search first (as the browser sends it)' => [['q', 'mentorId', 'panelNote']],
    'fields first' => [['mentorId', 'panelNote', 'q']],
]);

it('keeps what was typed off the new row when the 400 ms search moves the panel without a "Simpan" (r3 review)', function (): void {
    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $this->hana->getKey());

    r3TalentaRequest($component, ['q' => 'aurelia', 'panelNote' => 'Untuk Hana saja.'])
        ->assertSet('panelId', $this->aurelia->getKey())
        ->assertSet('panelNote', '')
        ->assertDispatched('sth-toast', message: Talenta::PANEL_MOVED_MESSAGE, tone: 'warning');

    $component->call('savePanel', $this->aurelia->getKey())
        ->assertDispatched('sth-toast', message: Talenta::SAVED_MESSAGE, tone: 'success');

    expect($this->aurelia->fresh()->note)->toBeNull()
        ->and($this->hana->fresh()->note)->toBeNull();
});

it('saves what was typed when the search of the same request keeps the open row (r3 review)', function (): void {
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $this->hana->getKey());

    r3TalentaRequest($component, ['q' => 'safira', 'mentorId' => (string) $guru->getKey(), 'panelNote' => 'Latihan soal tiap Kamis.'], $this->hana->getKey())
        ->assertSet('panelId', $this->hana->getKey())
        ->assertSet('mentorId', (string) $guru->getKey())
        ->assertSet('panelNote', 'Latihan soal tiap Kamis.')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: Talenta::SAVED_MESSAGE, tone: 'success')
        ->assertNotDispatched('sth-toast', message: Talenta::PANEL_MOVED_MESSAGE, tone: 'warning');

    expect($this->hana->fresh()->note)->toBe('Latihan soal tiap Kamis.')
        ->and((int) $this->hana->fresh()->mentor_id)->toBe((int) $guru->getKey())
        ->and($this->aurelia->fresh()->note)->toBeNull();
});

it('answers a "Simpan" for a row the panel no longer shows with a warning and the open row\'s own data (r3 review)', function (): void {
    // The click queued behind the search request still names Hana; the panel already holds Aurelia.
    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertSet('panelId', $this->aurelia->getKey())
        ->set('panelNote', 'Untuk Hana saja.')
        ->call('savePanel', $this->hana->getKey())
        ->assertOk()
        ->assertSet('panelId', $this->aurelia->getKey())
        ->assertSet('panelNote', '')
        ->assertDispatched('sth-toast', message: Talenta::PANEL_MOVED_MESSAGE, tone: 'warning');

    expect($this->hana->fresh()->note)->toBeNull()
        ->and($this->aurelia->fresh()->note)->toBeNull()
        ->and(Activity::query()->where('event', 'talent.assessment_updated')->exists())->toBeFalse();
});

it('keeps the panel and its draft while the tab "Belum mengisi" is open (r3 review)', function (): void {
    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $this->hana->getKey())
        ->set('panelNote', 'Draf catatan')
        ->call('setTab', 'belum')
        ->set('q', 'aurelia')
        ->assertSet('panelId', $this->hana->getKey())
        ->assertNotDispatched('sth-toast')
        ->set('q', '')
        ->call('setTab', 'semua')
        ->assertSet('panelId', $this->hana->getKey())
        ->assertSet('panelNote', 'Draf catatan')
        ->assertNotDispatched('sth-toast');
});

// r3 review, item 2: "still matches" = one of the rows the list shows ------------------------------------------------

it('opens the first shown row when the open row still matches but stands below the first page again (r3 review)', function (): void {
    // 30 newer choices in Hana's class: the first page (30 rows) holds only them.
    $this->travel(1)->minutes();
    $newest = null;

    foreach (range(1, 30) as $i) {
        $this->travel(1)->seconds();
        $newest = F::application(F::student('Siswa Contoh '.$i, 'X-8'));
    }

    $hanaClass = (string) Classroom::query()->where('name', 'X-8')->value('id');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertSet('panelId', $newest->getKey())
        ->call('showMore')
        ->assertSet('limit', 60)
        ->call('showPanel', $this->hana->getKey())
        ->assertSet('panelId', $this->hana->getKey())
        // Hana still matches the class, but she is row 31 of the first page of 30 the filter brings back.
        ->set('kelas', $hanaClass)
        ->assertSet('limit', 30)
        ->assertSet('panelId', $newest->getKey())
        ->assertViewHas('rows', fn ($rows): bool => $rows->count() === 30
            && ! $rows->contains(fn (array $row): bool => $row['id'] === $this->hana->getKey())
            && $rows->first()['selected'] === true)
        ->assertNotDispatched('sth-toast');
});

// Final review, item 1: words that hold for every cause ---------------------------------------------------------------

it('warns in words that hold when a click on another choice of the same student came with "Simpan" (final review)', function (): void {
    $hanaFisika = F::application($this->hana->student, TalentStatus::Terdaftar, 'Fisika');

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $this->hana->getKey())
        ->assertSet('panelId', $this->hana->getKey());

    // The panel's own list of Hana's choices: "Fisika" clicked, then "Simpan" of the Biologi row, in one request. No
    // search or filter: the list itself did not change.
    $component->update(calls: [
        ['method' => 'showPanel', 'params' => [$hanaFisika->getKey()], 'path' => ''],
        ['method' => 'savePanel', 'params' => [$this->hana->getKey()], 'path' => ''],
    ], updates: ['panelNote' => 'Untuk Biologi saja.'])
        ->assertSet('panelId', $hanaFisika->getKey())
        ->assertSet('panelNote', '')
        ->assertDispatched('sth-toast', message: Talenta::PANEL_MOVED_MESSAGE, tone: 'warning')
        ->assertNotDispatched('sth-toast', message: Talenta::SAVED_MESSAGE, tone: 'success');

    expect($this->hana->fresh()->note)->toBeNull()
        ->and($hanaFisika->fresh()->note)->toBeNull()
        ->and(Activity::query()->where('event', 'talent.assessment_updated')->exists())->toBeFalse();

    // Neither text claims a list change or "another student"; staff copy without "kamu" (D-119).
    expect(Talenta::PANEL_MOVED_MESSAGE)->toBe('Panel kini menampilkan pilihan talenta lain. Perubahan belum disimpan.')
        ->and(Talenta::PANEL_CLOSED_MESSAGE)->toBe('Panel sudah tertutup. Perubahan belum disimpan.')
        ->and(Talenta::PANEL_RELOADED_MESSAGE)->toBe('Perubahan belum disimpan. Panel menampilkan data yang tersimpan.');

    foreach ([Talenta::PANEL_MOVED_MESSAGE, Talenta::PANEL_CLOSED_MESSAGE, Talenta::PANEL_RELOADED_MESSAGE] as $message) {
        expect($message)->not->toContain('Daftar berubah')
            ->not->toContain('siswa lain')
            ->not->toMatch('/\bkamu\b/i');
    }
});

// Final review, item 2: a "Simpan" that finds the open row deleted by the student -------------------------------------

it('says the student deleted the open choice when a late "Simpan" finds it gone, and closes an empty list (final review)', function (): void {
    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertSet('panelId', $this->aurelia->getKey())
        ->set('q', 'hana')
        ->assertSet('panelId', $this->hana->getKey())
        ->assertNotDispatched('sth-toast');

    // Profil talenta "Hapus" (CancelTalentApplication) soft-deletes the row while the panel holds it.
    $this->hana->delete();

    // The "Simpan" sent before that render still names Aurelia, with a note typed for her.
    r3TalentaRequest($component, ['panelNote' => 'Untuk Aurelia saja.'], $this->aurelia->getKey())
        ->assertOk()
        ->assertDispatched('sth-toast', message: Talenta::CANCELLED_MESSAGE, tone: 'warning')
        // Nothing else matches "hana": the panel closes, and the second toast says so.
        ->assertSet('panelId', null)
        ->assertSet('panelNote', '')
        ->assertDispatched('sth-toast', message: Talenta::PANEL_CLOSED_MESSAGE, tone: 'warning')
        ->assertNotDispatched('sth-toast', message: Talenta::SAVED_MESSAGE, tone: 'success')
        ->assertSee('Pilih baris untuk melihat detail.');

    expect($this->aurelia->fresh()->note)->toBeNull()
        ->and(TalentApplication::withTrashed()->find($this->hana->getKey())->note)->toBeNull()
        ->and(Activity::query()->where('event', 'talent.assessment_updated')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('says the student deleted the open choice and shows the stored data when the first row left is the one "Simpan" named (final review)', function (): void {
    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertSet('panelId', $this->aurelia->getKey())
        ->call('showPanel', $this->hana->getKey())
        ->assertSet('panelId', $this->hana->getKey());

    $this->hana->delete();

    r3TalentaRequest($component, ['panelNote' => 'Untuk Aurelia saja.'], $this->aurelia->getKey())
        ->assertOk()
        ->assertDispatched('sth-toast', message: Talenta::CANCELLED_MESSAGE, tone: 'warning')
        // The first row left is Aurelia's, the row the button named: no "another choice" claim, nothing saved.
        ->assertSet('panelId', $this->aurelia->getKey())
        ->assertSet('panelNote', '')
        ->assertDispatched('sth-toast', message: Talenta::PANEL_RELOADED_MESSAGE, tone: 'warning')
        ->assertNotDispatched('sth-toast', message: Talenta::PANEL_MOVED_MESSAGE, tone: 'warning')
        ->assertNotDispatched('sth-toast', message: Talenta::SAVED_MESSAGE, tone: 'success')
        ->assertDontSee('Hana Safira');

    expect($this->aurelia->fresh()->note)->toBeNull()
        ->and(Activity::query()->where('event', 'talent.assessment_updated')->exists())->toBeFalse();
});
