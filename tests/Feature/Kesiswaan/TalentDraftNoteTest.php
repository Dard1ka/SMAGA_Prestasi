<?php

declare(strict_types=1);

use App\Actions\Talent\MoveTalentStage;
use App\Actions\Talent\RejectTalentApplication;
use App\Actions\Talent\RestoreTalentApplication;
use App\Actions\Talent\UpdateTalentAssessment;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Livewire\Kesiswaan\Talenta;
use App\Livewire\Siswa\ProfilTalenta;
use App\Models\TalentApplication;
use App\Notifications\Talent\TalentApplicationRejected;
use App\Notifications\Talent\TalentApplicationRestored;
use App\Notifications\Talent\TalentStageChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| M2-B (QA 2026-10-05). Q2-14: "Simpan" keeps a typed "Catatan untuk siswa" as a draft on the row
| (`student_note_draft`); only a decision sends it, the student never sees a draft. Q2-15: "Tidak lolos",
| panel and bulk, goes through a confirm dialog. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
});

// --- Actions: the draft ---------------------------------------------------------------------------------

it('keeps the note for the student as a draft on "Simpan": nothing is sent and the sent note stays as it is', function (): void {
    $application = F::application();
    $application->forceFill(['student_note' => 'Catatan keputusan lama.'])->save();

    app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application, [
        'note' => 'Hubungi wali kelas dulu.',
        'student_note_draft' => "  Siapkan rapor semester 1.\nBawa saat pemetaan.  ",
    ]);

    $fresh = $application->fresh();
    expect($fresh->student_note_draft)->toBe("Siapkan rapor semester 1.\nBawa saat pemetaan.")
        ->and($fresh->student_note)->toBe('Catatan keputusan lama.')
        ->and($fresh->note)->toBe('Hubungi wali kelas dulu.')
        ->and($fresh->status)->toBe(TalentStatus::Terdaftar)
        ->and(Activity::query()->where('event', 'talent.assessment_updated')->sole()->causer_id)->toBe($this->kesiswaan->getKey())
        ->and(DB::table('notifications')->count())->toBe(0);
    Notification::assertNothingSent();

    // An emptied field removes the draft; a call without the key leaves it alone.
    app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $fresh, ['student_note_draft' => 'Draf kedua.']);
    app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $fresh, ['note' => 'Catatan pembina baru.']);
    expect($application->fresh()->student_note_draft)->toBe('Draf kedua.');

    app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $fresh, ['student_note_draft' => '   ']);
    expect($application->fresh()->student_note_draft)->toBeNull();
    Notification::assertNothingSent();
});

it('limits the draft to 1.000 characters and refuses it from other roles', function (): void {
    $application = F::application();

    expect(fn () => app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application, ['student_note_draft' => str_repeat('a', 1001)]))
        ->toThrow(ValidationException::class, 'Catatan maksimal 1.000 karakter.');

    foreach ([$this->guru, F::staff(RoleName::Humas, 'Mas Galih'), F::staff(RoleName::TataUsaha, 'Staf TU'), F::staff(RoleName::KepalaSekolah, 'Kepala'), $application->student->user] as $user) {
        expect(fn () => app(UpdateTalentAssessment::class)->handle($user, $application, ['student_note_draft' => 'Dari peran lain.']))
            ->toThrow(AuthorizationException::class);
    }

    expect($application->fresh()->student_note_draft)->toBeNull();
});

it('sends the draft with the next move that passes no note, and clears it', function (): void {
    $application = F::application();
    app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application, ['student_note_draft' => 'Siapkan rapor semester 1.']);

    app(MoveTalentStage::class)->handle($this->kesiswaan, $application->fresh(), TalentStatus::Pemetaan);

    $fresh = $application->fresh();
    expect($fresh->status)->toBe(TalentStatus::Pemetaan)
        ->and($fresh->student_note)->toBe('Siapkan rapor semester 1.')
        ->and($fresh->student_note_draft)->toBeNull()
        ->and(Activity::query()->where('event', 'talent.stage_changed')->sole()->properties['student_note_from_draft'])->toBeTrue();

    Notification::assertSentTo($application->student->user, TalentStageChanged::class, fn (TalentStageChanged $n): bool => str_contains($n->body, 'Catatan dari Kesiswaan: Siapkan rapor semester 1.'));

    // The draft went out once: the next move has no note.
    app(MoveTalentStage::class)->handle($this->kesiswaan, $fresh, TalentStatus::Seleksi);
    expect($application->fresh()->student_note)->toBeNull();
});

