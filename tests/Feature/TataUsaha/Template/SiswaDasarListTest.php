<?php

declare(strict_types=1);

use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterContent;
use App\Actions\Letters\LetterDraft;
use App\Actions\Templates\LetterTemplate;
use App\Actions\Templates\UpdateLetterTemplate;
use App\Domain\Letters\PdfRenderer;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Livewire\TataUsaha\SuratTugas;
use App\Livewire\TataUsaha\TemplateSurat;
use App\Models\Letter;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
 * SV8, D-195 (lead, 2026-11-09: "Di tu, template surat siswa, dasarnya baru bisa 1. Dibuat bisa lebih dari 1 kayak
 * surat guru"): the student letter's Dasar is a list of 1 to 6 texts of TU › Template surat; the invitation of a
 * letter stays the last number (D-157); one text alone is printed without a number (D-154). Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->withoutVite();
    $this->tu = F::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani']);
});

const D195_DASAR_2 = 'Program Kerja {sekolah} Tahun Pelajaran {tahun_ajaran}';
const D195_DASAR_3 = 'Keputusan Kepala {sekolah} tentang Tim Pembina Lomba';
const D195_INVITATION = 'Surat dari Panitia NSIF 2026 Nomor 012/NSIF/IX/2026 Tanggal 15 September 2026';

/** The built-in text first, then $more (codes not filled yet). @param list<string> $more */
function d195Template(array $more = []): LetterTemplate
{
    return LetterTemplate::draft(LetterTemplate::withSiswaDasar(LetterTemplate::defaults(), [LetterTemplate::FIELDS['siswa_dasar'][4], ...$more]));
}

/** The fictional NSIF sample of Template surat, with its invitation or (false) with "Surat dari" emptied. */
function d195Content(bool $invitation = true): LetterContent
{
    $sample = TemplateSurat::sample();

    return $invitation ? $sample : new LetterContent(...array_merge(get_object_vars($sample), ['basisFrom' => '', 'basisNumber' => null, 'basisDateText' => null]));
}

function d195Html(LetterContent $content, LetterTemplate $template): DOMXPath
{
    View::addNamespace(PdfRenderer::VIEW_NAMESPACE, resource_path('pdf'));
    $html = view('pdf::surat-tugas-siswa', [
        'content' => $content,
        'letterTemplate' => $template,
        'number' => '400.3.8/313/MRD/X/2026',
        'verification' => ['code' => str_repeat('a', 32), 'url' => 'https://contoh.test/verifikasi-surat/'.str_repeat('a', 32)],
        'pdfImages' => [],
    ])->render();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

    return new DOMXPath($dom);
}

/**
 * Every text run of page 1 of a real dompdf PDF ("BT x y Td /F1 11.0 Tf [(text)] TJ ET"), top to bottom.
 *
 * @return list<array{x: float, y: float, size: float, text: string}>
 */
function d195PdfRuns(LetterContent $content, LetterTemplate $template): array
{
    $bytes = app(PdfRenderer::class)->render('surat-tugas-siswa', [
        'content' => $content,
        'letterTemplate' => $template,
        'number' => '400.3.8/313/MRD/X/2026',
        'verification' => ['code' => str_repeat('a', 32), 'url' => 'https://contoh.test/v/'.str_repeat('a', 32)],
    ])->bytes;
    preg_match_all('~stream\n(.*?)\nendstream~s', $bytes, $streams);

    foreach ($streams[1] as $stream) {
        $page = (string) @gzuncompress($stream);

        if (! str_contains($page, '[(Dasar)] TJ')) {
            continue;
        }

        preg_match_all('~BT ([\d.]+) ([\d.]+) Td /F\d+ ([\d.]+) Tf\s+\[\((.*?)\)\] TJ ET~', $page, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m): array => ['x' => (float) $m[1], 'y' => (float) $m[2], 'size' => (float) $m[3], 'text' => stripcslashes($m[4])], $matches);
    }

    return [];
}

