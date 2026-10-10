<?php

declare(strict_types=1);

use App\Actions\Talent\MoveTalentStage;
use App\Actions\Talent\RejectTalentApplication;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\UserStatus;
use App\Livewire\Kesiswaan\Talenta;
use App\Notifications\Talent\TalentStageChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| D-128 (lead, 2026-10-10), sweep: Kesiswaan › Talenta › bulk "Pindahkan ke …" asks first. One click used to move every
| checked row (up to 300) one stage, tell each student and send the saved draft notes; a stage cannot be moved back.
| askBulkAdvance() opens the dialog "pindahkan-talenta" (count, final rows that are skipped, drafts that go out, who is
| told); bulkAdvance() is the confirm step and does nothing without that question. The question fixes which rows move
| and from which stage (QA9: a stale tab never moves a row twice). Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
});

it('asks before a bulk move and moves nothing yet (D-128)', function (): void {
    $a = F::application(F::student('Hana Safira', 'X-8'));
    $b = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Pemetaan, 'Kimia');
    $b->forceFill(['student_note_draft' => 'Asesmen hari Jumat.'])->save();

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        // The dialog is on the page with a description from the first render (the <dialog> keeps its attributes).
        ->assertSeeHtml('aria-describedby="pindahkan-talenta-desc"')
        ->assertDontSeeHtml('wire:click="askBulkAdvance"')
        ->set('checked', [$a->getKey(), $b->getKey()])
        ->assertSeeHtml('wire:click="askBulkAdvance"')
        ->assertDontSeeHtml('wire:click="bulkAdvance"')
        ->assertSeeHtml('wire:submit="bulkAdvance"')
        ->call('askBulkAdvance')
        ->assertDispatched('sth-modal-open', id: 'pindahkan-talenta')
        ->assertSet('advanceAsk', [
            'stages' => [$a->getKey() => 'terdaftar', $b->getKey() => 'pemetaan'],
            'final' => 0,
            'moved' => 0,
            'rejected' => 0,
        ])
        ->assertViewHas('advancing', ['count' => 2, 'skipped' => 0, 'stale' => 0, 'drafts' => 1, 'notice' => 'Siswa langsung diberi tahu.'])
        ->assertSee('Pindahkan 2 pilihan ke tahap berikutnya?')
        ->assertSee('Siswa langsung diberi tahu.')
        ->assertSee('naik satu tahap. Tahap tidak bisa dimundurkan.')
        ->assertSee('catatan untuk siswa yang tersimpan ikut terkirim.')
        ->assertDontSee('sudah final (Peserta pembinaan atau Tidak lolos) dan dilewati.')
        ->assertNotDispatched('sth-toast');

    expect($a->fresh()->status)->toBe(TalentStatus::Terdaftar)
        ->and($b->fresh()->status)->toBe(TalentStatus::Pemetaan)
        ->and($b->fresh()->student_note_draft)->toBe('Asesmen hari Jumat.');
    Notification::assertNothingSent();
});

it('moves the checked rows after the confirmation and closes the dialog (D-128)', function (): void {
    $a = F::application(F::student('Hana Safira', 'X-8'));
    $b = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Pemetaan, 'Kimia');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('checked', [$a->getKey(), $b->getKey()])
        ->call('askBulkAdvance')
        ->call('bulkAdvance')
        ->assertDispatched('sth-modal-close', id: 'pindahkan-talenta')
        ->assertDispatched('sth-toast', message: '2 pilihan talenta dipindahkan ke tahap berikutnya.', tone: 'success')
        ->assertSet('advanceAsk', null)
        ->assertSet('checked', []);

    expect($a->fresh()->status)->toBe(TalentStatus::Pemetaan)
        ->and($b->fresh()->status)->toBe(TalentStatus::Seleksi);
    Notification::assertSentToTimes($a->student->user, TalentStageChanged::class, 1);
    Notification::assertSentToTimes($b->student->user, TalentStageChanged::class, 1);
});

it('counts the final rows it skips and promises a notice only to students who get one (D-128, D-109)', function (): void {
    $open = F::application(F::student('Hana Safira', 'X-8'));
    $alumnus = F::application(F::student('Rizky Maulana', 'XII-1'), TalentStatus::Pemetaan, 'Kimia');
    $final = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Pembinaan, 'Fisika');
    $alumnus->student->user->forceFill(['status' => UserStatus::Archived])->save();

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('checked', [$open->getKey(), $alumnus->getKey(), $final->getKey()])
        ->call('askBulkAdvance')
        ->assertViewHas('advancing', ['count' => 2, 'skipped' => 1, 'stale' => 0, 'drafts' => 0, 'notice' => 'Siswa yang akunnya aktif langsung diberi tahu.'])
        ->assertSee('Pindahkan 2 pilihan ke tahap berikutnya?')
        ->assertSee('pilihan yang dicentang sudah final (Peserta pembinaan atau Tidak lolos) dan dilewati.')
        ->assertDontSee('catatan untuk siswa yang tersimpan ikut terkirim.')
        ->call('bulkAdvance')
        ->assertDispatched('sth-toast', message: '2 pilihan talenta dipindahkan ke tahap berikutnya. 1 dilewati (tahap final).', tone: 'success');

    expect($open->fresh()->status)->toBe(TalentStatus::Pemetaan)
        ->and($alumnus->fresh()->status)->toBe(TalentStatus::Seleksi)
        ->and($final->fresh()->status)->toBe(TalentStatus::Pembinaan);
    Notification::assertNotSentTo($alumnus->student->user, TalentStageChanged::class);
});