it('lets a text typed for the decision win over the draft, and an emptied field mean "no note"', function (): void {
    $typed = F::application();
    $emptied = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Pemetaan, 'Kimia');

    foreach ([$typed, $emptied] as $application) {
        app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application, ['student_note_draft' => 'Draf lama.']);
    }

    app(MoveTalentStage::class)->handle($this->kesiswaan, $typed->fresh(), TalentStatus::Pemetaan, ['student_note' => 'Selamat, lolos pemetaan.']);
    app(RejectTalentApplication::class)->handle($this->kesiswaan, $emptied->fresh(), '');

    expect($typed->fresh()->student_note)->toBe('Selamat, lolos pemetaan.')
        ->and($typed->fresh()->student_note_draft)->toBeNull()
        ->and($emptied->fresh()->status)->toBe(TalentStatus::TidakLolos)
        ->and($emptied->fresh()->student_note)->toBeNull()
        ->and($emptied->fresh()->student_note_draft)->toBeNull();

    Notification::assertSentTo($typed->student->user, TalentStageChanged::class, fn (TalentStageChanged $n): bool => str_contains($n->body, 'Selamat, lolos pemetaan.') && ! str_contains($n->body, 'Draf lama'));
    Notification::assertSentTo($emptied->student->user, TalentApplicationRejected::class, fn (TalentApplicationRejected $n): bool => ! str_contains($n->body, 'Catatan dari Kesiswaan'));
});

it('sends the draft with a rejection and with a restore that pass no note', function (): void {
    $application = F::application(status: TalentStatus::Seleksi);
    app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application, ['student_note_draft' => 'Nilai asesmen belum cukup.']);

    app(RejectTalentApplication::class)->handle($this->kesiswaan, $application->fresh());

    expect($application->fresh()->student_note)->toBe('Nilai asesmen belum cukup.')
        ->and($application->fresh()->student_note_draft)->toBeNull()
        ->and(Activity::query()->where('event', 'talent.rejected')->sole()->properties['student_note_from_draft'])->toBeTrue();
    Notification::assertSentTo($application->student->user, TalentApplicationRejected::class, fn (TalentApplicationRejected $n): bool => str_contains($n->body, 'Catatan dari Kesiswaan: Nilai asesmen belum cukup.'));

    $application->fresh()->forceFill(['student_note_draft' => 'Asesmen susulan Jumat ini.'])->save();
    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application->fresh());

    expect($application->fresh()->status)->toBe(TalentStatus::Seleksi)
        ->and($application->fresh()->student_note)->toBe('Asesmen susulan Jumat ini.')
        ->and($application->fresh()->student_note_draft)->toBeNull();
    Notification::assertSentTo($application->student->user, TalentApplicationRestored::class, fn (TalentApplicationRestored $n): bool => str_contains($n->body, 'Catatan dari Kesiswaan: Asesmen susulan Jumat ini.'));
});

// --- Screen: "Simpan" and the draft (Q2-14) ---------------------------------------------------------------