/** @param list<array{x: float, y: float, size: float, text: string}> $runs */
function d195Run(array $runs, string $text): array
{
    foreach ($runs as $run) {
        if ($run['text'] === $text) {
            return $run;
        }
    }

    throw new RuntimeException('No PDF text run "'.$text.'".');
}

// The template ---------------------------------------------------------------------------------------

it('reads a template saved with the one student Dasar, and a letter printed with it, as a one-item list', function (): void {
    expect(LetterTemplate::SISWA_DASAR)->toHaveCount(LetterTemplate::SISWA_DASAR_MAX)
        ->and(LetterTemplate::SISWA_DASAR_MAX)->toBe(6)
        ->and(LetterTemplate::SISWA_DASAR[0])->toBe('siswa_dasar')
        ->and(LetterTemplate::current()->siswaDasarItems('2026/2027'))->toBe([LetterTemplate::FIELDS['siswa_dasar'][4]]);

    // What TU saved before SV8: only `siswa_dasar`, no `siswa_dasar_2` … `siswa_dasar_6`.
    $before = array_diff_key(LetterTemplate::defaults(), array_flip(array_slice(LetterTemplate::SISWA_DASAR, 1)));
    $before['siswa_dasar'] = 'Peraturan lama {sekolah} tahun pelajaran {tahun_ajaran}';
    Setting::put(LetterTemplate::SETTING_KEY, ['version' => 4, 'values' => $before]);

    $current = LetterTemplate::current();
    $printed = LetterTemplate::fromArray(['version' => 4, 'values' => $before]);

    expect($current->version)->toBe(4)
        ->and($current->siswaDasarItems('2026/2027'))->toBe(['Peraturan lama SMA Negeri 3 Semarang tahun pelajaran 2026/2027'])
        ->and($current->values['siswa_dasar_2'])->toBe('')
        ->and($printed->siswaDasarItems('2026/2027'))->toBe(['Peraturan lama SMA Negeri 3 Semarang tahun pelajaran 2026/2027'])
        ->and($printed->oldSiswaBasis('Panitia NSIF 2026'))->toBeNull()
        // and the letter it prints has its one Dasar, unnumbered
        ->and(d195Html(d195Content(false), $current)->query('//table[@class="s-daftar"]')->length)->toBe(0);
});

it('closes the gaps of the student Dasar list and keeps number 1 required', function (): void {
    $values = LetterTemplate::validate(array_merge(LetterTemplate::defaults(), [
        'siswa_dasar' => ' ',
        'siswa_dasar_2' => "Program {sekolah}\n{tahun_ajaran}",
        'siswa_dasar_3' => '',
        'siswa_dasar_4' => 'Keputusan Kepala Sekolah',
    ]));

    expect(array_intersect_key($values, array_flip(LetterTemplate::SISWA_DASAR)))->toBe([
        'siswa_dasar' => 'Program {sekolah} {tahun_ajaran}',
        'siswa_dasar_2' => 'Keputusan Kepala Sekolah',
        'siswa_dasar_3' => '',
        'siswa_dasar_4' => '',
        'siswa_dasar_5' => '',
        'siswa_dasar_6' => '',
    ])->and(LetterTemplate::draft($values)->siswaDasarItems('2026/2027'))->toBe(['Program SMA Negeri 3 Semarang 2026/2027', 'Keputusan Kepala Sekolah']);
});

it('refuses an empty list, a too long text and an unknown code on the row that has it', function (array $override, string $field, string $message): void {
    try {
        LetterTemplate::validate(array_merge(LetterTemplate::defaults(), $override));
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('form.'.$field)
            ->and($e->errors()['form.'.$field][0])->toBe($message);
    }
})->with([
    'every text empty' => [['siswa_dasar' => '', 'siswa_dasar_2' => ' '], 'siswa_dasar', 'Surat siswa: Dasar nomor 1 wajib diisi.'],
    'number 3 too long' => [['siswa_dasar_2' => 'Program kerja', 'siswa_dasar_3' => str_repeat('a', 601)], 'siswa_dasar_3', 'Surat siswa: Dasar nomor 3 maks. 600 karakter.'],
    'invitation code in number 2' => [['siswa_dasar_2' => 'Surat dari {penyelenggara}'], 'siswa_dasar_2', 'Kode {penyelenggara} tidak dikenal. Pakai {sekolah}, {tahun_ajaran}.'],
]);

