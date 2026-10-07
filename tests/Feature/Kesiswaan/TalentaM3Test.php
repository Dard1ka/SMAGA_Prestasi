<?php

declare(strict_types=1);

use App\Actions\Talent\RestoreTalentApplication;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\UserStatus;
use App\Livewire\Kesiswaan\Talenta;
use App\Models\TalentApplication;
use App\Models\TalentProfile;
use App\Notifications\Talent\TalentApplicationRejected;
use App\Notifications\Talent\TalentApplicationRestored;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| M3-A (lead, 2026-10-06), Kesiswaan › Talenta siswa:
| 1. "Pulihkan" asks where a tidak-lolos choice goes back to (Terdaftar, Pemetaan, Seleksi, Peserta pembinaan);
|    the stage before the rejection is preselected. The Action side: TalentRestoreStageTest.
| 2. The tab "Semua" lists every status, "Tidak lolos" included, each row with its chip and its own actions.
| 3. "Download data (Excel)" opens a dialog: Kesiswaan ticks the statuses that go into the file; the password
|    confirmation and the automatic download after it (QA4) keep that choice.
| Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
});

const M3A_STATUSES = ['terdaftar', 'pemetaan', 'seleksi', 'pembinaan', 'tidak_lolos'];

/** A tidak-lolos choice (OSN + $branch) of a named student; $from null = the earlier stage was never recorded. */
function m3aTidakLolos(string $name, string $class, ?TalentStatus $from, string $branch): TalentApplication
{
    $application = F::application(F::student($name, $class), TalentStatus::TidakLolos, $branch);
    $application->forceFill(['rejected_from' => $from])->save();

    return $application->fresh();
}

/**
 * One choice in every status, each of another student.
 *
 * @return array<string, TalentApplication> status value => row
 */
function m3aPipeline(): array
{
    return [
        'terdaftar' => F::application(F::student('Hana Safira', 'X-8'), TalentStatus::Terdaftar, 'Biologi'),
        'pemetaan' => F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Pemetaan, 'Kimia'),
        'seleksi' => F::application(F::student('Bima Satria Wicaksono', 'XII-7'), TalentStatus::Seleksi, 'Fisika'),
        'pembinaan' => F::application(F::student('Nadia Putri Anindya', 'XI-1'), TalentStatus::Pembinaan, 'Astronomi'),
        'tidak_lolos' => m3aTidakLolos('Citra Ayu Lestari', 'XI-2', TalentStatus::Seleksi, 'Informatika'),
    ];
}

/**
 * The rows of a downloaded talent .xlsx: [0] title, [1] "Diekspor oleh …", [2] "Filter: …", [3] header,
 * [4…] data (the reader skips the empty row of the sheet).
 *
 * @return list<array<int, mixed>>
 */
function m3aExportRows(Testable $component): array
{
    $path = sys_get_temp_dir().'/talenta-m3a-'.uniqid().'.xlsx';
    file_put_contents($path, base64_decode((string) $component->effects['download']['content']));
    $rows = SimpleExcelReader::create($path)->noHeaderRow()->getRows()->all();
    @unlink($path);

    return array_values($rows);
}

/** @return list<string> "Nama | Tahap" of every data row of the file */
function m3aExportedStudents(array $rows): array
{
    return collect($rows)->slice(4)->map(fn (array $row): string => $row[0].' | '.$row[5])->sort()->values()->all();
}

// --- "Semua" shows everything with its status -------------------------------------------------------------

it('lists tidak lolos rows in "Semua" with their status chip and counts them', function (): void {
    $open = F::application(F::student('Hana Safira', 'X-8'));
    $final = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Pembinaan, 'Kimia');
    $rejected = m3aTidakLolos('Citra Ayu Lestari', 'XI-2', TalentStatus::Seleksi, 'Fisika');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertSet('tahap', 'semua')
        ->assertSee(['Hana Safira', 'Aurelia Maharani', 'Citra Ayu Lestari'])
        ->assertSee('Menampilkan 3 dari 3 pilihan talenta')
        ->assertViewHas('counts', fn (array $counts): bool => $counts === ['terdaftar' => 1, 'pemetaan' => 0, 'seleksi' => 0, 'pembinaan' => 1, 'tidak_lolos' => 1, 'semua' => 3])
        ->assertSeeHtmlInOrder(['Semua <span class="cnt">3</span>', 'Tidak lolos <span class="cnt">1</span>'])
        ->assertViewHas('rows', function (Collection $rows) use ($open, $final, $rejected): bool {
            $byId = $rows->keyBy('id');

            return $rows->count() === 3
                && $byId[$open->getKey()]['status'] === TalentStatus::Terdaftar
                && $byId[$final->getKey()]['status'] === TalentStatus::Pembinaan
                && $byId[$rejected->getKey()]['status'] === TalentStatus::TidakLolos
                && $byId[$rejected->getKey()]['restorable'] === true
                && $byId[$rejected->getKey()]['next'] === null;
        })
        // In the table row: the chip "Tidak lolos" sits between the row's tick box and its "Pulihkan" icon.
        ->assertSeeHtmlInOrder(['aria-label="Pilih Citra Ayu Lestari', 'Tidak lolos', 'aria-label="Pulihkan Citra Ayu Lestari']);
});

