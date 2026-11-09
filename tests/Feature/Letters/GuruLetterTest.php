<?php

declare(strict_types=1);

use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterContent;
use App\Actions\Letters\LetterDraft;
use App\Actions\Templates\LetterTemplate;
use App\Domain\Letters\JustifiedLines;
use App\Domain\Letters\PdfQrCode;
use App\Domain\Letters\PdfRenderer;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Livewire\TataUsaha\SuratTugas;
use App\Models\Letter;
use App\Models\Registration;
use Carbon\CarbonImmutable;
use Database\Seeders\SignatureSeeder;
use Dompdf\Dompdf;
use Dompdf\Frame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;
use Tests\Support\TestDisks;

/*
 * SV8, D-194 (lead, 2026-11-09: "samakan persis seperti template pdf nya harus identik 100%"): the guru letter after the
 * school's PDF of that day, measured line by line: one page, the one guru right under "Kepada", the kegiatan bold, no
 * Lampiran, the Word way of justifying; a letter printed before keeps the preview of what it printed (D-108).
 * Fictional people and numbers only; the regulation texts are the template's built-in ones.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    $this->tu = F::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani']);
    $this->mentor = F::mentor('Drs. Bambang Hartono, M.Pd.');
    F::signature();
});

const SV8G_NUMBER = '800.1.11.1/1999/2026';

function sv8gDraft(string $number = SV8G_NUMBER, string $place = 'Gedung Serbaguna Universitas Contoh, Kota Semarang'): LetterDraft
{
    return new LetterDraft(
        number: $number,
        issuedOn: CarbonImmutable::parse('2026-11-04'),
        basisFrom: 'Panitia Lomba Contoh 2026',
        basisNumber: '012/PAN-LC/X/2026',
        basisDate: CarbonImmutable::parse('2026-11-02'),
        scheduleDay: 'Jumat s.d Selasa',
        scheduleDate: '06 s.d 10 November 2026',
        scheduleTime: '15.30',
        place: $place,
        basisSubject: 'Permohonan Pendamping',
    );
}

function sv8gRegistration(object $t, RegistrationStatus $status = RegistrationStatus::Disetujui): Registration
{
    return F::registration($status, ['Raka Pradipta', 'Bima Satria Wicaksono'], $t->mentor, ['name' => 'Olimpiade Sains Contoh 2026']);
}

function sv8gContent(object $t, ?LetterDraft $draft = null): LetterContent
{
    return LetterContent::build(sv8gRegistration($t), $draft ?? sv8gDraft());
}

function sv8gHtml(LetterContent $content, ?LetterTemplate $template = null): string
{
    View::addNamespace(PdfRenderer::VIEW_NAMESPACE, resource_path('pdf'));

    return view('pdf::surat-perintah-tugas-guru', [
        'content' => $content,
        'number' => SV8G_NUMBER,
        'verification' => ['code' => str_repeat('a', 32), 'url' => 'https://contoh.test/verifikasi-surat/'.str_repeat('a', 32)],
        'pdfImages' => [],
        'letterTemplate' => $template ?? LetterTemplate::draft([]),
    ])->render();
}

/**
 * Lays the guru letter out with the renderer's own dompdf options and returns the border boxes [x, y, w, h] in pt:
 * per class (first element with it) and per image alt text, plus the page count.
 *
 * @return array{class: array<string, list<float>>, img: array<string, list<float>>, pages: int}
 */