it('saves the typed note as a draft, says when it is sent, and shows it again after a reload', function (): void {
    $application = F::application(F::student('Hana Safira', 'X-8'));
    $student = $application->student->user;

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $application->getKey())
        ->assertSee('Simpan hanya menyimpan draf.')
        ->assertDontSee('Draf tersimpan, belum terkirim.')
        ->set('panelNote', 'Minta nilai rapor ke wali kelas.')
        ->set('panelStudentNote', 'Siapkan sertifikat SMP.')
        ->call('savePanel')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Tersimpan. Catatan untuk siswa terkirim saat Lolos atau Tidak lolos ditekan.', tone: 'success')
        ->assertSet('panelStudentNote', 'Siapkan sertifikat SMP.')
        ->assertSee('Draf tersimpan, belum terkirim.');

    expect($application->fresh()->student_note_draft)->toBe('Siapkan sertifikat SMP.')
        ->and($application->fresh()->student_note)->toBeNull()
        ->and($application->fresh()->status)->toBe(TalentStatus::Terdaftar);
    Notification::assertNothingSent();

    // The student sees and receives nothing before a decision.
    Livewire::actingAs($student)->test(ProfilTalenta::class)->assertDontSee('Siapkan sertifikat SMP.');
    $this->actingAs($student)->get(route('siswa.talenta'))->assertOk()->assertDontSee('Siapkan sertifikat SMP.');
    expect($student->notifications()->count())->toBe(0);

    // A reload (a new component) shows the draft in the field again; "Lolos" then sends it.
    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $application->getKey())
        ->assertSet('panelStudentNote', 'Siapkan sertifikat SMP.')
        ->assertSee('Draf tersimpan, belum terkirim.')
        ->call('advance')
        ->assertHasNoErrors()
        ->assertSet('panelStudentNote', '')
        ->assertSet('panelDone', 'Dipindahkan dari Terdaftar ke Pemetaan. Siswa diberi tahu, catatan terkirim.')
        ->assertDontSee('Draf tersimpan, belum terkirim.');

    expect($application->fresh()->student_note)->toBe('Siapkan sertifikat SMP.')
        ->and($application->fresh()->student_note_draft)->toBeNull();
    Notification::assertSentTo($student, TalentStageChanged::class, fn (TalentStageChanged $n): bool => str_contains($n->body, 'Siapkan sertifikat SMP.'));
    Livewire::actingAs($student)->test(ProfilTalenta::class)->assertSee('Siapkan sertifikat SMP.');
});

it('says plainly "Data asesmen tersimpan." without a note for the student, and an emptied field removes the draft', function (): void {
    $application = F::application();
    $application->forceFill(['student_note_draft' => 'Draf yang tidak jadi.'])->save();

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $application->getKey())
        ->assertSet('panelStudentNote', 'Draf yang tidak jadi.')
        ->set('panelStudentNote', '')
        ->call('savePanel')
        ->assertDispatched('sth-toast', message: 'Data asesmen tersimpan.', tone: 'success');

    expect($application->fresh()->student_note_draft)->toBeNull();
    Notification::assertNothingSent();
});

it('keeps no draft on a row that gets no more decision', function (): void {
    $final = F::application(status: TalentStatus::Pembinaan);

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $final->getKey())
        // The field is not drawn for a final row; a forged value is not stored either.
        ->set('panelStudentNote', 'Tidak punya tujuan.')
        ->set('panelNote', 'Sudah ikut pembinaan.')
        ->call('savePanel')
        ->assertDispatched('sth-toast', message: 'Data asesmen tersimpan.', tone: 'success');

    expect($final->fresh()->student_note_draft)->toBeNull()
        ->and($final->fresh()->note)->toBe('Sudah ikut pembinaan.');
});

it('sends a saved draft with the row button and with a bulk move, and says so', function (): void {
    $a = F::application(F::student('Hana Safira', 'X-8'));
    $b = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Pemetaan, 'Kimia');
    $c = F::application(F::student('Galang Putra Mahendra', 'X-TAHFIDZ'), TalentStatus::Pemetaan, 'Fisika');
    $a->forceFill(['student_note_draft' => 'Selamat, lanjut ke pemetaan.'])->save();
    $b->forceFill(['student_note_draft' => 'Asesmen hari Jumat.'])->save();

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('advanceRow', $a->getKey())
        ->assertDispatched('sth-toast', message: 'Dipindahkan ke Pemetaan. Catatan untuk siswa yang tersimpan ikut terkirim.', tone: 'success')
        ->set('checked', [$b->getKey(), $c->getKey()])
        ->call('bulkAdvance')
        ->assertDispatched('sth-toast', message: '2 pilihan talenta dipindahkan ke tahap berikutnya. 1 catatan untuk siswa yang tersimpan ikut terkirim.', tone: 'success');

    expect($a->fresh()->student_note)->toBe('Selamat, lanjut ke pemetaan.')
        ->and($a->fresh()->student_note_draft)->toBeNull()
        ->and($b->fresh()->student_note)->toBe('Asesmen hari Jumat.')
        ->and($b->fresh()->student_note_draft)->toBeNull()
        ->and($c->fresh()->status)->toBe(TalentStatus::Seleksi)
        ->and($c->fresh()->student_note)->toBeNull();
});