it('says in the dialog that nobody is told when no checked student can log in (D-109)', function (): void {
    $row = F::application(F::student('Rizky Maulana', 'XII-1'));
    $row->student->user->forceFill(['status' => UserStatus::Disabled])->save();

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('checked', [$row->getKey()])
        ->call('askBulkAdvance')
        ->assertSee('Pindahkan 1 pilihan ke tahap berikutnya?')
        ->assertSee('Akun siswa tidak aktif, jadi tidak ada notifikasi.');
});

it('opens no dialog for a selection of final rows only (D-128)', function (): void {
    $final = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Pembinaan, 'Kimia');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('checked', [$final->getKey()])
        ->call('askBulkAdvance')
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('advanceAsk', null)
        ->assertDispatched('sth-toast', message: 'Pilihan yang dicentang sudah final, tidak bisa dipindahkan.', tone: 'error')
        ->call('bulkAdvance')
        ->assertOk();

    expect($final->fresh()->status)->toBe(TalentStatus::Pembinaan);
    Notification::assertNothingSent();
});

// Forbidden cases ----------------------------------------------------------------------------------------------------

it('moves nothing when the confirmation arrives without the question, and shows no error page (D-128)', function (): void {
    $a = F::application(F::student('Hana Safira', 'X-8'));

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('checked', [$a->getKey()])
        ->call('bulkAdvance')
        ->assertOk()
        ->assertNotDispatched('sth-toast')
        // The selection stays for the real question.
        ->assertSet('checked', [$a->getKey()]);

    expect($a->fresh()->status)->toBe(TalentStatus::Terdaftar);
    Notification::assertNothingSent();
});

it('moves each row once when "Pindahkan" is pressed twice in the dialog (D-128)', function (): void {
    $a = F::application(F::student('Hana Safira', 'X-8'));

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('checked', [$a->getKey()])
        ->call('askBulkAdvance')
        ->call('bulkAdvance')
        ->call('bulkAdvance')
        ->assertOk();

    expect($a->fresh()->status)->toBe(TalentStatus::Pemetaan);
    Notification::assertSentToTimes($a->student->user, TalentStageChanged::class, 1);
});

it('keeps the dialog state locked: the browser cannot claim a question (D-128)', function (): void {
    $a = F::application(F::student('Hana Safira', 'X-8'));

    expect(fn () => Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('advanceAsk', ['stages' => [$a->getKey() => 'terdaftar'], 'final' => 0, 'moved' => 0, 'rejected' => 0]))
        ->toThrow(Exception::class)
        ->and($a->fresh()->status)->toBe(TalentStatus::Terdaftar);
});

it('voids the question when the checked rows or a filter change under it (D-128)', function (string $change): void {
    $a = F::application(F::student('Hana Safira', 'X-8'));
    $b = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Terdaftar, 'Kimia');

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('checked', [$a->getKey()])
        ->call('askBulkAdvance')
        ->assertSet('advanceAsk', ['stages' => [$a->getKey() => 'terdaftar'], 'final' => 0, 'moved' => 0, 'rejected' => 0]);

    match ($change) {
        'checked' => $component->set('checked', [$a->getKey(), $b->getKey()]),
        'filter' => $component->set('q', 'Hana'),
        'tab' => $component->call('setTahap', 'terdaftar'),
        'toggleAll' => $component->call('toggleAll'),
        'cancelBulk' => $component->call('cancelBulk'),
    };

    $component->assertSet('advanceAsk', null)
        ->assertViewHas('advancing', null)
        ->call('bulkAdvance')
        ->assertOk()
        ->assertNotDispatched('sth-toast');

    expect($a->fresh()->status)->toBe(TalentStatus::Terdaftar)
        ->and($b->fresh()->status)->toBe(TalentStatus::Terdaftar);
    Notification::assertNothingSent();
})->with(['checked', 'filter', 'tab', 'toggleAll', 'cancelBulk']);

