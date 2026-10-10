<?php

declare(strict_types=1);

use App\Actions\Talent\MoveTalentStage;
use App\Actions\Talent\RejectTalentApplication;
use App\Actions\Talent\RestoreTalentApplication;
use App\Actions\Talent\UpdateTalentAssessment;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\UserStatus;
use App\Livewire\Kesiswaan\Talenta;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Notifications\Talent\TalentApplicationRejected;
use App\Notifications\Talent\TalentStageChanged;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| QA9 (2026-10-09), Kesiswaan › Talenta siswa:
| 2. A second tab that still shows the old stage never moves a row a second time (row icon, panel, bulk bar).
| 3. A choice the student cancelled while it is open: a toast and a fresh panel, never a 404 page.
| 4. A guru pembina whose account left does not block Simpan / Lolos / Tidak lolos while unchanged; the panel says so.
| 5. "Siswa diberi tahu" only when the student was told (alumni and disabled accounts get no notice).
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

/** A student whose account can no longer log in (an alumnus by default). */
function qa9FrozenStudent(string $name, string $class, UserStatus $status = UserStatus::Archived): Student
{
    $student = F::student($name, $class);
    $student->user->forceFill(['status' => $status])->save();

    return $student->fresh(['user']);
}

// 2. A stale tab never moves a row twice -------------------------------------------------------------------

it('does not move a row twice when a second tab clicks the row icon of a stage that is gone (QA9)', function (): void {
    $row = F::application(F::student('Hana Safira', 'X-8'));

    $first = Livewire::actingAs($this->kesiswaan)->test(Talenta::class);
    $second = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertSeeHtml('aria-label="Lolos ke Pemetaan"');

    $first->call('advanceRow', $row->getKey())
        ->assertDispatched('sth-toast', message: 'Dipindahkan ke Pemetaan.', tone: 'success');

    $second->call('advanceRow', $row->getKey())
        ->assertDispatched('sth-toast', message: 'Pilihan ini sudah dipindahkan ke Pemetaan. Daftar sudah diperbarui.', tone: 'warning')
        ->assertSeeHtml('aria-label="Lolos ke Seleksi/asesmen"');

    expect($row->fresh()->status)->toBe(TalentStatus::Pemetaan)
        ->and(Activity::query()->where('event', 'talent.stage_changed')->count())->toBe(1);
    Notification::assertSentToTimes($row->student->user, TalentStageChanged::class, 1);

    // Once the tab shows the new stage, its click is meant: the row goes on to Seleksi.
    $second->call('advanceRow', $row->getKey())
        ->assertDispatched('sth-toast', message: 'Dipindahkan ke Seleksi/asesmen.', tone: 'success');

    expect($row->fresh()->status)->toBe(TalentStatus::Seleksi);
});

it('does not move the panel row twice when a second tab presses "Lolos ke …" (QA9)', function (): void {
    $row = F::application(F::student('Hana Safira', 'X-8'));

    // Both tabs open the first row in the panel.
    $first = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)->assertSet('panelId', $row->getKey());
    $second = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)->assertSet('panelId', $row->getKey())
        ->set('panelStudentNote', 'Ketik di tab kedua.');

    $first->call('advance')
        ->assertSet('panelDone', 'Dipindahkan dari Terdaftar ke Pemetaan. Siswa diberi tahu.');

    $second->call('advance')
        ->assertDispatched('sth-toast', message: 'Pilihan ini sudah dipindahkan ke Pemetaan. Daftar sudah diperbarui.', tone: 'warning')
        ->assertSet('panelDone', null)
        // What was typed in the panel stays; the panel now offers the next stage.
        ->assertSet('panelStudentNote', 'Ketik di tab kedua.')
        ->assertSee('Lolos ke Seleksi/asesmen');

    expect($row->fresh()->status)->toBe(TalentStatus::Pemetaan)
        ->and($row->fresh()->student_note)->toBeNull();
    Notification::assertSentToTimes($row->student->user, TalentStageChanged::class, 1);
});