it('offers restore, not the forward actions, on a tidak lolos row inside "Semua"', function (): void {
    $open = F::application(F::student('Hana Safira', 'X-8'));
    $rejected = m3aTidakLolos('Citra Ayu Lestari', 'XI-2', TalentStatus::Seleksi, 'Fisika');

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertSeeHtml('wire:click="askRestore(\''.$rejected->getKey().'\')"')
        ->assertDontSeeHtml('wire:click="advanceRow(\''.$rejected->getKey().'\')"')
        ->assertSeeHtml('wire:click="advanceRow(\''.$open->getKey().'\')"')
        ->assertDontSeeHtml('wire:click="askRestore(\''.$open->getKey().'\')"')
        // The panel of the rejected row: "Pulihkan" only, with the earlier stage named.
        ->call('showPanel', $rejected->getKey())
        ->assertViewHas('panel', fn (array $panel): bool => $panel['restorable'] === true && $panel['next'] === null && $panel['final'] === true)
        ->assertSee('Tahap sebelumnya: Seleksi/asesmen. Pulihkan untuk mengembalikannya ke tahap yang kamu pilih.')
        ->assertDontSeeHtml('wire:click="advance"')
        ->assertDontSeeHtml('wire:click="askReject"')
        // A forged forward click on the rejected row does nothing.
        ->call('advanceRow', $rejected->getKey())
        ->call('advance');

    expect($rejected->fresh()->status)->toBe(TalentStatus::TidakLolos);
    Notification::assertNothingSent();

    // Restored from "Semua", the row stays in the list with its new status, and the counts follow.
    $component->call('askRestore', $rejected->getKey())
        ->set('restoreTo', 'pemetaan')
        ->call('restore')
        ->assertHasNoErrors()
        ->assertViewHas('rows', fn (Collection $rows): bool => $rows->count() === 2 && $rows->keyBy('id')[$rejected->getKey()]['status'] === TalentStatus::Pemetaan)
        ->assertViewHas('counts', fn (array $counts): bool => $counts['semua'] === 2 && $counts['pemetaan'] === 1 && $counts['tidak_lolos'] === 0)
        ->assertSeeHtml('wire:click="advanceRow(\''.$rejected->getKey().'\')"')
        // The panel followed the row into its stage.
        ->assertSee('Lolos ke Seleksi/asesmen');
});

it('skips rows that are already final when a mixed selection of "Semua" is moved or rejected', function (): void {
    $open = F::application(F::student('Hana Safira', 'X-8'));
    $next = F::application(F::student('Bima Satria Wicaksono', 'XII-7'), TalentStatus::Pemetaan, 'Fisika');
    $final = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Pembinaan, 'Kimia');
    $rejected = m3aTidakLolos('Citra Ayu Lestari', 'XI-2', TalentStatus::Seleksi, 'Informatika');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('toggleAll')
        ->assertCount('checked', 4)
        ->assertSee('Pindahkan ke tahap berikutnya')
        ->call('askBulkReject')
        ->assertDispatched('sth-modal-open', id: 'tidak-lolos-talenta')
        // The dialog counts only what it will reject and names the rest.
        ->assertViewHas('rejecting', fn (array $rejecting): bool => $rejecting['bulk'] === true && $rejecting['count'] === 2 && $rejecting['skipped'] === 2)
        ->assertSee('pilihan yang dicentang sudah final (Peserta pembinaan atau Tidak lolos) dan dilewati.')
        ->set('checked', [$open->getKey(), $final->getKey(), $rejected->getKey()])
        ->call('bulkAdvance')
        ->assertDispatched('sth-toast', message: '1 pilihan talenta dipindahkan ke tahap berikutnya. 2 dilewati (tahap final).', tone: 'success')
        ->assertSet('checked', [])
        ->set('checked', [$next->getKey(), $final->getKey(), $rejected->getKey()])
        ->call('askBulkReject')
        ->call('reject')
        ->assertDispatched('sth-toast', message: '1 pilihan talenta ditandai tidak lolos. Siswa diberi tahu. 2 dilewati (tahap final).', tone: 'success');

    expect($open->fresh()->status)->toBe(TalentStatus::Pemetaan)
        ->and($next->fresh()->status)->toBe(TalentStatus::TidakLolos)
        ->and($next->fresh()->rejected_from)->toBe(TalentStatus::Pemetaan)
        ->and($final->fresh()->status)->toBe(TalentStatus::Pembinaan)
        // The earlier rejection is untouched: its stage is not overwritten and its student is not told twice.
        ->and($rejected->fresh()->status)->toBe(TalentStatus::TidakLolos)
        ->and($rejected->fresh()->rejected_from)->toBe(TalentStatus::Seleksi);
    Notification::assertNotSentTo($rejected->student->user, TalentApplicationRejected::class);
    Notification::assertNotSentTo($final->student->user, TalentApplicationRejected::class);
});