it('saves the list as a new version and audits every changed number with its label', function (): void {
    $saved = app(UpdateLetterTemplate::class)->handle($this->tu, LetterTemplate::withSiswaDasar(LetterTemplate::defaults(), [
        LetterTemplate::FIELDS['siswa_dasar'][4], D195_DASAR_2, D195_DASAR_3,
    ]));

    $log = Activity::query()->where('event', 'letter_template.updated')->sole();

    expect($saved->version)->toBe(1)
        ->and(LetterTemplate::current()->siswaDasarItems('2026/2027'))->toHaveCount(3)
        ->and($log->properties['changed'])->toBe([
            'siswa_dasar_2' => ['from' => '', 'to' => D195_DASAR_2],
            'siswa_dasar_3' => ['from' => '', 'to' => D195_DASAR_3],
        ])
        ->and($log->properties['snapshot']['siswa_dasar_3'])->toBe(D195_DASAR_3)
        ->and(LetterTemplate::label('siswa_dasar_2'))->toBe('Surat siswa: Dasar nomor 2');

    Livewire::actingAs($this->tu)->test(TemplateSurat::class)
        ->assertSee('Diubah: Surat siswa: Dasar nomor 2, Surat siswa: Dasar nomor 3');
});

// The PDF --------------------------------------------------------------------------------------------

it('numbers two and three Dasar texts in the student PDF, the invitation last, one alone without a number', function (): void {
    $dasar1 = LetterTemplate::FIELDS['siswa_dasar'][4];
    $dasar2 = 'Program Kerja SMA Negeri 3 Semarang Tahun Pelajaran 2026/2027';
    $dasar3 = 'Keputusan Kepala SMA Negeri 3 Semarang tentang Tim Pembina Lomba';
    $rows = fn (DOMXPath $x): array => array_map(
        fn (DOMElement $tr): array => [trim($tr->childNodes->item(0)->textContent), trim($tr->childNodes->item(1)->textContent)],
        iterator_to_array($x->query('//table[@class="s-daftar"]//tr')),
    );

    // two texts of the template, no invitation
    $two = d195Html(d195Content(false), d195Template([D195_DASAR_2]));
    expect($rows($two))->toBe([['1.', $dasar1], ['2.', $dasar2]])
        ->and($two->query('//table[@class="s-dasar"]//td[@class="k"]')->length)->toBe(1);

    // three texts and the invitation: the invitation is number 4
    $four = d195Html(d195Content(), d195Template([D195_DASAR_2, D195_DASAR_3]));
    expect($rows($four))->toBe([['1.', $dasar1], ['2.', $dasar2], ['3.', $dasar3], ['4.', D195_INVITATION]]);

    // one text, no invitation: no list, no number (D-154); one text and the invitation: 1. and 2. (D-157)
    $one = d195Html(d195Content(false), d195Template());
    expect($one->query('//table[@class="s-daftar"]')->length)->toBe(0)
        ->and(trim($one->query('//table[@class="s-dasar"]//td')->item(2)->textContent))->toBe($dasar1)
        ->and($rows(d195Html(d195Content(), d195Template())))->toBe([['1.', $dasar1], ['2.', D195_INVITATION]]);
});