function sv8gBoxes(LetterContent $content): array
{
    View::addNamespace(PdfRenderer::VIEW_NAMESPACE, resource_path('pdf'));
    $sandbox = TestDisks::directory('pdf-guru');
    file_put_contents($sandbox.'/ttd.png', SignatureSeeder::generatePng());

    $html = view('pdf::surat-perintah-tugas-guru', [
        'content' => $content,
        'number' => SV8G_NUMBER,
        'verification' => ['code' => str_repeat('a', 32), 'url' => 'https://contoh.test/verifikasi-surat/'.str_repeat('a', 32)],
        'pdfImages' => ['ttd.png' => $sandbox.'/ttd.png'],
        'letterTemplate' => LetterTemplate::draft([]),
    ])->render();

    $found = ['class' => [], 'img' => []];
    $dompdf = new Dompdf(app(PdfRenderer::class)->options($sandbox));
    $dompdf->setBasePath($sandbox);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    // dompdf drops each page's frames once it is drawn, so the boxes are read while they are drawn.
    $dompdf->setCallbacks([['event' => 'end_frame', 'f' => static function (Frame $frame) use (&$found): void {
        $node = $frame->get_node();

        if (! $node instanceof DOMElement) {
            return;
        }

        $border = $frame->get_border_box();
        $box = [(float) $border['x'], (float) $border['y'], (float) $border['w'], (float) $border['h']];

        if ($node->tagName === 'img') {
            $found['img'][$node->getAttribute('alt')] ??= $box;
        }

        foreach (preg_split('/\s+/', $node->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY) as $class) {
            $found['class'][$class] ??= $box;
        }
    }]]);
    $dompdf->render();
    $found['pages'] = (int) $dompdf->getCanvas()->get_page_count();

    return $found;
}

// What the letter says ------------------------------------------------------------------------------------------

it('words "Untuk 1" like the school\'s letter, the kegiatan apart for bold, and writes days with two digits', function (): void {
    $content = sv8gContent($this);

    expect($content->guruTaskParts())->toBe([
        'Melaksanakan tugas kegiatan ',
        'Olimpiade Sains Contoh 2026',
        ' pada hari Jumat s.d Selasa tanggal 06 s.d 10 November 2026, Pukul 15.30 di Gedung Serbaguna Universitas Contoh, Kota Semarang',
    ])
        ->and($content->guruTask())->toBe('Melaksanakan tugas kegiatan Olimpiade Sains Contoh 2026 pada hari Jumat s.d Selasa tanggal 06 s.d 10 November 2026, Pukul 15.30 di Gedung Serbaguna Universitas Contoh, Kota Semarang')
        // the invitation is Dasar 4: "Surat dari … Nomor … Tanggal 02 November 2026 Hal …"
        ->and($content->guruBasis())->toBe('Surat dari Panitia Lomba Contoh 2026 Nomor 012/PAN-LC/X/2026 Tanggal 02 November 2026 Hal Permohonan Pendamping')
        ->and($content->guruSignedOn())->toBe('04 November 2026')
        // the student letter and the stored content keep the plain day
        ->and($content->issuedOnText)->toBe('4 November 2026')
        ->and($content->basisDateText)->toBe('2 November 2026');

    // A letter printed before D-194 is previewed with the words it printed.
    expect($content->guruTask(LetterTemplate::GURU_LAYOUT_LAMPIRAN))->toStartWith('Melaksanakan tugas kegiatan mendampingi siswa Lomba Olimpiade Sains Contoh 2026 pada hari')
        ->and($content->guruBasis(twoDigitDay: false))->toContain('Tanggal 2 November 2026 Hal');

    // Online: "secara daring" instead of the place; two-digit days stay as they are.
    $online = sv8gContent($this, sv8gDraft(place: 'Daring'));
    expect($online->guruTaskParts()[2])->toEndWith('Pukul 15.30 secara daring');
});

it('stores the layout with every new letter and reads a letter printed before as the one with the Lampiran', function (): void {
    $template = LetterTemplate::current();

    expect($template->guruLayout)->toBe(LetterTemplate::GURU_LAYOUT)
        ->and(LetterTemplate::GURU_LAYOUT)->toBe(LetterTemplate::GURU_LAYOUT_LANGSUNG)
        ->and($template->toArray()['guru_layout'])->toBe('langsung')
        ->and(LetterTemplate::fromArray($template->toArray())?->guruLayout)->toBe('langsung')
        // letters.printed of a letter from before SV8 has no layout, or an unknown one: the M1-A letter
        ->and(LetterTemplate::fromArray(['version' => 3, 'values' => []])?->guruLayout)->toBe('lampiran')
        ->and(LetterTemplate::fromArray(['version' => 3, 'values' => [], 'guru_layout' => 'lain'])?->guruLayout)->toBe('lampiran')
        ->and(LetterTemplate::draft([])->guruLayout)->toBe('langsung');

    // Every view of a layout exists, so a key without its partials fails here.
    foreach (LetterTemplate::GURU_LAYOUTS as $layout) {
        expect(view()->exists('livewire.tata-usaha.partials.pratinjau-guru-'.$layout))->toBeTrue();
    }

    View::addNamespace(PdfRenderer::VIEW_NAMESPACE, resource_path('pdf'));
    expect(view()->exists('pdf::partials.guru-'.LetterTemplate::GURU_LAYOUT))->toBeTrue();
});