it('does not open the "Tidak lolos" dialog for a selection of final rows only', function (): void {
    $final = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Pembinaan, 'Kimia');
    $rejected = m3aTidakLolos('Citra Ayu Lestari', 'XI-2', TalentStatus::Seleksi, 'Informatika');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('checked', [$final->getKey(), $rejected->getKey()])
        ->call('askBulkReject')
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('rejectTarget', '')
        ->assertDispatched('sth-toast', message: 'Pilihan yang dicentang sudah final, tidak bisa ditandai tidak lolos.', tone: 'error')
        ->call('reject')
        ->assertNotFound();

    expect($final->fresh()->status)->toBe(TalentStatus::Pembinaan)
        ->and($rejected->fresh()->status)->toBe(TalentStatus::TidakLolos);
    Notification::assertNothingSent();
});

// --- "Pulihkan" to a chosen stage ---------------------------------------------------------------------------

it('asks where the choice goes back to, with the earlier stage preselected and marked', function (): void {
    $rejected = m3aTidakLolos('Citra Ayu Lestari', 'XI-2', TalentStatus::Pemetaan, 'Fisika');

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('askRestore', $rejected->getKey())
        ->assertDispatched('sth-modal-open', id: 'pulihkan-talenta')
        ->assertSet('restoreId', $rejected->getKey())
        ->assertSet('restoreTo', 'pemetaan')
        ->assertSee('Pilih tahap tujuannya. Siswa diberi tahu.')
        ->assertSee('tidak lolos dari tahap Pemetaan.')
        ->assertSee('Kembalikan ke tahap')
        ->assertViewHas('restoring', fn (array $restoring): bool => $restoring['from'] === 'Pemetaan' && $restoring['options'] === [
            'terdaftar' => ['label' => 'Terdaftar', 'sub' => 'Tahap 1'],
            'pemetaan' => ['label' => 'Pemetaan', 'sub' => 'Tahap 2 · tahap sebelumnya'],
            'seleksi' => ['label' => 'Seleksi/asesmen', 'sub' => 'Tahap 3'],
            'pembinaan' => ['label' => 'Peserta pembinaan', 'sub' => 'Tahap 4'],
        ])
        ->assertSeeHtml(['id="f-restoreTo-terdaftar"', 'id="f-restoreTo-pemetaan"', 'id="f-restoreTo-seleksi"', 'id="f-restoreTo-pembinaan"'])
        ->assertDontSeeHtml('id="f-restoreTo-tidak-lolos"')
        ->assertSee('Tahap 2 · tahap sebelumnya')
        ->assertSee('Guru pembina')
        ->assertSee('Wajib kalau dipulihkan ke Peserta pembinaan.');

    // Opening the dialog changed nothing and told nobody.
    expect($rejected->fresh()->status)->toBe(TalentStatus::TidakLolos);
    Notification::assertNothingSent();

    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');

    $component->set('restoreTo', 'pembinaan')
        ->set('restoreNote', 'Selamat bergabung di pembinaan.')
        ->call('restore')
        ->assertHasErrors(['restoreMentor'])
        ->assertSee('Peserta pembinaan perlu guru pembina. Pilih guru pembinanya.')
        ->assertNotDispatched('sth-modal-close');

    expect($rejected->fresh()->status)->toBe(TalentStatus::TidakLolos);

    $component->set('restoreMentor', (string) $guru->getKey())
        ->call('restore')
        ->assertHasNoErrors()
        ->assertSet('restoreId', null)
        ->assertSet('restoreTo', '')
        ->assertDispatched('sth-modal-close', id: 'pulihkan-talenta')
        ->assertDispatched('sth-toast', message: 'Dipulihkan ke tahap Peserta pembinaan. Siswa diberi tahu.', tone: 'success');

    $fresh = $rejected->fresh();
    expect($fresh->status)->toBe(TalentStatus::Pembinaan)
        ->and($fresh->rejected_from)->toBeNull()
        ->and($fresh->student_note)->toBe('Selamat bergabung di pembinaan.')
        ->and($fresh->mentor_id)->toBe($guru->getKey());

    $log = Activity::query()->where('event', 'talent.restored')->sole();
    expect($log->properties['from'])->toBe('tidak_lolos')
        ->and($log->properties['to'])->toBe('pembinaan')
        ->and($log->properties['rejected_from'])->toBe('pemetaan')
        ->and((int) $log->causer_id)->toBe((int) $this->kesiswaan->getKey());

    Notification::assertSentTo($rejected->student->user, TalentApplicationRestored::class, fn (TalentApplicationRestored $n): bool => str_contains($n->body, 'dibuka kembali. Kamu sekarang peserta pembinaan.')
        && str_contains($n->body, 'Catatan dari Kesiswaan: Selamat bergabung di pembinaan.'));
});