it('keeps every other role away from the question (D-128)', function (): void {
    $a = F::application(F::student('Hana Safira', 'X-8'));

    foreach ([F::staff(RoleName::Guru, 'Pak Hendra Kusuma'), F::staff(RoleName::Humas, 'Mas Galih'), F::staff(RoleName::TataUsaha, 'Bu Sari'), $a->student->user] as $user) {
        Livewire::actingAs($user)->test(Talenta::class)->assertForbidden();
    }

    expect($a->fresh()->status)->toBe(TalentStatus::Terdaftar);
});

// A second tab (QA9) -------------------------------------------------------------------------------------------------

it('leaves out a row another tab moved before the question, says so in the dialog and never moves it twice (D-128, QA9)', function (): void {
    $moved = F::application(F::student('Hana Safira', 'X-8'));
    $rejected = F::application(F::student('Bima Satria Wicaksono', 'XII-7'), TalentStatus::Terdaftar, 'Fisika');
    $waiting = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Terdaftar, 'Kimia');

    $second = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('checked', [$moved->getKey(), $rejected->getKey(), $waiting->getKey()])
        ->assertSee('Pindahkan ke Pemetaan');

    app(MoveTalentStage::class)->handle($this->kesiswaan, $moved, TalentStatus::Pemetaan);
    app(RejectTalentApplication::class)->handle($this->kesiswaan, $rejected, 'Kuota penuh.');

    // The dialog's own render draws the new stages; the question was fixed before it.
    $second->call('askBulkAdvance')
        ->assertDispatched('sth-modal-open', id: 'pindahkan-talenta')
        ->assertSet('advanceAsk', ['stages' => [$waiting->getKey() => 'terdaftar'], 'final' => 0, 'moved' => 1, 'rejected' => 1])
        ->assertViewHas('advancing', fn (array $advancing): bool => $advancing['count'] === 1 && $advancing['stale'] === 2)
        ->assertSee('Pindahkan 1 pilihan ke tahap berikutnya?')
        ->assertSee('pilihan yang dicentang sudah dipindahkan atau ditandai tidak lolos lebih dulu dan dilewati.')
        ->call('bulkAdvance')
        ->assertDispatched('sth-toast', message: '1 pilihan talenta dipindahkan ke tahap berikutnya. 1 sudah dipindahkan lebih dulu dan tidak diubah lagi. 1 sudah ditandai tidak lolos lebih dulu. Daftar sudah diperbarui.', tone: 'warning')
        ->assertSet('checked', []);

    expect($moved->fresh()->status)->toBe(TalentStatus::Pemetaan)
        ->and($rejected->fresh()->status)->toBe(TalentStatus::TidakLolos)
        ->and($waiting->fresh()->status)->toBe(TalentStatus::Pemetaan);
    Notification::assertSentToTimes($moved->student->user, TalentStageChanged::class, 1);
});

it('opens no dialog when every checked row was moved by another tab, and refreshes the list (D-128, QA9)', function (): void {
    $moved = F::application(F::student('Hana Safira', 'X-8'));

    $second = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)->set('checked', [$moved->getKey()]);

    app(MoveTalentStage::class)->handle($this->kesiswaan, $moved, TalentStatus::Pemetaan);

    $second->call('askBulkAdvance')
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('advanceAsk', null)
        ->assertDispatched('sth-toast', message: '0 pilihan talenta dipindahkan ke tahap berikutnya. 1 sudah dipindahkan lebih dulu dan tidak diubah lagi. Daftar sudah diperbarui.', tone: 'warning')
        ->assertSet('checked', []);

    expect($moved->fresh()->status)->toBe(TalentStatus::Pemetaan);
    Notification::assertSentToTimes($moved->student->user, TalentStageChanged::class, 1);
});

it('skips a row a colleague moved while the dialog was open (D-128, QA9)', function (): void {
    $moved = F::application(F::student('Hana Safira', 'X-8'));
    $waiting = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Terdaftar, 'Kimia');

    $second = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('checked', [$moved->getKey(), $waiting->getKey()])
        ->call('askBulkAdvance')
        ->assertViewHas('advancing', fn (array $advancing): bool => $advancing['count'] === 2);

    app(MoveTalentStage::class)->handle($this->kesiswaan, $moved, TalentStatus::Pemetaan);

    $second->call('bulkAdvance')
        ->assertDispatched('sth-toast', message: '1 pilihan talenta dipindahkan ke tahap berikutnya. 1 sudah dipindahkan lebih dulu dan tidak diubah lagi. Daftar sudah diperbarui.', tone: 'warning');

    // Asked from Terdaftar: the row the colleague moved to Pemetaan does not go on to Seleksi.
    expect($moved->fresh()->status)->toBe(TalentStatus::Pemetaan)
        ->and($waiting->fresh()->status)->toBe(TalentStatus::Pemetaan);
    Notification::assertSentToTimes($moved->student->user, TalentStageChanged::class, 1);
});