it('marks only the host of a web address in a kop line as the link', function (): void {
    expect(LetterTemplate::kopLineParts('Laman https://sekolah-contoh.sch.id, Pos-el info@contoh.test'))->toBe([
        ['text' => 'Laman https://', 'link' => false],
        ['text' => 'sekolah-contoh.sch.id', 'link' => true],
        ['text' => ', Pos-el info@contoh.test', 'link' => false],
    ])
        ->and(LetterTemplate::kopLineParts('Laman http://contoh.test'))->toBe([
            ['text' => 'Laman http://', 'link' => false],
            ['text' => 'contoh.test', 'link' => true],
        ])
        ->and(LetterTemplate::kopLineParts('Kode Pos 50132, Telepon 3544287'))->toBe([['text' => 'Kode Pos 50132, Telepon 3544287', 'link' => false]]);
});

// The PDF ---------------------------------------------------------------------------------------------------------

it('lays the guru letter out like the school\'s PDF: inline "Kepada", bold kegiatan, no Lampiran', function (): void {
    $html = sv8gHtml(sv8gContent($this));
    $flat = (string) preg_replace('/\s+/', ' ', $html);
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($dom);

    expect($html)->toContain('<div class="g-judul">SURAT PERINTAH TUGAS</div>')
        // "Nomor" without a colon, as on the paper
        ->toContain('<div class="g-nomor">Nomor '.SV8G_NUMBER.'</div>')
        ->toContain('<div class="g-perintah">MEMERINTAHKAN:</div>')
        ->toContain('logo-pemprov-guru.png')
        ->toContain('logo-sekolah.jpg')
        ->toContain('@page { margin: 23.8pt 17.83pt 15.3pt 55.2pt; }')
        ->not->toContain('Terlampir')
        ->not->toContain('Lampiran')
        ->not->toContain('kurung-kurawal.svg')
        ->not->toContain('class="halaman-baru"')
        ->not->toContain('DAFTAR PENDAMPING');

    // "Dasar": the three regulation texts of the template, then the invitation as number 4.
    $numbers = array_map(fn (DOMNode $n): string => trim($n->textContent), iterator_to_array($xpath->query('//table[contains(@class, "g-dasar")]//td[@class="n"]')));
    $labels = array_map(fn (DOMNode $n): string => trim($n->textContent), iterator_to_array($xpath->query('//table[contains(@class, "g-dasar")]//td[@class="k"]')));
    $fourth = trim(preg_replace('/\s+/', ' ', $xpath->query('//table[contains(@class, "g-dasar")]//tr[4]/td[@class="isi"]')->item(0)->textContent));

    expect($numbers)->toBe(['1.', '2.', '3.', '4.'])
        ->and($labels)->toBe(['Dasar', '', '', ''])
        ->and($fourth)->toBe('Surat dari Panitia Lomba Contoh 2026 Nomor 012/PAN-LC/X/2026 Tanggal 02 November 2026 Hal Permohonan Pendamping');

    // "Kepada": the one guru right under it, every label with its own colon.
    expect($flat)->toContain('<td class="k">Kepada</td><td class="s">:</td><td class="n">1.</td><td class="label">Nama</td><td class="titik">:</td><td class="nilai">Drs. Bambang Hartono, M.Pd.</td>')
        ->toContain('<td class="label">Pangkat/Gol</td><td class="titik">:</td><td class="nilai">Penata, III/c</td>')
        ->toContain('<td class="label">NIP</td><td class="titik">:</td><td class="nilai">198001012005011001</td>')
        ->toContain('<td class="label">Jabatan</td><td class="titik">:</td><td class="nilai">Guru</td>');

    // "Untuk": the kegiatan bold (and only it), then the template's points 2 and 3.
    $bold = array_map(fn (DOMNode $n): string => $n->textContent, iterator_to_array($xpath->query('//table[contains(@class, "g-untuk")]//span[@class="tebal"]')));
    $untuk = array_map(fn (DOMNode $n): string => trim(preg_replace('/\s+/', ' ', $n->textContent)), iterator_to_array($xpath->query('//table[contains(@class, "g-untuk")]//td[@class="isi"]')));

    expect(implode(' ', $bold))->toBe('Olimpiade Sains Contoh 2026')
        ->and($untuk[0])->toBe(sv8gContent($this)->guruTask())
        ->and($untuk[1])->toBe('Melaporkan hasil pelaksanaan tugas kepada pejabat pemberi tugas.')
        ->and($untuk[2])->toBe('Perintah ini dilaksanakan dengan penuh tanggung jawab.');

    // Signature: city + the two-digit date, the signer title, the image, name, rank, NIP; plain (no bold, no underline).
    expect($flat)->toContain('<div>Semarang, 04 November 2026</div> <div>Kepala Sekolah</div>')
        ->and($xpath->query('//div[contains(@class, "g-ttd")]//div[@class="nama"]')->length)->toBe(1)
        ->and($html)->not->toContain('.g-ttd .nama {');

    // The kop: the web address host in blue, the scheme and the rest plain.
    expect($flat)->toContain('<span class="">Laman https://</span><span class="tautan">sman3-smg.sch.id</span><span class="">, Pos-el smanegeri3semarang149@gmail.com</span>');
});