it('restores to every stage of the pipeline from the dialog', function (string $to, TalentStatus $status): void {
    $rejected = m3aTidakLolos('Citra Ayu Lestari', 'XI-2', TalentStatus::Seleksi, 'Fisika');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('setTahap', 'tidak_lolos')
        ->call('askRestore', $rejected->getKey())
        ->set('restoreTo', $to)
        ->set('restoreMentor', (string) F::staff(RoleName::Guru, 'Pak Hendra Kusuma')->getKey())
        ->call('restore')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Dipulihkan ke tahap '.$status->label().'. Siswa diberi tahu.', tone: 'success')
        // The tab Tidak lolos is empty again.
        ->assertViewHas('rows', fn (Collection $rows): bool => $rows->isEmpty());

    expect($rejected->fresh()->status)->toBe($status);
    Notification::assertSentToTimes($rejected->student->user, TalentApplicationRestored::class, 1);
})->with([
    'terdaftar' => ['terdaftar', TalentStatus::Terdaftar],
    'pemetaan' => ['pemetaan', TalentStatus::Pemetaan],
    'seleksi' => ['seleksi', TalentStatus::Seleksi],
    'peserta pembinaan' => ['pembinaan', TalentStatus::Pembinaan],
]);

it('refuses a stage that is not one of the four and keeps the dialog open', function (string $forged): void {
    $rejected = m3aTidakLolos('Citra Ayu Lestari', 'XI-2', TalentStatus::Seleksi, 'Fisika');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('askRestore', $rejected->getKey())
        ->set('restoreTo', $forged)
        ->call('restore')
        ->assertHasErrors('restoreTo')
        ->assertSee(RestoreTalentApplication::TARGET_MESSAGE)
        ->assertSet('restoreId', $rejected->getKey())
        ->assertNotDispatched('sth-modal-close');

    expect($rejected->fresh()->status)->toBe(TalentStatus::TidakLolos)
        ->and($rejected->fresh()->rejected_from)->toBe(TalentStatus::Seleksi)
        ->and(Activity::query()->where('event', 'talent.restored')->exists())->toBeFalse();
    Notification::assertNothingSent();
})->with(['tidak lolos itself' => ['tidak_lolos'], 'nothing ticked' => [''], 'an unknown stage' => ['lulus'], 'the tab key' => ['semua']]);

it('preselects Terdaftar and says so when the earlier stage was never recorded', function (): void {
    $rejected = m3aTidakLolos('Fajar Ramadhan', 'XII-1', null, 'Kimia');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $rejected->getKey())
        ->assertSee('Siswa sudah diberi tahu. Pulihkan untuk mengembalikannya ke tahap yang kamu pilih.')
        ->assertDontSee('Tahap sebelumnya:')
        ->call('askRestore', $rejected->getKey())
        ->assertSet('restoreTo', 'terdaftar')
        ->assertSee('Tahap sebelumnya tidak tercatat, jadi Terdaftar yang dipilih.')
        ->assertViewHas('restoring', fn (array $restoring): bool => $restoring['from'] === null
            && collect($restoring['options'])->pluck('sub')->all() === ['Tahap 1', 'Tahap 2', 'Tahap 3', 'Tahap 4'])
        ->call('restore')
        ->assertDispatched('sth-toast', message: 'Dipulihkan ke tahap Terdaftar. Siswa diberi tahu.', tone: 'success');

    expect($rejected->fresh()->status)->toBe(TalentStatus::Terdaftar);
});

it('closes the student request when the choice is restored to another stage than the earlier one', function (): void {
    $asked = TalentApplication::factory()->rejected(TalentStatus::Seleksi)->reviewRequested('Saya sakit saat asesmen.')->create([
        'student_id' => F::student('Citra Ayu Lestari', 'XI-2')->getKey(),
        'talent_type_id' => F::talentType()->getKey(),
    ]);

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $asked->getKey())
        ->assertSee('Siswa minta ditinjau ulang')
        ->call('askRestore', $asked->getKey())
        ->assertSee('Permintaan tinjau ulang siswa ikut ditutup.')
        ->set('restoreTo', 'terdaftar')
        ->call('restore')
        ->assertHasNoErrors()
        ->assertDontSee('Siswa minta ditinjau ulang');

    expect($asked->fresh()->status)->toBe(TalentStatus::Terdaftar)
        ->and($asked->fresh()->review_requested_at)->toBeNull();
    Notification::assertSentTo($asked->student->user, TalentApplicationRestored::class, fn (TalentApplicationRestored $n): bool => str_contains($n->body, 'Permintaan tinjau ulangmu diterima.'));
});

