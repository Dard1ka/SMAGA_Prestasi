<?php

declare(strict_types=1);

use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterDraft;
use App\Actions\Templates\LetterTemplate;
use App\Actions\Templates\UpdateLetterTemplate;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Livewire\TataUsaha\SuratTugas;
use App\Livewire\TataUsaha\TemplateSurat;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
 * SV9, D-200 (lead, 2026-11-09: "ini tidak konsisten masalahnya dgn surat guru"): the guru letter's Dasar is edited the
 * same way as the student letter's (D-195): a list of 1 to 6 rows with "Tambah dasar", "Naikkan" / "Turunkan" and
 * "Hapus". The guru texts stay optional (the invitation is always the last Dasar of a guru letter); the three built-in
 * regulation texts are the first three rows. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->withoutVite();
    $this->tu = F::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani']);
});

const D200_DASAR_4 = 'Program Kerja {sekolah} Tahun Pelajaran {tahun_ajaran}';

it('keeps the guru Dasar in six fields like the student one, the three built-in texts first', function (): void {
    $defaults = LetterTemplate::defaults();

    expect(LetterTemplate::GURU_DASAR)->toBe(['guru_basis_1', 'guru_basis_2', 'guru_basis_3', 'guru_basis_4', 'guru_basis_5', 'guru_basis_6'])
        ->and(LetterTemplate::DASAR)->toBe(['siswa' => LetterTemplate::SISWA_DASAR, 'guru' => LetterTemplate::GURU_DASAR])
        ->and(LetterTemplate::DASAR_MAX)->toBe(6)
        ->and(LetterTemplate::label('guru_basis_4'))->toBe('Surat guru: Dasar nomor 4')
        ->and($defaults['guru_basis_4'])->toBe('')
        ->and(LetterTemplate::current()->guruBasisItems('2026/2027'))->toHaveCount(3)
        ->and(LetterTemplate::dasarTexts('guru', $defaults))->toHaveCount(3);
});

it('reads a template saved before SV9 with a gap as its texts in order, and a letter printed with it the same', function (): void {
    // what TU saved before SV9: guru_basis_1 … 3 only, number 1 emptied
    $before = array_diff_key(LetterTemplate::defaults(), array_flip(['guru_basis_4', 'guru_basis_5', 'guru_basis_6']));
    $before['guru_basis_1'] = '';
    $before['guru_basis_3'] = 'DPA {sekolah}.';
    Setting::put(LetterTemplate::SETTING_KEY, ['version' => 2, 'values' => $before]);

    $expected = [LetterTemplate::FIELDS['guru_basis_2'][4], 'DPA SMA Negeri 3 Semarang.'];

    expect(LetterTemplate::current()->guruBasisItems('2026/2027'))->toBe($expected)
        ->and(LetterTemplate::fromArray(['version' => 2, 'values' => $before])->guruBasisItems('2026/2027'))->toBe($expected);

    // the screen shows them closed up, two rows, and the closed-up list is not an unsaved change (SV9 review)
    Livewire::actingAs($this->tu)->test(TemplateSurat::class)
        ->assertSet('guruDasarRows', 2)
        ->assertSet('form.guru_basis_1', LetterTemplate::FIELDS['guru_basis_2'][4])
        ->assertSet('form.guru_basis_2', 'DPA {sekolah}.')
        ->assertSet('form.guru_basis_3', '')
        ->assertDontSee('Belum disimpan')
        ->call('revert')
        ->assertDontSee('Belum disimpan');
});

it('closes the gaps of the guru list on save and still accepts a guru letter without template texts', function (): void {
    $values = LetterTemplate::validate(array_merge(LetterTemplate::defaults(), [
        'guru_basis_1' => '',
        'guru_basis_2' => 'Peraturan A',
        'guru_basis_3' => ' ',
        'guru_basis_5' => 'Peraturan B',
    ]));

    expect(array_intersect_key($values, array_flip(LetterTemplate::GURU_DASAR)))->toBe([
        'guru_basis_1' => 'Peraturan A',
        'guru_basis_2' => 'Peraturan B',
        'guru_basis_3' => '',
        'guru_basis_4' => '',
        'guru_basis_5' => '',
        'guru_basis_6' => '',
    ])->and(LetterTemplate::draft(LetterTemplate::validate(array_merge(LetterTemplate::defaults(), [
        'guru_basis_1' => '', 'guru_basis_2' => '', 'guru_basis_3' => '',
    ])))->guruBasisItems('2026/2027'))->toBe([]);
});