it('renders the guru letter on one page at the places measured on the school\'s PDF', function (): void {
    $found = sv8gBoxes(sv8gContent($this));
    $at = static fn (array $box, array $expected): mixed => expect(array_map(fn (float $v): float => round($v, 2), array_slice($box, 0, count($expected))))->toEqualWithDelta($expected, 0.01);

    expect($found['pages'])->toBe(1);

    // Kop: both emblems where the school's PDF has them, the rule from the left emblem's edge (55.2) to the right emblem's edge (577.45 pt) at 114.99 pt (SV9, D-200).
    $at($found['img']['Lambang Provinsi Jawa Tengah'], [55.2, 23.8, 81.7, 85.05]);
    $at($found['img']['Lambang SMA Negeri 3 Semarang'], [513.6, 26.35, 63.85, 85.05]);
    $at($found['class']['kop-garis-g'], [55.2, 114.99, 522.25]);
    // Titles centred on their own axes (311.13, 308.53 and 326.74 pt).
    $centre = static fn (array $box): float => round($box[0] + $box[2] / 2, 2);
    expect($centre($found['class']['g-judul']))->toEqualWithDelta(311.13, 0.01)
        ->and($centre($found['class']['g-nomor']))->toEqualWithDelta(308.53, 0.01)
        ->and($centre($found['class']['g-perintah']))->toEqualWithDelta(326.74, 0.01);
    // Columns: Dasar / Untuk at 90.42 pt (Untuk 0.2 pt further left), texts 367.41 / 367.61 pt wide, Kepada at 85.03 pt,
    // the guru's data at 277.7 pt, the signature at 333.3 pt.
    $at($found['class']['g-dasar'], [90.42]);
    $at($found['class']['g-untuk'], [90.22]);
    $at($found['class']['isi'], [181.05, $found['class']['isi'][1], 367.41]);
    $at($found['class']['g-kepada'], [85.03]);
    $at($found['class']['nilai'], [277.7, $found['class']['nilai'][1], 270.76]);
    $at($found['class']['g-ttd'], [333.3]);
    // Lines 14.89 pt apart (dompdf: line-height x 1.0175), the "Kepada" rows 13.8 pt.
    $at([$found['class']['baris'][3]], [14.89]);
    $at([$found['class']['label'][3]], [13.8]);
});