it('skips the checked rows another tab already moved in a bulk move (QA9)', function (): void {
    $moved = F::application(F::student('Hana Safira', 'X-8'));
    $waiting = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Terdaftar, 'Kimia');

    $second = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('checked', [$moved->getKey(), $waiting->getKey()])
        ->assertSee('Pindahkan ke Pemetaan');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)->call('advanceRow', $moved->getKey());

    // D-128: the bulk move asks first; the question already leaves the moved row out (TalentaPindahkanDialogTest).
    $second->call('askBulkAdvance')
        ->assertDispatched('sth-modal-open', id: 'pindahkan-talenta')
        ->call('bulkAdvance')
        ->assertDispatched('sth-toast', message: '1 pilihan talenta dipindahkan ke tahap berikutnya. 1 sudah dipindahkan lebih dulu dan tidak diubah lagi. Daftar sudah diperbarui.', tone: 'warning')
        ->assertSet('checked', []);

    expect($moved->fresh()->status)->toBe(TalentStatus::Pemetaan)
        ->and($waiting->fresh()->status)->toBe(TalentStatus::Pemetaan);
    Notification::assertSentToTimes($moved->student->user, TalentStageChanged::class, 1);
});

it('moves no row the screen did not draw and claims nothing about its stage (QA9)', function (): void {
    $shown = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Pemetaan, 'Kimia');
    $hidden = F::application(F::student('Hana Safira', 'X-8'));

    // Tab "Pemetaan": the Terdaftar row is neither listed nor in the panel (a forged call, or a page from before QA9).
    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('setTahap', 'pemetaan')
        ->call('showPanel', $shown->getKey())
        ->assertDontSee('Hana Safira')
        ->call('advanceRow', $hidden->getKey())
        ->assertDispatched('sth-toast', message: 'Daftar sudah diperbarui. Periksa tahapnya, lalu coba lagi.', tone: 'warning');

    expect($hidden->fresh()->status)->toBe(TalentStatus::Terdaftar);
    Notification::assertNothingSent();
});

it('rejects nothing when the panel row was moved while the "Tidak lolos" dialog was open, and keeps the note (QA9)', function (): void {
    $row = F::application(F::student('Hana Safira', 'X-8'));

    $second = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertSet('panelId', $row->getKey())
        ->set('panelStudentNote', 'Belum memenuhi kuota tahun ini.')
        ->call('askReject')
        ->assertDispatched('sth-modal-open', id: 'tidak-lolos-talenta');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)->call('advanceRow', $row->getKey());

    $second->call('reject')
        ->assertDispatched('sth-toast', message: 'Pilihan ini sudah dipindahkan ke Pemetaan. Daftar sudah diperbarui.', tone: 'warning')
        ->assertDispatched('sth-modal-close', id: 'tidak-lolos-talenta')
        ->assertSet('rejectTarget', '')
        ->assertSet('panelDone', null)
        ->assertSet('panelStudentNote', 'Belum memenuhi kuota tahun ini.')
        ->assertSee('Lolos ke Seleksi/asesmen');

    expect($row->fresh()->status)->toBe(TalentStatus::Pemetaan)
        ->and($row->fresh()->student_note)->toBeNull();
    Notification::assertNotSentTo($row->student->user, TalentApplicationRejected::class);
});

it('opens no "Tidak lolos" dialog for a panel row a colleague already marked tidak lolos (QA9)', function (): void {
    $row = F::application(F::student('Hana Safira', 'X-8'), TalentStatus::Seleksi, 'Fisika');

    $second = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertSet('panelId', $row->getKey())
        ->set('panelStudentNote', 'Catatan dari tab kedua.');

    app(RejectTalentApplication::class)->handle($this->kesiswaan, $row->fresh(), 'Nilai asesmen kurang.');

    $second->call('askReject')
        ->assertDispatched('sth-toast', message: 'Pilihan ini sudah ditandai tidak lolos. Daftar sudah diperbarui.', tone: 'warning')
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('rejectTarget', '')
        ->assertSet('panelStudentNote', 'Catatan dari tab kedua.');

    expect($row->fresh()->student_note)->toBe('Nilai asesmen kurang.');
    Notification::assertSentToTimes($row->student->user, TalentApplicationRejected::class, 1);
});