// --- Screen: "Tidak lolos" asks first (Q2-15) -------------------------------------------------------------

it('opens a confirm dialog for "Tidak lolos" in the panel and rejects only after the confirmation', function (): void {
    $application = F::application(F::student('Bima Satria Wicaksono', 'XII-7'), TalentStatus::Seleksi, 'Kimia');
    $student = $application->student->user;

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $application->getKey())
        ->assertSeeHtml('wire:click="askReject"')
        ->assertDontSeeHtml('wire:click="rejectPanel"')
        ->set('panelStudentNote', 'Coba lagi tahun depan.')
        ->call('askReject')
        ->assertDispatched('sth-modal-open', id: 'tidak-lolos-talenta')
        ->assertSet('rejectTarget', 'panel')
        ->assertSee('Tandai tidak lolos?')
        ->assertSee('Siswa langsung diberi tahu.')
        ->assertSee('tidak lolos dari tahap Seleksi/asesmen. Bisa dipulihkan lagi nanti.')
        ->assertSee('Coba lagi tahun depan.');

    // The click only opened the dialog.
    expect($application->fresh()->status)->toBe(TalentStatus::Seleksi);
    Notification::assertNothingSent();

    $component->call('reject')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-close', id: 'tidak-lolos-talenta')
        ->assertSet('rejectTarget', '')
        ->assertSet('panelDone', 'Tidak lolos dari tahap Seleksi/asesmen. Siswa diberi tahu, catatan terkirim.');

    expect($application->fresh()->status)->toBe(TalentStatus::TidakLolos)
        ->and($application->fresh()->student_note)->toBe('Coba lagi tahun depan.');
    Notification::assertSentToTimes($student, TalentApplicationRejected::class, 1);
});

it('says "Tanpa catatan untuk siswa." in the dialog and stops at a field error before opening it', function (): void {
    $application = F::application();

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $application->getKey())
        ->set('panelStudentNote', str_repeat('a', 1001))
        ->call('askReject')
        ->assertHasErrors('panelStudentNote')
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('rejectTarget', '')
        ->set('panelStudentNote', '')
        ->call('askReject')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-open', id: 'tidak-lolos-talenta')
        ->assertSee('Tanpa catatan untuk siswa.');

    expect($application->fresh()->status)->toBe(TalentStatus::Terdaftar);
});

it('has nothing to reject without a dialog, keeps the dialog state locked and drops it when another row is opened', function (): void {
    $a = F::application(F::student('Hana Safira', 'X-8'));
    $b = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Pemetaan, 'Kimia');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)->call('showPanel', $a->getKey())->call('reject')->assertNotFound();
    expect(fn () => Livewire::actingAs($this->kesiswaan)->test(Talenta::class)->set('rejectTarget', 'panel'))->toThrow(Exception::class);

    // Asked about row A, then row B is opened: the question is void.
    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $a->getKey())
        ->call('askReject')
        ->assertSet('rejectTarget', 'panel')
        ->call('showPanel', $b->getKey())
        ->assertSet('rejectTarget', '')
        ->call('reject')
        ->assertNotFound();

    expect($a->fresh()->status)->toBe(TalentStatus::Terdaftar)
        ->and($b->fresh()->status)->toBe(TalentStatus::Pemetaan);
    Notification::assertNothingSent();
});