it('sets the justified texts the Word way: the school\'s line breaks, narrower spaces when a word still fits', function (): void {
    $justify = JustifiedLines::helvetica(12);
    $lines = static fn (string $text, float $width): array => array_map(
        fn (array $line): string => implode('', array_column($line['runs'], 'text')),
        $justify->split([['text' => $text, 'bold' => false]], $width),
    );
    $tpl = LetterTemplate::draft([]);
    [$dasar1, $dasar2, $dasar3] = $tpl->guruBasisItems('2026/2027');

    // The built-in regulation texts break exactly where the school's PDF breaks them.
    expect($lines($dasar1, 367.41))->toBe([
        'Peraturan Daerah Provinsi Jawa Tengah Nomor 11 Tahun 2025',
        'tentang Anggaran Pendapatan Daerah dan Belanja Daerah Tahun',
        'Anggaran 2026 (Lembaran Daerah Provinsi Jawa Tengah Tahun',
        '2025 Nomor 11, Tambahan Lembaran Provinsi Jawa Tengah Nomor',
        '173) (tanggal 30 Desember 2025);',
    ])
        ->and($lines($dasar2, 367.41))->toBe([
            'Peraturan Gubernur Jawa Tengah Nomor 58 Tahun 2023 tentang',
            'Perubahan Atas Peraturan Gubernur Nomor 45 Tahun 2022 tentang',
            'pedoman Perjalanan Dinas Dalam Negeri di Lingkungan Pemerintah',
            'Provinsi Jawa Tengah (Berita Daerah Provinsi Jawa Tengah Tahun',
            '2023 Nomor 58);',
        ])
        ->and($lines($dasar3, 367.41))->toBe([
            'Peraturan Gubernur Jawa Tengah Nomor 36 Tahun 2025 tentang',
            'Penjabaran Anggaran Pendapatan Dan Belanja Daerah Tahun',
            'Anggaran 2026 (Berita Daerah Provinsi Jawa Tengah Tahun 2025',
            'Nomor 36) (tanggal 30 Desember 2025);',
        ]);

    // "… Pukul 15.30" is wider than the column with normal spaces; the school's Word keeps it on the line with
    // narrower spaces (dompdf alone would move "15.30" down).
    $split = $justify->split([['text' => 'hari Jumat s.d Selasa tanggal 06 s.d 10 November 2026, Pukul 15.30 di Aula Utama', 'bold' => false]], 367.61);
    expect(implode('', array_column($split[0]['runs'], 'text')))->toBe('hari Jumat s.d Selasa tanggal 06 s.d 10 November 2026, Pukul 15.30')
        ->and($split[0]['spacing'])->toBeLessThan(0.0)
        ->and($split[1]['spacing'])->toBe(0.0);

    // A justified line ends at the column less at most a few tenths (whole twips per space), never past it.
    $metrics = (new Dompdf)->getFontMetrics();
    foreach ($justify->split([['text' => $dasar1, 'bold' => false]], 367.41) as $index => $line) {
        $text = implode('', array_column($line['runs'], 'text'));
        $width = $metrics->getTextWidth($text, $metrics->getFont('Helvetica', 'normal'), 12, $line['spacing']);

        if ($index < 4) {
            expect($width)->toBeLessThanOrEqual(367.41 + 0.001)->toBeGreaterThan(367.41 - 0.05 * substr_count($text, ' ') - 0.001);
        }
    }
});

it('keeps "Untuk" and the signature together on the next page when a long lomba does not fit (QA8)', function (): void {
    $long = str_repeat('Gedung Pertemuan Contoh Lantai Tiga, ', 25).'Kota Semarang';
    $found = sv8gBoxes(sv8gContent($this, sv8gDraft(place: $long)));

    expect($found['pages'])->toBe(2);

    $html = sv8gHtml(sv8gContent($this));
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $box = (new DOMXPath($dom))->query('//div[@class="utuh"][.//table[contains(@class, "g-untuk")]][.//div[contains(@class, "g-ttd")]]');

    expect($box->length)->toBe(1);
});