it('shows the guru Dasar as the same list as the student one, up to six rows', function (): void {
    $component = Livewire::actingAs($this->tu)->test(TemplateSurat::class)
        ->assertSet('guruDasarRows', 3)
        ->assertSee('Surat guru: Dasar')
        ->assertSee('Surat guru: Dasar nomor 3')
        ->assertDontSee('Surat guru: Dasar nomor 4')
        ->assertSeeHtml('wire:click="addGuruDasar"')
        ->assertSeeHtml('wire:click="addSiswaDasar"')
        ->assertSeeHtml('wire:click="removeGuruDasar(2)"')
        ->assertSeeHtml('wire:click="moveGuruDasarUp(2)"');

    foreach (range(4, 6) as $rows) {
        $component->call('addGuruDasar')->assertSet('guruDasarRows', $rows)->assertSet('form.'.LetterTemplate::GURU_DASAR[$rows - 1], '');
    }

    $component->assertSee('Surat guru: Dasar nomor 6')
        ->assertDontSeeHtml('wire:click="addGuruDasar"')
        ->call('addGuruDasar')
        ->assertDispatched('sth-toast', message: 'Dasar surat guru paling banyak 6.', tone: 'warning')
        ->assertSet('guruDasarRows', 6);

    expect(fn () => $component->set('guruDasarRows', 7))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('moves, removes and saves guru rows, never the last one, and prints the fourth text before the invitation', function (): void {
    $dasar1 = LetterTemplate::FIELDS['guru_basis_1'][4];
    $dasar2 = LetterTemplate::FIELDS['guru_basis_2'][4];

    $component = Livewire::actingAs($this->tu)->test(TemplateSurat::class)
        ->call('addGuruDasar')
        ->set('form.guru_basis_4', D200_DASAR_4)
        ->call('moveGuruDasarUp', 1)
        ->assertSet('form.guru_basis_1', $dasar2)
        ->assertSet('form.guru_basis_2', $dasar1)
        ->call('moveGuruDasarUp', 0)
        ->call('moveGuruDasarUp', 4)
        ->assertSet('form.guru_basis_1', $dasar2)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('guruDasarRows', 4);

    expect(LetterTemplate::current()->guruBasisItems('2026/2027'))->toHaveCount(4)
        ->and(LetterTemplate::current()->guruBasisItems('2026/2027')[3])->toBe('Program Kerja SMA Negeri 3 Semarang Tahun Pelajaran 2026/2027');

    // the guru preview numbers the four texts and the sample's invitation as 5.
    $component->call('showPreview', 'guru')
        ->assertSee('Program Kerja SMA Negeri 3 Semarang Tahun Pelajaran 2026/2027')
        ->assertSee('Surat dari Panitia NSIF 2026');

    foreach ([0, 0, 0] as $index) {
        $component->call('removeGuruDasar', $index);
    }

    $component->assertSet('guruDasarRows', 1)
        ->call('removeGuruDasar', 0)
        ->assertDispatched('sth-toast', message: 'Surat guru perlu minimal satu baris dasar. Kosongkan barisnya bila tidak perlu.', tone: 'warning')
        ->assertSet('guruDasarRows', 1);
});

it('lets a reader of letters who may not change the template neither add, remove nor move a guru Dasar', function (RoleName $role): void {
    $reader = F::staff($role);

    Livewire::actingAs($reader)->test(TemplateSurat::class)
        ->assertOk()
        ->assertDontSeeHtml('wire:click="addGuruDasar"')
        ->assertDontSeeHtml('wire:click="removeGuruDasar');

    foreach ([['addGuruDasar'], ['removeGuruDasar', 0], ['moveGuruDasarUp', 1]] as $call) {
        Livewire::actingAs($reader)->test(TemplateSurat::class)->call(...$call)->assertForbidden();
    }
})->with([RoleName::KepalaSekolah, RoleName::Kesiswaan]);

it('shows the template Dasar on the guru tab of Surat tugas like the student tab, the invitation as the next number (SV9 review)', function (): void {
    F::signature();
    $registration = F::registration(RegistrationStatus::Disetujui, ['Raka Pradipta'], F::mentor());
    app(UpdateLetterTemplate::class)->handle($this->tu, LetterTemplate::withDasar('guru', LetterTemplate::defaults(), [
        LetterTemplate::FIELDS['guru_basis_1'][4], LetterTemplate::FIELDS['guru_basis_2'][4], LetterTemplate::FIELDS['guru_basis_3'][4], D200_DASAR_4,
    ]));

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->call('setTab', 'guru')
        ->assertSee('Dasar 1–4')
        ->assertSee('Sama untuk semua surat guru.')
        ->assertSeeHtml('<li wire:key="dasar-template-3">Program Kerja SMA Negeri 3 Semarang Tahun Pelajaran 2026/2027</li>')
        ->assertSee('Dasar 5');

    // the issued guru letter lists what it printed: the four texts, then the invitation; no "dengan lampiran"
    $letter = app(IssueLetters::class)->handle($this->tu, $registration, LetterType::Guru, new LetterDraft(
        number: '400.3.8.6/1681/2026',
        issuedOn: CarbonImmutable::parse('2026-10-01'),
        basisFrom: 'Panitia NSIF 2026',
        basisNumber: '012/NSIF/IX/2026',
        basisDate: CarbonImmutable::parse('2026-09-15'),
        scheduleDay: 'Sabtu',
        scheduleDate: '7 November 2026',
        scheduleTime: '07.00 WIB s.d Selesai',
        place: 'Daring',
        basisSubject: 'Undangan',
    ));
    expect($letter->printed['template']['values']['guru_basis_4'])->toBe(D200_DASAR_4);

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->call('setTab', 'guru')
        ->assertSeeHtml('<li>Program Kerja SMA Negeri 3 Semarang Tahun Pelajaran 2026/2027</li>')
        ->assertSeeHtml('<li>Surat dari Panitia NSIF 2026, nomor 012/NSIF/IX/2026, tanggal 15 September 2026, hal Undangan</li>')
        ->assertDontSee('dengan lampiran');
});