it('has nothing to restore without the dialog, and no stage choice revives a row that is not tidak lolos', function (): void {
    $open = F::application(F::student('Hana Safira', 'X-8'));
    $rejected = m3aTidakLolos('Citra Ayu Lestari', 'XI-2', TalentStatus::Seleksi, 'Fisika');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)->set('restoreTo', 'pembinaan')->call('restore')->assertNotFound();
    expect(fn () => Livewire::actingAs($this->kesiswaan)->test(Talenta::class)->set('restoreId', $open->getKey()))->toThrow(Exception::class);

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('askRestore', $open->getKey())
        ->assertSet('restoreId', null)
        ->assertSet('restoreTo', '')
        ->assertNotDispatched('sth-modal-open');

    // The dialog is open, someone else restores the row meanwhile: the confirmation is refused, the row keeps
    // the stage it got, and the student is not told a second time.
    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)->call('askRestore', $rejected->getKey());
    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $rejected->fresh());

    $component->set('restoreTo', 'pembinaan')
        ->call('restore')
        ->assertDispatched('sth-toast', message: 'Hanya pilihan yang tidak lolos yang bisa dipulihkan.', tone: 'error')
        ->assertSet('restoreId', null);

    expect($open->fresh()->status)->toBe(TalentStatus::Terdaftar)
        ->and($rejected->fresh()->status)->toBe(TalentStatus::Seleksi);
    Notification::assertSentToTimes($rejected->student->user, TalentApplicationRestored::class, 1);
});

// --- "Download data (Excel)" with a filter ------------------------------------------------------------------

it('opens a dialog for "Download data (Excel)" with the statuses of the open tab ticked', function (): void {
    m3aPipeline();
    session()->put('auth.password_confirmed_at', time());

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertSeeHtml('wire:click="askExport"')
        ->assertDontSeeHtml('wire:click="export"')
        ->assertSee('Download data (Excel)')
        ->call('askExport')
        ->assertDispatched('sth-modal-open', id: 'download-talenta')
        ->assertNoFileDownloaded()
        // Tab "Semua": every status is ticked.
        ->assertSet('exportStatuses', M3A_STATUSES)
        ->assertSee('Pilih status yang masuk ke file Excel.')
        ->assertSee('File mengikuti filter di layar. Saat ini tidak ada filter jenis, cabang, kelas, atau pencarian.')
        ->assertSee('Pilih semua status')
        ->assertViewHas('exportOptions', fn (array $options): bool => array_keys($options) === M3A_STATUSES
            && $options['seleksi'] === ['label' => 'Seleksi/asesmen', 'sub' => '1 pilihan', 'disabled' => false]
            && $options['tidak_lolos'] === ['label' => 'Tidak lolos', 'sub' => '1 pilihan', 'disabled' => false])
        ->assertSeeHtml(['id="f-exportStatuses-terdaftar"', 'id="f-exportStatuses-tidak-lolos"'])
        // Another tab: only its status is ticked; "Pilih semua status" ticks the rest.
        ->call('setTahap', 'seleksi')
        ->call('askExport')
        ->assertSet('exportStatuses', ['seleksi'])
        ->call('selectAllExport')
        ->assertSet('exportStatuses', M3A_STATUSES)
        ->call('setTahap', 'tidak_lolos')
        ->call('askExport')
        ->assertSet('exportStatuses', ['tidak_lolos'])
        ->assertNoFileDownloaded();

    // Opening the dialog downloads nothing.
    expect(Activity::query()->where('event', 'talent.exported')->exists())->toBeFalse();
});

it('puts only the ticked statuses into the file and says so in its "Filter:" line', function (): void {
    m3aPipeline();
    session()->put('auth.password_confirmed_at', time());

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('setTahap', 'seleksi')
        ->call('askExport')
        // Ticked in any order; the tab does not decide what is downloaded.
        ->set('exportStatuses', ['tidak_lolos', 'terdaftar'])
        ->call('download')
        ->assertHasNoErrors()
        ->assertFileDownloaded()
        ->assertDispatched('sth-modal-close', id: 'download-talenta');

    $rows = m3aExportRows($component);

    // Everything QA4 put on the sheet is still there.
    expect($rows[0][0])->toBe('Talenta siswa SMAGA Talent Hub')
        ->and($rows[1][0])->toStartWith('Diekspor oleh '.$this->kesiswaan->name.' · ')
        ->and($rows[1][0])->toEndWith(' WIB')
        ->and($rows[2][0])->toBe('Filter: Tahap: Terdaftar, Tidak lolos')
        ->and($rows[3][0])->toBe('Nama')
        ->and($rows[3][5])->toBe('Tahap')
        ->and(m3aExportedStudents($rows))->toBe(['Citra Ayu Lestari | Tidak lolos', 'Hana Safira | Terdaftar']);

    $log = Activity::query()->where('event', 'talent.exported')->sole();
    expect($log->properties['filters'])->toBe(['tahap' => 'terdaftar,tidak_lolos'])
        ->and($log->properties['rows'])->toBe(2)
        ->and($log->properties['format'])->toBe('xlsx')
        ->and((int) $log->causer_id)->toBe((int) $this->kesiswaan->getKey());
});

