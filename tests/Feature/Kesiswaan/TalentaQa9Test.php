<?php

declare(strict_types=1);

use App\Actions\Talent\UpdateTalentAssessment;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\UserStatus;
use App\Livewire\Kesiswaan\Talenta;
use App\Models\Classroom;
use App\Models\TalentApplication;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| QA9 (2026-10-09), Kesiswaan › Database talenta siswa, what is left after D-142 (no stages):
| 3. A choice the student deleted while it is open: a toast and a fresh panel, never a 404 page.
| 4. A guru pembina whose account left does not block Simpan while unchanged; the panel says so.
| 6. The Excel export pages through the rows in a unique order.
| 7. "N hasil sesuai filter" counts every matching row, not only the rows on screen.
| Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
});

// 3. A choice the student deleted ---------------------------------------------------------------------------

it('says the student deleted the choice instead of a 404 page (QA9)', function (string $method, bool $withId): void {
    $deleted = F::application(F::student('Hana Safira', 'X-8'));
    $other = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Pemetaan, 'Kimia');

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $deleted->getKey())
        ->assertSee('Hana Safira')
        ->set('panelNote', 'Tidak tersimpan.');

    // Profil talenta "Hapus" (CancelTalentApplication) soft-deletes the row.
    $deleted->delete();

    $component->call($method, ...($withId ? [$deleted->getKey()] : []))
        ->assertStatus(200)
        ->assertDispatched('sth-toast', message: Talenta::CANCELLED_MESSAGE, tone: 'warning')
        // The panel moves to the first row that is still there.
        ->assertSet('panelId', $other->getKey())
        ->assertDontSee('Hana Safira');

    expect(Talenta::CANCELLED_MESSAGE)->toBe('Siswa sudah menghapus pilihan ini.')
        ->and(TalentApplication::withTrashed()->find($deleted->getKey())->note)->toBeNull();
    Notification::assertNothingSent();
})->with([
    'showPanel' => ['showPanel', true],
    'savePanel' => ['savePanel', false],
]);

it('empties the panel when the student deleted the last choice (QA9)', function (): void {
    $deleted = F::application(F::student('Hana Safira', 'X-8'));

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)->assertSet('panelId', $deleted->getKey());

    $deleted->delete();

    $component->call('savePanel')
        ->assertDispatched('sth-toast', message: Talenta::CANCELLED_MESSAGE, tone: 'warning')
        ->assertSet('panelId', null)
        ->assertSee('Pilih baris untuk melihat detail.');
});

// 4. A guru pembina who left ----------------------------------------------------------------------------------

it('keeps an unchanged guru pembina who left from blocking the panel and says he is not active (QA9)', function (): void {
    $gone = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $otherGone = F::staff(RoleName::Guru, 'Bu Sari Wulandari');
    $row = F::application(F::student('Bima Satria Wicaksono', 'XII-7'), TalentStatus::Pemetaan, 'Fisika');
    $row->forceFill(['mentor_id' => $gone->getKey()])->save();
    $gone->forceFill(['status' => UserStatus::Disabled])->save();
    $otherGone->forceFill(['status' => UserStatus::Archived])->save();

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $row->getKey())
        ->assertSet('mentorId', (string) $gone->getKey())
        ->assertSee('Guru pembina yang tercatat, Pak Hendra Kusuma, tidak aktif lagi. Pilih guru pembina lain.')
        ->set('panelNote', 'Latihan soal tiap Kamis.')
        ->call('savePanel')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Data talenta tersimpan.', tone: 'success');

    expect($row->fresh()->note)->toBe('Latihan soal tiap Kamis.')
        ->and((int) $row->fresh()->mentor_id)->toBe((int) $gone->getKey());

    // Choosing another guru who left is still refused.
    $component->set('mentorId', (string) $otherGone->getKey())
        ->call('savePanel')
        ->assertHasErrors(['mentorId']);

    expect((int) $row->fresh()->mentor_id)->toBe((int) $gone->getKey());
});

it('accepts the unchanged guru pembina of the row in UpdateTalentAssessment::validated(), never another inactive one (QA9)', function (): void {
    $gone = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $row = F::application(F::student('Bima Satria Wicaksono', 'XII-7'), TalentStatus::Seleksi, 'Fisika');
    $other = F::application(F::student('Hana Safira', 'X-8'));
    $row->forceFill(['mentor_id' => $gone->getKey()])->save();
    $gone->forceFill(['status' => UserStatus::Archived])->save();
    $assessment = app(UpdateTalentAssessment::class);

    expect($assessment->validated(['mentor_id' => (string) $gone->getKey()], $row->fresh()))->toBe(['mentor_id' => (int) $gone->getKey()])
        ->and(UpdateTalentAssessment::isActiveGuru($gone->getKey()))->toBeFalse()
        ->and(fn () => $assessment->validated(['mentor_id' => (string) $gone->getKey()], $other))
        ->toThrow(ValidationException::class, 'Pilih guru pembina dari daftar.')
        ->and(fn () => $assessment->validated(['mentor_id' => (string) $gone->getKey()]))
        ->toThrow(ValidationException::class, 'Pilih guru pembina dari daftar.');
});

// 6. The export pages in a unique order ----------------------------------------------------------------------

it('pages through the export rows in a unique order (QA9)', function (): void {
    F::application(F::student('Hana Safira', 'X-8'));
    session()->put('auth.password_confirmed_at', time());
    $queries = collect();
    DB::listen(fn (QueryExecuted $query) => $queries->push($query->sql));

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('export')
        ->assertFileDownloaded();

    // Rows created in the same second used to share their place in "order by created_at": a page could repeat or
    // skip one of them. The key breaks the tie.
    $page = $queries->first(fn (string $sql): bool => str_contains($sql, 'from "talent_applications"') && str_contains($sql, 'limit 500'));

    expect($page)->not->toBeNull()
        ->and($page)->toContain('order by "created_at" asc, "id" asc');
});

// 7. "N hasil sesuai filter" ------------------------------------------------------------------------------------

it('counts every row that matches the filter, not only the rows on screen (QA9)', function (): void {
    foreach (range(1, 31) as $i) {
        F::application(F::student('Siswa Contoh '.$i, 'X-8'));
    }

    F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Terdaftar, 'Kimia');
    $kelas = (string) Classroom::query()->where('name', 'X-8')->value('id');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('kelas', $kelas)
        ->assertSee('Menampilkan 30 dari 31 hasil sesuai filter')
        ->call('showMore')
        ->assertSee('31 hasil sesuai filter')
        ->assertDontSee('Menampilkan 31 dari');
});