it('prints the numbers in the real PDF in the student letter\'s own measures, kop and table untouched', function (): void {
    $one = d195PdfRuns(d195Content(false), d195Template());
    $three = d195PdfRuns(d195Content(false), d195Template([D195_DASAR_2, 'Keputusan Kepala {sekolah}']));

    // Measured on the PDF: "Dasar" at the margin (61.14 pt), the colon column 103 pt on, the numbers after the 9 pt
    // colon column (173.14 pt) and every text 16 pt further (189.14 pt), wrapped lines under the text, not the number.
    $n1 = d195Run($three, '1.');
    $n2 = d195Run($three, '2.');
    $n3 = d195Run($three, '3.');
    $wrapped = d195Run($three, 'Indonesia Nomor 8 Tahun 2026 Hal : Penggunaan Dana BOS Kinerja');
    $pitch = d195Run($one, 'Dasar')['y'] - d195Run($one, 'Nomor 8 Tahun 2026 Hal : Penggunaan Dana BOS Kinerja bagi sekolah')['y'];

    expect(d195Run($one, 'Dasar')['x'])->toBe(61.14)
        ->and(d195Run($one, 'Peraturan Menteri Pendidikan Dasar dan Menengah Republik Indonesia')['x'])->toBe(173.14)
        ->and(array_column($one, 'text'))->not->toContain('1.')
        ->and([$n1['x'], $n2['x'], $n3['x']])->toBe([173.14, 173.14, 173.14])
        ->and(d195Run($three, 'Peraturan Menteri Pendidikan Dasar dan Menengah Republik')['x'])->toBe(189.14)
        ->and(d195Run($three, 'Program Kerja SMA Negeri 3 Semarang Tahun Pelajaran 2026/2027')['x'])->toBe(189.14)
        ->and(d195Run($three, 'Keputusan Kepala SMA Negeri 3 Semarang')['y'])->toBe($n3['y'])
        ->and($wrapped['x'])->toBe(189.14)
        ->and($n1['size'])->toBe(11.0)
        // 11 pt text on the rows of the single Dasar (15 pt line height, 15.26 pt apart in dompdf's output): 1. has
        // three lines, 2. and 3. one each
        ->and($pitch)->toEqualWithDelta(15.26, 0.01)
        ->and($n1['y'] - $wrapped['y'])->toEqualWithDelta($pitch, 0.01)
        ->and($n1['y'] - $n2['y'])->toEqualWithDelta(3 * $pitch, 0.01)
        ->and($n2['y'] - $n3['y'])->toEqualWithDelta($pitch, 0.01);

    // Kop, Nomor and the Dasar row stand where they stood; the intro and the table move down by the two added lines
    // only (the two empty rows above the intro and the table's own rows stay).
    foreach (['PEMERINTAH PROVINSI JAWA TENGAH', 'Nomor : 400.3.8/313/MRD/X/2026', 'Dasar', ':'] as $text) {
        expect(d195Run($three, $text))->toBe(d195Run($one, $text));
    }

    $intro = 'Kepala SMA Negeri 3 Semarang memberikan tugas kepada :';
    expect(d195Run($one, $intro)['y'] - d195Run($three, $intro)['y'])->toEqualWithDelta(2 * $pitch, 0.01)
        ->and(d195Run($three, $intro)['y'] - d195Run($three, 'NO')['y'])->toEqualWithDelta(d195Run($one, $intro)['y'] - d195Run($one, 'NO')['y'], 0.001)
        ->and(d195Run($three, 'NO')['y'] - d195Run($three, 'RAKA PRADIPTA')['y'])->toEqualWithDelta(d195Run($one, 'NO')['y'] - d195Run($one, 'RAKA PRADIPTA')['y'], 0.001);
});

// TU › Template surat --------------------------------------------------------------------------------