it('downloads every status when all are ticked, whatever tab is open, and keeps the other filters', function (): void {
    $rows = m3aPipeline();
    F::application(F::student('Galang Putra Mahendra', 'X-TAHFIDZ'), TalentStatus::Seleksi, 'Biologi');
    session()->put('auth.password_confirmed_at', time());

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('setTahap', 'pemetaan')
        ->call('askExport')
        ->call('selectAllExport')
        ->call('download')
        ->assertFileDownloaded();

    $sheet = m3aExportRows($component);

    expect($sheet[2][0])->toBe('Filter: semua talenta')
        ->and(m3aExportedStudents($sheet))->toHaveCount(6)
        ->and(collect($sheet)->slice(4)->pluck(5)->unique()->sort()->values()->all())->toBe(['Pemetaan', 'Peserta pembinaan', 'Seleksi/asesmen', 'Terdaftar', 'Tidak lolos'])
        ->and(Activity::query()->where('event', 'talent.exported')->sole()->properties['filters'])->toBe([]);

    // With a class filter the dialog names it, and the "Filter:" line still says which statuses were chosen.
    $kelas = $rows['seleksi']->student->classroom_id;
    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('kelas', $kelas)
        ->call('askExport')
        ->assertSee('Filter yang sedang dipakai ikut berlaku: Kelas: XII-7.')
        ->call('download')
        ->assertFileDownloaded();

    $sheet = m3aExportRows($component);

    expect($sheet[2][0])->toBe('Filter: Tahap: semua; Kelas: XII-7')
        ->and(m3aExportedStudents($sheet))->toBe(['Bima Satria Wicaksono | Seleksi/asesmen'])
        ->and(Activity::query()->where('event', 'talent.exported')->latest('id')->first()->properties['filters'])->toBe(['kelas' => $kelas]);
});

it('asks for at least one real status before it downloads', function (array $ticked): void {
    m3aPipeline();
    session()->put('auth.password_confirmed_at', time());

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('askExport')
        ->set('exportStatuses', $ticked)
        ->call('download')
        ->assertHasErrors('exportStatuses')
        ->assertSee('Pilih minimal satu status.')
        ->assertNoFileDownloaded()
        ->assertNoRedirect()
        ->assertNotDispatched('sth-modal-close');

    expect(Activity::query()->where('event', 'talent.exported')->exists())->toBeFalse();
})->with(['nothing ticked' => [[]], 'only values that are no status' => [['semua', 'lulus', '']]]);

it('asks for the password first and then starts the same download by itself', function (array $ticked, string $argument, array $expected): void {
    m3aPipeline();

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('setTahap', 'seleksi')
        ->call('askExport')
        ->set('exportStatuses', $ticked)
        ->call('download')
        ->assertRedirect(route('password.confirm'))
        ->assertNoFileDownloaded();

    // Nothing was written and nothing logged; the password page sends the user back to the same tab.
    expect(Activity::query()->where('event', 'talent.exported')->exists())->toBeFalse()
        ->and(session('url.intended'))->toBe(route('kesiswaan.talenta.index', ['tahap' => 'seleksi']));

    session()->put('auth.password_confirmed_at', time()); // what Fortify writes after /user/confirm-password

    // Back on the screen: the download starts once, by itself, with the choice made in the dialog.
    $component = Livewire::actingAs($this->kesiswaan)->withQueryParams(['tahap' => 'seleksi'])->test(Talenta::class)
        ->assertSet('tahap', 'seleksi')
        ->assertSeeHtml('wire:init="export(&#039;'.$argument.'&#039;)"')
        ->call('export', $argument)
        ->assertFileDownloaded()
        ->assertDontSeeHtml('wire:init=');

    expect(m3aExportedStudents(m3aExportRows($component)))->toBe($expected)
        ->and(Activity::query()->where('event', 'talent.exported')->count())->toBe(1);

    // A reload or a later visit does not download again.
    Livewire::actingAs($this->kesiswaan)->withQueryParams(['tahap' => 'seleksi'])->test(Talenta::class)->assertDontSeeHtml('wire:init=');
})->with([
    'two statuses, not the open tab' => [
        ['tidak_lolos', 'terdaftar'],
        'terdaftar,tidak_lolos',
        ['Citra Ayu Lestari | Tidak lolos', 'Hana Safira | Terdaftar'],
    ],
    'every status' => [
        M3A_STATUSES,
        'semua',
        ['Aurelia Maharani | Pemetaan', 'Bima Satria Wicaksono | Seleksi/asesmen', 'Citra Ayu Lestari | Tidak lolos', 'Hana Safira | Terdaftar', 'Nadia Putri Anindya | Peserta pembinaan'],
    ],
]);