it('skips and counts the checked rows a colleague moved or rejected meanwhile in a bulk "Tidak lolos" (QA9)', function (): void {
    $moved = F::application(F::student('Hana Safira', 'X-8'));
    $rejected = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Terdaftar, 'Kimia');
    $waiting = F::application(F::student('Bima Satria Wicaksono', 'XII-7'), TalentStatus::Terdaftar, 'Fisika');

    // The note is typed before the colleague acts (in the browser the field sends it only with "Tidak lolos").
    $second = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('checked', [$moved->getKey(), $rejected->getKey(), $waiting->getKey()])
        ->call('askBulkReject')
        ->assertDispatched('sth-modal-open', id: 'tidak-lolos-talenta')
        ->set('bulkNote', 'Belum lolos penjaringan.');

    app(MoveTalentStage::class)->handle($this->kesiswaan, $moved, TalentStatus::Pemetaan);
    app(RejectTalentApplication::class)->handle($this->kesiswaan, $rejected, 'Kuota penuh.');

    $second->call('reject')
        ->assertDispatched('sth-toast', message: '1 pilihan talenta ditandai tidak lolos. Siswa diberi tahu. 1 sudah dipindahkan lebih dulu dan tidak diubah lagi. 1 sudah ditandai tidak lolos lebih dulu. Daftar sudah diperbarui.', tone: 'warning')
        ->assertSet('checked', []);

    expect($moved->fresh()->status)->toBe(TalentStatus::Pemetaan)
        ->and($rejected->fresh()->student_note)->toBe('Kuota penuh.')
        ->and($waiting->fresh()->status)->toBe(TalentStatus::TidakLolos)
        ->and($waiting->fresh()->student_note)->toBe('Belum lolos penjaringan.');
    Notification::assertNotSentTo($moved->student->user, TalentApplicationRejected::class);
    Notification::assertSentToTimes($rejected->student->user, TalentApplicationRejected::class, 1);
    Notification::assertSentToTimes($waiting->student->user, TalentApplicationRejected::class, 1);
});

// 3. The student cancelled the choice meanwhile -------------------------------------------------------------

it('says the student cancelled the choice instead of a 404 page (QA9)', function (string $method, bool $withId): void {
    $cancelled = F::application(F::student('Hana Safira', 'X-8'));
    $other = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Pemetaan, 'Kimia');

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $cancelled->getKey())
        ->assertSee('Hana Safira');

    // Profil talenta "Batalkan" (CancelTalentApplication) soft-deletes the row.
    $cancelled->delete();

    $component->call($method, ...($withId ? [$cancelled->getKey()] : []))
        ->assertStatus(200)
        ->assertDispatched('sth-toast', message: 'Siswa sudah membatalkan pilihan ini.', tone: 'warning')
        ->assertNotDispatched('sth-modal-open')
        // The panel moves to the first row that is still there.
        ->assertSet('panelId', $other->getKey())
        ->assertDontSee('Hana Safira');

    expect(TalentApplication::withTrashed()->find($cancelled->getKey())->status)->toBe(TalentStatus::Terdaftar);
    Notification::assertNothingSent();
})->with([
    'showPanel' => ['showPanel', true],
    'advanceRow' => ['advanceRow', true],
    'advance' => ['advance', false],
    'savePanel' => ['savePanel', false],
    'askReject' => ['askReject', false],
]);

it('closes the "Tidak lolos" dialog when the student cancelled the choice while it was open (QA9)', function (): void {
    $cancelled = F::application(F::student('Hana Safira', 'X-8'));

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $cancelled->getKey())
        ->call('askReject')
        ->assertDispatched('sth-modal-open', id: 'tidak-lolos-talenta');

    $cancelled->delete();

    $component->call('reject')
        ->assertStatus(200)
        ->assertDispatched('sth-toast', message: 'Siswa sudah membatalkan pilihan ini.', tone: 'warning')
        ->assertDispatched('sth-modal-close', id: 'tidak-lolos-talenta')
        ->assertSet('rejectTarget', '')
        ->assertSet('panelId', null)
        ->assertSee('Pilih baris untuk melihat detail.');

    Notification::assertNothingSent();
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
        ->assertDispatched('sth-toast', message: 'Data asesmen tersimpan.', tone: 'success')
        ->call('askReject')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-open', id: 'tidak-lolos-talenta');

    expect($row->fresh()->note)->toBe('Latihan soal tiap Kamis.')
        ->and((int) $row->fresh()->mentor_id)->toBe((int) $gone->getKey());

    $component->call('advance')->assertHasNoErrors();

    expect($row->fresh()->status)->toBe(TalentStatus::Seleksi)
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
        ->and(fn () => $assessment->validated(['mentor_id' => (string) $gone->getKey()], $other))
        ->toThrow(ValidationException::class, 'Pilih guru pembina dari daftar.')
        ->and(fn () => $assessment->validated(['mentor_id' => (string) $gone->getKey()]))
        ->toThrow(ValidationException::class, 'Pilih guru pembina dari daftar.');
});

// 5. "Siswa diberi tahu" only when someone was told -----------------------------------------------------------