// Signature + stamp (both letters) are in LetterLayoutTest (SV8 section).

// The TU preview -------------------------------------------------------------------------------------------------

it('previews the guru letter like its PDF: inline "Kepada", bold kegiatan, no Lampiran page', function (): void {
    $registration = sv8gRegistration($this);

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->call('setTab', 'guru')
        ->assertSee('SURAT PERINTAH TUGAS')
        ->assertSee('MEMERINTAHKAN:')
        ->assertSee('Drs. Bambang Hartono, M.Pd.')
        ->assertSee('198001012005011001')
        ->assertSeeHtml('<b>Olimpiade Sains Contoh 2026</b>')
        ->assertSeeHtml('aria-label="Preview surat perintah tugas guru"')
        // the PDF page at the sheet's scale (0.94 px per pt), the emblem of the school's letter
        ->assertSeeHtml('sm:pl-[51.9px]')
        ->assertSeeHtml(route('tu.surat.kop', ['logo' => 'pemprov-guru']))
        ->assertSeeHtml('<span class="text-[#00f] underline">sman3-smg.sch.id</span>')
        ->assertDontSee('Terlampir')
        ->assertDontSee('Lampiran Surat Tugas')
        ->assertDontSee('Halaman 2')
        ->assertDontSee('mendampingi siswa');
});

it('stores the new layout with an issued guru letter and keeps the Lampiran preview of a letter printed before', function (): void {
    $registration = sv8gRegistration($this);
    $letter = app(IssueLetters::class)->handle($this->tu, $registration, LetterType::Guru, sv8gDraft());

    expect($letter->printed['template']['guru_layout'])->toBe('langsung');

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration->fresh()])
        ->call('setTab', 'guru')
        ->assertSee(SV8G_NUMBER)
        ->assertSeeHtml('<b>Olimpiade Sains Contoh 2026</b>')
        ->assertDontSee('Terlampir');

    // The same letter as if it was printed before SV8: no layout in its stored template = the M1-A letter.
    $printed = $letter->printed;
    unset($printed['template']['guru_layout']);
    $letter->forceFill(['printed' => $printed])->save();

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration->fresh()])
        ->call('setTab', 'guru')
        ->assertSee('Terlampir')
        ->assertSee('Lampiran Surat Tugas Kepala SMA Negeri 3 Semarang')
        ->assertSee('mendampingi siswa Lomba Olimpiade Sains Contoh 2026')
        ->assertSee('Tanggal 2 November 2026')
        ->assertDontSeeHtml('<b>Olimpiade Sains Contoh 2026</b>');
});

it('serves the emblem of the guru letter to Tata Usaha only', function (): void {
    $this->actingAs($this->tu)->get(route('tu.surat.kop', ['logo' => 'pemprov-guru']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    foreach ([RoleName::KepalaSekolah, RoleName::Kesiswaan, RoleName::Guru] as $role) {
        $this->actingAs(F::staff($role))->get(route('tu.surat.kop', ['logo' => 'pemprov-guru']))->assertForbidden();
    }

    // A PNG without an alpha channel, like the other emblem (dompdf embeds it without a per-pixel pass).
    $png = (string) file_get_contents(resource_path('pdf/assets/logo-pemprov-guru.png'));
    expect(getimagesize(resource_path('pdf/assets/logo-pemprov-guru.png'))[2])->toBe(IMAGETYPE_PNG)
        ->and(ord($png[25]))->toBe(2);
});

it('renders a real guru PDF with the QR footer and one page', function (): void {
    $url = 'https://contoh.test/verifikasi-surat/'.str_repeat('a', 32);
    $document = app(PdfRenderer::class)->render('surat-perintah-tugas-guru', [
        'content' => sv8gContent($this),
        'number' => SV8G_NUMBER,
        'verification' => ['code' => str_repeat('a', 32), 'url' => $url],
    ], ['ttd.png' => SignatureSeeder::generatePng(), 'qr.png' => (new PdfQrCode)->png($url, 6)]);

    expect($document->bytes)->toStartWith('%PDF-')
        ->and($document->pageCount)->toBe(1);
});