it('never lets a forged tick reach the call that is run after the password page', function (): void {
    m3aPipeline();

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('askExport')
        ->set('exportStatuses', ['seleksi', "x'); hapus(); ('", 'lulus', 'seleksi'])
        ->call('download')
        ->assertRedirect(route('password.confirm'));

    session()->put('auth.password_confirmed_at', time());

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertSeeHtml('wire:init="export(&#039;seleksi&#039;)"')
        ->assertDontSeeHtml('hapus');
});

it('normalises the statuses of a direct export call and keeps following the tab without an argument', function (): void {
    m3aPipeline();

    // Unordered and repeated: the call that is resumed is the tidy one.
    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('export', 'seleksi,terdaftar,seleksi')
        ->assertRedirect(route('password.confirm'));

    session()->put('auth.password_confirmed_at', time());

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertSeeHtml('wire:init="export(&#039;terdaftar,seleksi&#039;)"')
        ->call('export', 'seleksi,terdaftar,seleksi')
        ->assertFileDownloaded();

    $sheet = m3aExportRows($component);
    expect($sheet[2][0])->toBe('Filter: Tahap: Terdaftar, Seleksi/asesmen')
        ->and(m3aExportedStudents($sheet))->toBe(['Bima Satria Wicaksono | Seleksi/asesmen', 'Hana Safira | Terdaftar']);

    // No argument (an older resume call): what the open tab lists, here the tab Tidak lolos.
    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('setTahap', 'tidak_lolos')
        ->call('export')
        ->assertFileDownloaded();

    $sheet = m3aExportRows($component);
    expect($sheet[2][0])->toBe('Filter: Tahap: Tidak lolos')
        ->and(m3aExportedStudents($sheet))->toBe(['Citra Ayu Lestari | Tidak lolos']);
});

it('refuses an export call with anything but real statuses', function (string $argument): void {
    m3aPipeline();
    session()->put('auth.password_confirmed_at', time());

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('export', $argument)
        ->assertNotFound();

    expect(Activity::query()->where('event', 'talent.exported')->exists())->toBeFalse();
})->with([
    'an unknown status' => ['lulus'],
    'a real one next to an unknown one' => ['terdaftar,lulus'],
    'an empty part' => ['terdaftar,'],
    'only a comma' => [','],
    'upper case' => ['TERDAFTAR'],
    'a quote' => ["terdaftar') or ('1'='1"],
    'far too long' => [str_repeat('terdaftar,', 20).'seleksi'],
]);

// --- Access -------------------------------------------------------------------------------------------------

it('keeps every other role away from the screen with the new dialogs', function (): void {
    $rejected = m3aTidakLolos('Citra Ayu Lestari', 'XI-2', TalentStatus::Seleksi, 'Fisika');

    foreach ([F::staff(RoleName::Guru, 'Pak Hendra Kusuma'), F::staff(RoleName::Humas, 'Mas Galih'), F::staff(RoleName::TataUsaha, 'Staf TU'), F::staff(RoleName::KepalaSekolah, 'Kepala'), $rejected->student->user] as $user) {
        Livewire::actingAs($user)->test(Talenta::class)->assertForbidden();
        $this->actingAs($user)->get(route('kesiswaan.talenta.index'))->assertForbidden();
    }

    // Admin may: same permission as Kesiswaan.
    Livewire::actingAs(F::staff(RoleName::Admin, 'Operator STH'))->test(Talenta::class)
        ->call('askExport')
        ->assertDispatched('sth-modal-open', id: 'download-talenta');

    expect($rejected->fresh()->status)->toBe(TalentStatus::TidakLolos);
});