it('adds student Dasar rows up to six and refuses a seventh', function (): void {
    $component = Livewire::actingAs($this->tu)->test(TemplateSurat::class)
        ->assertSet('siswaDasarRows', 1)
        ->assertSee('Surat siswa: Dasar nomor 1')
        ->assertDontSee('Surat siswa: Dasar nomor 2')
        ->assertSee('Tambah dasar')
        // one row cannot be removed or moved
        ->assertDontSeeHtml('wire:click="removeSiswaDasar(0)"')
        ->assertDontSeeHtml('wire:click="moveSiswaDasarUp(1)"');

    foreach (range(2, 6) as $rows) {
        $component->call('addSiswaDasar')->assertSet('siswaDasarRows', $rows)->assertSet('form.'.LetterTemplate::SISWA_DASAR[$rows - 1], '');
    }

    $component->assertSee('Surat siswa: Dasar nomor 6')
        ->assertDontSee('Tambah dasar')
        ->assertSeeHtml('wire:click="removeSiswaDasar(5)"')
        ->call('addSiswaDasar')
        ->assertDispatched('sth-toast', message: 'Dasar surat siswa paling banyak 6.', tone: 'warning')
        ->assertSet('siswaDasarRows', 6);

    // the number of rows is the server's: the browser cannot set it
    expect(fn () => $component->set('siswaDasarRows', 7))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('removes and moves rows, never the last one, and previews the numbered list', function (): void {
    $component = Livewire::actingAs($this->tu)->test(TemplateSurat::class)
        ->call('addSiswaDasar')
        ->call('addSiswaDasar')
        ->set('form.siswa_dasar_2', D195_DASAR_2)
        ->set('form.siswa_dasar_3', D195_DASAR_3)
        ->assertSee('Belum disimpan')
        // the preview numbers the three texts, codes filled
        ->assertSeeHtml('<span>1.</span><span>'.LetterTemplate::FIELDS['siswa_dasar'][4].'</span>')
        ->assertSeeHtml('<span>2.</span><span>Program Kerja SMA Negeri 3 Semarang Tahun Pelajaran 2026/2027</span>')
        ->assertSeeHtml('<span>3.</span><span>Keputusan Kepala SMA Negeri 3 Semarang tentang Tim Pembina Lomba</span>')
        // the sample's invitation is number 4
        ->assertSeeHtml('<span>4.</span><span>'.D195_INVITATION.'</span>')
        ->call('moveSiswaDasarUp', 2)
        ->assertSet('form.siswa_dasar_2', D195_DASAR_3)
        ->assertSet('form.siswa_dasar_3', D195_DASAR_2)
        // out of range: nothing moves
        ->call('moveSiswaDasarUp', 0)
        ->call('moveSiswaDasarUp', 3)
        ->assertSet('form.siswa_dasar_2', D195_DASAR_3)
        ->call('removeSiswaDasar', 0)
        ->assertSet('siswaDasarRows', 2)
        ->assertSet('form.siswa_dasar', D195_DASAR_3)
        ->assertSet('form.siswa_dasar_2', D195_DASAR_2)
        ->assertSet('form.siswa_dasar_3', '')
        ->call('removeSiswaDasar', 9)
        ->assertSet('siswaDasarRows', 2)
        ->call('removeSiswaDasar', 1)
        ->assertSet('siswaDasarRows', 1)
        ->assertSet('form.siswa_dasar_2', '')
        ->call('removeSiswaDasar', 0)
        ->assertDispatched('sth-toast', message: 'Surat siswa perlu minimal satu dasar.', tone: 'warning')
        ->assertSet('siswaDasarRows', 1)
        ->assertSet('form.siswa_dasar', D195_DASAR_3);

    // one text: the preview has no number (D-154 without the invitation, D-157 with it: 1. and 2.)
    $component->assertDontSeeHtml('<span>3.</span>')
        ->assertSeeHtml('<span>2.</span><span>'.D195_INVITATION.'</span>');
});

it('saves the shown rows closed up, drops the empty ones and ignores a field after them', function (): void {
    $component = Livewire::actingAs($this->tu)->test(TemplateSurat::class)
        ->call('addSiswaDasar')
        ->call('addSiswaDasar')
        ->set('form.siswa_dasar_2', '  ')
        ->set('form.siswa_dasar_3', D195_DASAR_3)
        // a field no row shows (a request the screen never sends) is not part of the template
        ->set('form.siswa_dasar_5', 'Teks tanpa baris')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Template disimpan sebagai versi 1.')
        ->assertSet('siswaDasarRows', 2)
        ->assertSet('form.siswa_dasar_2', D195_DASAR_3)
        ->assertSet('form.siswa_dasar_3', '')
        ->assertSet('form.siswa_dasar_5', '');

    expect(LetterTemplate::current()->siswaDasarItems('2026/2027'))
        ->toBe([LetterTemplate::FIELDS['siswa_dasar'][4], 'Keputusan Kepala SMA Negeri 3 Semarang tentang Tim Pembina Lomba'])
        ->and(LetterTemplate::current()->values['siswa_dasar_5'])->toBe('');

    // every row emptied: the error stands on number 1, nothing is saved
    $component->set('form.siswa_dasar', '')
        ->set('form.siswa_dasar_2', '')
        ->call('save')
        ->assertHasErrors('form.siswa_dasar')
        ->assertSet('siswaDasarRows', 1);
    expect(LetterTemplate::current()->version)->toBe(1);

    // "Kembalikan bawaan": the one built-in text again; "Batalkan perubahan": the saved two rows
    $component->call('restoreDefaults')
        ->assertSet('siswaDasarRows', 1)
        ->assertSet('form.siswa_dasar', LetterTemplate::FIELDS['siswa_dasar'][4])
        ->assertSet('form.siswa_dasar_2', '')
        ->call('revert')
        ->assertSet('siswaDasarRows', 2)
        ->assertSet('form.siswa_dasar_2', D195_DASAR_3);

    // "Pakai lagi" of a version loads its rows
    $component->call('restoreDefaults')->call('save')->assertSet('siswaDasarRows', 1);
    $first = Activity::query()->where('event', 'letter_template.updated')->orderBy('id')->first();
    $component->call('useVersion', $first->id)
        ->assertSet('siswaDasarRows', 2)
        ->assertSet('form.siswa_dasar_2', D195_DASAR_3);
});

it('opens the list for Tata Usaha only', function (RoleName $role): void {
    $this->actingAs(F::staff($role))->get(route('tu.surat.template'))->assertForbidden();
})->with([RoleName::KepalaSekolah, RoleName::Kesiswaan, RoleName::Humas, RoleName::Guru, RoleName::Admin]);

it('lets a reader of letters who may not change the template neither add, remove nor move a Dasar', function (RoleName $role): void {
    $reader = F::staff($role);

    Livewire::actingAs($reader)->test(TemplateSurat::class)
        ->assertOk()
        ->assertDontSee('Tambah dasar')
        ->assertDontSeeHtml('wire:click="removeSiswaDasar');

    foreach ([['addSiswaDasar'], ['removeSiswaDasar', 0], ['moveSiswaDasarUp', 1]] as $call) {
        Livewire::actingAs($reader)->test(TemplateSurat::class)->call(...$call)->assertForbidden();
    }
})->with([RoleName::KepalaSekolah, RoleName::Kesiswaan]);

it('keeps the template screen closed to roles that may not read letters', function (RoleName $role): void {
    Livewire::actingAs(F::staff($role))->test(TemplateSurat::class)->assertForbidden();
})->with([RoleName::Humas, RoleName::Guru]);

// TU › Surat tugas -----------------------------------------------------------------------------------

it('shows every template Dasar on the student tab, the invitation as the next number, and keeps letters issued before', function (): void {
    F::signature();
    $mentor = F::mentor();
    $before = F::registration(RegistrationStatus::Disetujui, ['Raka Pradipta', 'Bima Satria Wicaksono'], $mentor);
    $draft = new LetterDraft(
        number: '400.3.8/313/MRD/X/2026',
        issuedOn: CarbonImmutable::parse('2026-10-01'),
        basisFrom: 'Panitia NSIF 2026',
        basisNumber: '017/PAN-NSIF/IX/2026',
        basisDate: CarbonImmutable::parse('2026-09-15'),
        scheduleDay: 'Sabtu',
        scheduleDate: '7 November 2026',
        scheduleTime: '07.00 WIB s.d Selesai',
        place: 'Daring',
    );
    // A letter issued with the one Dasar; what a letter printed before SV8 stored: no `siswa_dasar_2` … at all.
    $old = app(IssueLetters::class)->handle($this->tu, $before, LetterType::Siswa, $draft);
    $printed = $old->printed;
    $printed['template']['values'] = array_diff_key($printed['template']['values'], array_flip(array_slice(LetterTemplate::SISWA_DASAR, 1)));
    $old->forceFill(['printed' => $printed])->save();
    $oldPdf = $old->file?->getKey();

    app(UpdateLetterTemplate::class)->handle($this->tu, LetterTemplate::withSiswaDasar(LetterTemplate::defaults(), [
        LetterTemplate::FIELDS['siswa_dasar'][4], D195_DASAR_2, D195_DASAR_3,
    ]));

    // The letter issued before: still its one template text and the invitation as 2., in the summary and the preview.
    $invitation = 'Surat dari Panitia NSIF 2026 Nomor 017/PAN-NSIF/IX/2026 Tanggal 15 September 2026';
    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $before])
        ->call('setTab', 'siswa')
        ->assertSee('Isi PDF yang sudah dibuat')
        ->assertSeeHtml('<li>'.LetterTemplate::FIELDS['siswa_dasar'][4].'</li>')
        ->assertSeeHtml('<li>'.$invitation.'</li>')
        ->assertSeeHtml('<span>2.</span><span>'.$invitation.'</span>')
        ->assertDontSee('Program Kerja SMA Negeri 3 Semarang')
        ->assertDontSeeHtml('<span>3.</span>');
    expect($old->fresh()->file?->getKey())->toBe($oldPdf);

    // A new letter: the three texts of the template (Dasar 1–3), the invitation as Dasar 4, in the form and the preview.
    $next = F::registration(RegistrationStatus::Disetujui, ['Yoga Pratama'], $mentor);
    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $next])
        ->assertSet('tab', 'siswa')
        ->assertSee('Dasar 1–3')
        ->assertSee('Dari Template surat')
        ->assertSee('Program Kerja SMA Negeri 3 Semarang Tahun Pelajaran')
        ->assertSeeHtml('<li wire:key="dasar-template-2">Keputusan Kepala SMA Negeri 3 Semarang tentang Tim Pembina Lomba</li>')
        ->assertSee('Dasar 4')
        ->assertSee('surat siswa lalu hanya memuat Dasar 1–3.')
        ->assertSeeHtml('<span>3.</span><span>Keputusan Kepala SMA Negeri 3 Semarang tentang Tim Pembina Lomba</span>')
        ->assertSeeHtml('<span>4.</span><span>Surat dari Penyelenggara swasta</span>')
        ->set('siswa.nomor', '400.3.8/314/MRD/X/2026')
        ->call('generate')
        ->assertHasNoErrors()
        ->call('setTab', 'siswa')
        ->assertSeeHtml('<li>Keputusan Kepala SMA Negeri 3 Semarang tentang Tim Pembina Lomba</li>')
        ->assertSeeHtml('<li>Surat dari Penyelenggara swasta</li>');

    $letter = Letter::query()->where('registration_id', $next->getKey())->sole();
    expect($letter->printed['template']['values']['siswa_dasar_3'])->toBe(D195_DASAR_3)
        ->and(LetterTemplate::fromArray($letter->printed['template'])->siswaDasarItems('2026/2027'))->toHaveCount(3);

    // "Surat dari" emptied: the three texts stay numbered, no fourth
    expect(LetterContent::build($next, new LetterDraft(
        number: '400.3.8/315/MRD/X/2026',
        issuedOn: CarbonImmutable::parse('2026-10-01'),
        basisFrom: '',
        basisNumber: null,
        basisDate: null,
        scheduleDay: 'Sabtu',
        scheduleDate: '7 November 2026',
        scheduleTime: '07.00 WIB s.d Selesai',
        place: 'Daring',
    ))->siswaBasis(LetterTemplate::current()))->toHaveCount(3);
});