it('answers in the Actions whether the student is told (QA9)', function (UserStatus $status, bool $told): void {
    $row = F::application(qa9FrozenStudent('Rizky Maulana', 'XII-1', $status));

    expect(MoveTalentStage::notifiesStudent($row))->toBe($told)
        ->and(RejectTalentApplication::notifiesStudent($row))->toBe($told)
        ->and(RestoreTalentApplication::notifiesStudent($row))->toBe($told);
})->with([
    'active' => [UserStatus::Active, true],
    'pending activation' => [UserStatus::PendingActivation, true],
    'alumnus' => [UserStatus::Archived, false],
    'disabled' => [UserStatus::Disabled, false],
]);

it('claims no notice in the panel, the dialogs and the restore toast for an alumnus (QA9)', function (): void {
    $row = F::application(qa9FrozenStudent('Rizky Maulana', 'XII-1'));

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $row->getKey())
        ->set('panelStudentNote', 'Selamat, lanjut ke pemetaan.')
        ->call('advance')
        ->assertHasNoErrors()
        ->assertSet('panelDone', 'Dipindahkan dari Terdaftar ke Pemetaan.')
        ->call('askReject')
        ->assertSee('Akun siswa tidak aktif, jadi tidak ada notifikasi.')
        ->assertDontSee('Siswa langsung diberi tahu.')
        ->call('reject')
        ->assertSet('panelDone', 'Tidak lolos dari tahap Pemetaan.')
        ->assertSee('Tahap sebelumnya: Pemetaan. Pulihkan untuk mengembalikannya ke tahap yang dipilih.')
        ->assertDontSee('Siswa sudah diberi tahu.')
        ->call('askRestore', $row->getKey())
        ->assertSee('Pilih tahap tujuannya. Akun siswa tidak aktif, jadi tidak ada notifikasi.')
        ->call('restore')
        ->assertDispatched('sth-toast', message: 'Dipulihkan ke tahap Pemetaan.', tone: 'success');

    expect($row->fresh()->status)->toBe(TalentStatus::Pemetaan);
    Notification::assertNothingSent();
});

it('counts in the bulk "Tidak lolos" toast only the students who were told (QA9)', function (): void {
    $active = F::application(F::student('Hana Safira', 'X-8'));
    $alumnus = F::application(qa9FrozenStudent('Rizky Maulana', 'XII-1'), TalentStatus::Pemetaan, 'Kimia');
    $disabled = F::application(qa9FrozenStudent('Fajar Ramadhan', 'XII-2', UserStatus::Disabled), TalentStatus::Seleksi, 'Fisika');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('checked', [$active->getKey(), $alumnus->getKey()])
        ->call('askBulkReject')
        ->assertSee('Siswa yang akunnya aktif langsung diberi tahu.')
        ->call('reject')
        ->assertDispatched('sth-toast', message: '2 pilihan talenta ditandai tidak lolos. Siswa diberi tahu untuk 1 dari 2 pilihan.', tone: 'success')
        ->set('checked', [$disabled->getKey()])
        ->call('askBulkReject')
        ->assertSee('Akun siswa tidak aktif, jadi tidak ada notifikasi.')
        ->call('reject')
        ->assertDispatched('sth-toast', message: '1 pilihan talenta ditandai tidak lolos.', tone: 'success');

    expect($alumnus->fresh()->status)->toBe(TalentStatus::TidakLolos)
        ->and($disabled->fresh()->status)->toBe(TalentStatus::TidakLolos);
    Notification::assertCount(1);
});

// 6. Export: a unique order for the pages of 500 --------------------------------------------------------------

it('pages through the export rows in a unique order (QA9)', function (): void {
    F::application(F::student('Hana Safira', 'X-8'));
    session()->put('auth.password_confirmed_at', time());
    $queries = collect();
    DB::listen(fn (QueryExecuted $query) => $queries->push($query->sql));

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('export', 'semua')
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

it('judges the checked rows of a bulk "Tidak lolos" without a query per row (QA9 review)', function (): void {
    // The dialog says per row whether the student is told: that asked the database twice for every checked row (up to
    // 300), on every redraw while the dialog was open.
    $ids = collect(range(1, 6))
        ->map(fn (int $i): string => F::application(F::student('Siswa Uji '.$i, 'X-'.$i), TalentStatus::Terdaftar, 'Kimia')->getKey())
        ->all();

    $queriesFor = function (array $checked): int {
        $screen = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)->set('checked', $checked);
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $screen->call('askBulkReject')->assertDispatched('sth-modal-open', id: 'tidak-lolos-talenta');

        return $count;
    };

    $two = $queriesFor(array_slice($ids, 0, 2));
    $six = $queriesFor($ids);

    expect($six - $two)->toBeLessThan(4);
});