it('checks the permission again inside every new action', function (string $method, array $arguments): void {
    $rejected = m3aTidakLolos('Citra Ayu Lestari', 'XI-2', TalentStatus::Seleksi, 'Fisika');
    session()->put('auth.password_confirmed_at', time());

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('askRestore', $rejected->getKey())
        ->set('restoreTo', 'pembinaan')
        ->set('exportStatuses', ['tidak_lolos']);

    // The account loses the role while the screen is open.
    $this->kesiswaan->syncRoles([RoleName::Guru->value]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $arguments = array_map(fn (string $argument): string => $argument === ':id' ? $rejected->getKey() : $argument, $arguments);

    $component->call($method, ...$arguments)->assertForbidden();

    expect($rejected->fresh()->status)->toBe(TalentStatus::TidakLolos)
        ->and(Activity::query()->whereIn('event', ['talent.exported', 'talent.restored'])->exists())->toBeFalse();
    Notification::assertNothingSent();
})->with([
    'restore' => ['restore', []],
    'askRestore' => ['askRestore', [':id']],
    'askExport' => ['askExport', []],
    'selectAllExport' => ['selectAllExport', []],
    'download' => ['download', []],
    'export' => ['export', ['semua']],
]);

// QA5 (2026-10-07): a status without rows is marked and never makes an empty file --------------------------

it('marks a status without rows as empty, leaves it unticked and never downloads an empty file', function (): void {
    F::application(F::student('Bima Satria Wicaksono', 'XII-7'), TalentStatus::Seleksi, 'Fisika');
    session()->put('auth.password_confirmed_at', time());

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('askExport')
        // Tab "Semua": only the statuses that have rows are ticked.
        ->assertSet('exportStatuses', ['seleksi'])
        ->assertViewHas('exportOptions', fn (array $options): bool => $options['seleksi'] === ['label' => 'Seleksi/asesmen', 'sub' => '1 pilihan', 'disabled' => false]
            && $options['terdaftar'] === ['label' => 'Terdaftar', 'sub' => 'Kosong', 'disabled' => true]
            && $options['tidak_lolos'] === ['label' => 'Tidak lolos', 'sub' => 'Kosong', 'disabled' => true])
        ->call('selectAllExport')
        ->assertSet('exportStatuses', ['seleksi']);

    // A tick on an empty status (forged, the box is disabled) asks for another choice, no file.
    $component->set('exportStatuses', ['terdaftar'])
        ->call('download')
        ->assertHasErrors(['exportStatuses'])
        ->assertSee('Status yang dipilih belum punya data. Pilih status lain atau ubah filter.')
        ->assertNoFileDownloaded();

    // A direct call for an empty status (e.g. the open tab is empty) says so instead of an empty file.
    $component->call('export', 'terdaftar')
        ->assertNoFileDownloaded()
        ->assertDispatched('sth-toast', message: 'Tidak ada pilihan talenta dengan status dan filter ini. Tidak ada yang di-download.', tone: 'error');

    expect(Activity::query()->where('event', 'talent.exported')->exists())->toBeFalse();
});

it('opens the dialog of an empty tab with nothing ticked', function (): void {
    F::application(F::student('Bima Satria Wicaksono', 'XII-7'), TalentStatus::Seleksi, 'Fisika');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('setTahap', 'tidak_lolos')
        ->call('askExport')
        ->assertSet('exportStatuses', []);
});

it('does not preselect a guru pembina who is no longer in the list of the restore dialog', function (): void {
    $rejected = m3aTidakLolos('Citra Ayu Lestari', 'XI-2', TalentStatus::Seleksi, 'Fisika');
    $gone = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $active = F::staff(RoleName::Guru, 'Bu Retno Wulandari');
    $rejected->forceFill(['mentor_id' => $gone->getKey()])->save();
    $gone->forceFill(['status' => UserStatus::Archived])->save();

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('setTahap', 'tidak_lolos')
        ->call('askRestore', $rejected->getKey())
        ->assertSet('restoreMentor', '')
        ->set('restoreTo', 'terdaftar')
        ->call('restore')
        ->assertHasNoErrors();

    expect($rejected->fresh()->status)->toBe(TalentStatus::Terdaftar);

    $rejected->fresh()->forceFill(['status' => TalentStatus::TidakLolos, 'mentor_id' => $active->getKey()])->save();

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('askRestore', $rejected->getKey())
        ->assertSet('restoreMentor', (string) $active->getKey());
});

// QA6: explore round 3 after PR 14 (2026-10-07) ----------------------------------------------------

it('wraps one long word of the student in the detail panel instead of widening the page (QA6, #65 Q2-29)', function (): void {
    $application = F::application();
    $word = str_repeat('instagramcomsiswasmagaresmi', 3);
    TalentProfile::query()->create([
        'student_id' => $application->student_id,
        'interests' => [mb_substr($word, 0, 40)],
        'organizations' => $word,
        'junior_high_achievements' => $word,
    ]);

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $application->getKey())
        ->assertSee($word)
        // A grid column of 1fr grows to its longest word; minmax(0,1fr) plus a break inside the word keeps it in the card.
        ->assertSeeHtml('grid-cols-[110px_minmax(0,1fr)]')
        ->assertSeeHtml('<dd class="m-0 [overflow-wrap:anywhere]">'.$word.'</dd>');
});