it('opens the same dialog for a bulk "Tidak lolos": optional note, saved drafts are named and sent', function (): void {
    $a = F::application(F::student('Hana Safira', 'X-8'));
    $b = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Pemetaan, 'Kimia');
    $b->forceFill(['student_note_draft' => 'Nilai asesmen belum cukup.'])->save();

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertDontSeeHtml('wire:click="askBulkReject"')
        ->set('checked', [$a->getKey(), $b->getKey()])
        ->assertSeeHtml('wire:click="askBulkReject"')
        ->call('askBulkReject')
        ->assertDispatched('sth-modal-open', id: 'tidak-lolos-talenta')
        ->assertSet('rejectTarget', 'bulk')
        ->assertSee('pilihan talenta')
        ->assertSee('Catatan yang sama terkirim ke semua siswa yang dipilih.')
        ->assertSee('pilihan punya catatan untuk siswa yang tersimpan. Kalau kolom di atas kosong, catatan itu yang terkirim.');

    expect($a->fresh()->status)->toBe(TalentStatus::Terdaftar)
        ->and($b->fresh()->status)->toBe(TalentStatus::Pemetaan);
    Notification::assertNothingSent();

    $component->call('reject')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-close', id: 'tidak-lolos-talenta')
        ->assertDispatched('sth-toast', message: '2 pilihan talenta ditandai tidak lolos. Siswa diberi tahu. 1 catatan untuk siswa yang tersimpan ikut terkirim.', tone: 'success')
        ->assertSet('checked', [])
        ->assertSet('rejectTarget', '');

    expect($a->fresh()->status)->toBe(TalentStatus::TidakLolos)
        ->and($a->fresh()->student_note)->toBeNull()
        ->and($b->fresh()->status)->toBe(TalentStatus::TidakLolos)
        ->and($b->fresh()->student_note)->toBe('Nilai asesmen belum cukup.')
        ->and($b->fresh()->student_note_draft)->toBeNull();
    Notification::assertSentTo($a->student->user, TalentApplicationRejected::class);
    Notification::assertSentTo($b->student->user, TalentApplicationRejected::class);
});

it('sends a typed bulk note to every checked row instead of their drafts, and asks again when the selection changes', function (): void {
    $a = F::application(F::student('Hana Safira', 'X-8'));
    $b = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Pemetaan, 'Kimia');
    $c = F::application(F::student('Galang Putra Mahendra', 'X-TAHFIDZ'), TalentStatus::Pemetaan, 'Fisika');
    $b->forceFill(['student_note_draft' => 'Draf yang tidak terpakai.'])->save();

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('checked', [$a->getKey(), $b->getKey()])
        ->call('askBulkReject')
        // The selection changed under the open question: it is void.
        ->set('checked', [$a->getKey(), $b->getKey(), $c->getKey()])
        ->assertSet('rejectTarget', '')
        ->call('reject')
        ->assertNotFound();

    expect($c->fresh()->status)->toBe(TalentStatus::Pemetaan);

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('checked', [$a->getKey(), $b->getKey()])
        ->call('askBulkReject')
        ->set('bulkNote', 'Belum memenuhi kuota.')
        ->call('reject')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: '2 pilihan talenta ditandai tidak lolos. Siswa diberi tahu.', tone: 'success');

    expect($a->fresh()->student_note)->toBe('Belum memenuhi kuota.')
        ->and($b->fresh()->student_note)->toBe('Belum memenuhi kuota.')
        ->and($b->fresh()->student_note_draft)->toBeNull()
        ->and($c->fresh()->status)->toBe(TalentStatus::Pemetaan);
});

it('refuses the "Tidak lolos" dialog and the draft to every other role', function (): void {
    $application = F::application();
    $application->forceFill(['student_note_draft' => 'Draf Kesiswaan.'])->save();

    foreach ([$this->guru, F::staff(RoleName::Humas, 'Mas Galih'), F::staff(RoleName::TataUsaha, 'Staf TU'), F::staff(RoleName::KepalaSekolah, 'Kepala'), $application->student->user] as $user) {
        Livewire::actingAs($user)->test(Talenta::class)->assertForbidden();
        expect(fn () => app(RejectTalentApplication::class)->handle($user, $application))->toThrow(AuthorizationException::class);
    }

    expect($application->fresh()->status)->toBe(TalentStatus::Terdaftar)
        ->and($application->fresh()->student_note)->toBeNull()
        ->and($application->fresh()->student_note_draft)->toBe('Draf Kesiswaan.')
        ->and(TalentApplication::query()->where('status', TalentStatus::TidakLolos)->count())->toBe(0);
    Notification::assertNothingSent();
});
