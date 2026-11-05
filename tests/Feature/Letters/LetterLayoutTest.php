<?php

declare(strict_types=1);

use App\Actions\Letters\LetterContent;
use App\Actions\Letters\LetterDraft;
use App\Actions\Templates\LetterTemplate;
use App\Domain\Letters\PdfDocument;
use App\Domain\Letters\PdfQrCode;
use App\Domain\Letters\PdfRenderer;
use App\Enums\RegistrationStatus;
use App\Models\Letter;
use App\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Database\Seeders\SignatureSeeder;
use Dompdf\Dompdf;
use Dompdf\Frame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;
use Tests\Support\TestDisks;

/*
 * M1-A (lead, 2026-10-04): both letters follow the school's own files ("ST PENGUKUHAN KEG OPSI" for the guru
 * letter, "ST_LOMBA … TIM" for the student letter): what each letter prints (LetterContent), the blocks of the
 * two templates, and real PDFs with the right number of pages. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    $this->mentor = F::mentor();
});

function m1aLayoutDraft(string $date = '7 November 2026 s.d 9 November 2026', string $place = 'Gedung Serbaguna Universitas Contoh'): LetterDraft
{
    return new LetterDraft(
        number: '400.3.8/313/MRD/X/2026',
        issuedOn: CarbonImmutable::parse('2026-10-04'),
        basisFrom: 'Panitia NSIF 2026',
        basisNumber: '012/NSIF/IX/2026',
        basisDate: CarbonImmutable::parse('2026-09-15'),
        scheduleDay: 'Sabtu',
        scheduleDate: $date,
        scheduleTime: '07.00 WIB s.d Selesai',
        place: $place,
    );
}

/** @param list<string> $names */
function m1aLayoutContent(object $t, array $names = ['Raka Pradipta', 'Bima Satria Wicaksono', 'Yoga Pratama'], ?LetterDraft $draft = null): LetterContent
{
    $registration = F::registration(RegistrationStatus::Disetujui, $names, $t->mentor);

    return LetterContent::build($registration, $draft ?? m1aLayoutDraft());
}

function m1aLayoutHtml(string $view, LetterContent $content, string $number): string
{
    View::addNamespace(PdfRenderer::VIEW_NAMESPACE, resource_path('pdf'));

    return view('pdf::'.$view, [
        'content' => $content,
        'number' => $number,
        'verification' => ['code' => str_repeat('a', 32), 'url' => 'https://contoh.test/verifikasi-surat/'.str_repeat('a', 32)],
        'pdfImages' => [],
        'replaces' => '400.3.8/300/MRD/X/2026',
    ])->render();
}

function m1aLayoutPdf(string $view, LetterContent $content, string $number): PdfDocument
{
    $url = 'https://contoh.test/verifikasi-surat/'.str_repeat('a', 32);

    return app(PdfRenderer::class)->render($view, [
        'content' => $content,
        'number' => $number,
        'verification' => ['code' => str_repeat('a', 32), 'url' => $url],
    ], ['ttd.png' => SignatureSeeder::generatePng(), 'qr.png' => (new PdfQrCode)->png($url, 6)]);
}

// What a letter prints -------------------------------------------------------------------------------

it('prints students the way the school does: capitals, "XI - 4", ketua first', function (): void {
    $content = m1aLayoutContent($this);

    expect(array_column($content->students, 'name'))->toBe(['RAKA PRADIPTA', 'BIMA SATRIA WICAKSONO', 'YOGA PRATAMA'])
        ->and(array_column($content->students, 'class'))->toBe(['XI - 4', 'XI - 4', 'XI - 4'])
        ->and(array_column($content->students, 'no'))->toBe([1, 2, 3])
        ->and(LetterContent::classLabel('XII-10'))->toBe('XII - 10')
        ->and(LetterContent::classLabel('X - 3'))->toBe('X - 3')
        ->and(LetterContent::classLabel('Kelas khusus'))->toBe('Kelas khusus');
});

it('takes the people from the database and only the typed fields from the draft', function (): void {
    StaffProfile::query()->where('user_id', $this->mentor->getKey())->update(['position' => 'Guru Ahli Muda']);
    $content = m1aLayoutContent($this);

    expect($content->mentor)->toBe(['name' => 'Hendra Kusuma, S.Pd., M.Si.', 'rank' => 'Penata, III/c', 'nip' => '198001012005011001', 'position' => 'Guru Ahli Muda'])
        ->and($content->competitionShort)->toBe('National Science and Invention Fair (NSIF) 2026')
        ->and($content->organizer)->toBe('Penyelenggara swasta')
        ->and($content->basisFrom)->toBe('Panitia NSIF 2026')
        ->and($content->issuedOnText)->toBe('4 Oktober 2026')
        ->and($content->issuedYear)->toBe('2026');
});

it('words the activity, the ranges and the guru letter texts like the school files', function (): void {
    $content = m1aLayoutContent($this);

    expect($content->activity())->toBe('Lomba National Science and Invention Fair (NSIF) 2026')
        ->and($content->dateRange())->toBe(['7 November 2026', '9 November 2026'])
        ->and($content->timeRange())->toBe(['07.00 WIB', 'Selesai'])
        ->and($content->guruBasis())->toBe('Surat dari Panitia NSIF 2026 Nomor 012/NSIF/IX/2026 Tanggal 15 September 2026 Hal Undangan')
        ->and($content->guruTask())->toBe('Melaksanakan tugas kegiatan mendampingi siswa Lomba National Science and Invention Fair (NSIF) 2026'
            .' pada hari Sabtu tanggal 7 November 2026 s.d 9 November 2026, Pukul 07.00 WIB s.d Selesai di Gedung Serbaguna Universitas Contoh')
        // the year at the end of the lomba name moves to the second line of the Lampiran title
        ->and($content->attachmentTitle())->toBe(['DAFTAR PENDAMPING LOMBA NATIONAL SCIENCE AND INVENTION FAIR (NSIF)', 'TAHUN 2026']);

    $single = m1aLayoutContent($this, ['Hana Safira'], m1aLayoutDraft('7 November 2026', 'Daring'));

    expect($single->dateRange())->toBe(['7 November 2026', null])
        ->and($single->online)->toBeTrue()
        ->and($single->guruTask())->toEndWith('Pukul 07.00 WIB s.d Selesai secara daring');
});

it('does not double "Lomba" and falls back to the letter year in the Lampiran title', function (): void {
    $registration = F::registration(RegistrationStatus::Disetujui, ['Hana Safira'], $this->mentor, ['name' => 'Lomba Debat Bahasa Indonesia']);
    $content = LetterContent::build($registration, m1aLayoutDraft());

    expect($content->activity())->toBe('Lomba Debat Bahasa Indonesia')
        ->and($content->attachmentTitle())->toBe(['DAFTAR PENDAMPING LOMBA DEBAT BAHASA INDONESIA', 'TAHUN 2026']);
});

// The form of one letter -----------------------------------------------------------------------------

it('prefills a new letter from the lomba: number empty, hari and tanggal apart, a range for several days', function (): void {
    $registration = F::registration(RegistrationStatus::Disetujui, ['Hana Safira'], $this->mentor, [
        'organizer_name' => 'Panitia NSIF 2026',
        'location' => 'Kota Semarang',
        'starts_on' => '2026-11-07',
        'final_on' => '2026-11-09',
    ]);

    expect(LetterDraft::defaults($registration, CarbonImmutable::parse('2026-10-04')))->toBe([
        'nomor' => '',
        'tanggalSurat' => '2026-10-04',
        'dasarDari' => 'Panitia NSIF 2026',
        'dasarNomor' => '',
        'dasarTanggal' => '',
        'dasarHal' => 'Undangan',
        'hari' => 'Sabtu s.d Senin',
        'tanggal' => '7 November 2026 s.d 9 November 2026',
        'pukul' => '07.00 WIB s.d Selesai',
        'tempat' => 'Kota Semarang',
    ]);

    $oneDay = F::registration(RegistrationStatus::Disetujui, ['Citra Ayu Lestari'], $this->mentor, ['starts_on' => '2026-11-07', 'final_on' => '2026-11-07', 'mode' => 'daring']);
    $defaults = LetterDraft::defaults($oneDay, CarbonImmutable::parse('2026-10-04'));

    expect($defaults['hari'])->toBe('Sabtu')
        ->and($defaults['tanggal'])->toBe('7 November 2026')
        ->and($defaults['tempat'])->toBe('Daring');
});

it('reads a letter issued before M1-A: "Hari, tanggal" in one column, no "Surat dari"', function (): void {
    $registration = F::registration(RegistrationStatus::SuratTerbit, ['Hana Safira'], $this->mentor);
    $old = Letter::factory()->create([
        'registration_id' => $registration->getKey(),
        'sequence' => 312,
        'issued_on' => '2026-09-08',
        'basis_number' => '017/PAN/IX/2026',
        'basis_date' => '2026-09-01',
        'schedule_day' => 'Sabtu, 7 November 2026',
        'schedule_time' => '07.00 WIB s.d. selesai',
        'place' => 'Daring',
    ])->fresh();

    expect(LetterDraft::fromLetter($old, $registration))->toMatchArray([
        'nomor' => '400.3.8/312/MRD/IX/2026',
        'tanggalSurat' => '2026-09-08',
        'dasarDari' => 'Penyelenggara swasta',
        'dasarNomor' => '017/PAN/IX/2026',
        'dasarTanggal' => '2026-09-01',
        'dasarHal' => 'Undangan',
        'hari' => 'Sabtu',
        'tanggal' => '7 November 2026',
        'pukul' => '07.00 WIB s.d. selesai',
        'tempat' => 'Daring',
    ]);

    // "s.d." with a full stop (old letters) still splits into a range.
    $content = LetterContent::build($registration, m1aLayoutDraft('7 November 2026 s.d. 9 November 2026'));
    expect($content->dateRange())->toBe(['7 November 2026', '9 November 2026']);
});

// Template blocks ------------------------------------------------------------------------------------

it('lays the student letter out like the school sheet', function (): void {
    $html = m1aLayoutHtml('surat-tugas-siswa', m1aLayoutContent($this), '400.3.8/313/MRD/X/2026');

    expect($html)->toContain('S U R A T &nbsp; T U G A S')
        ->toContain('Nomor : 400.3.8/313/MRD/X/2026')
        // D-154: "Dasar" 1 is the regulation text of the school's printed letter; D-157: the invitation TU typed is
        // "Dasar" 2, one sentence without "Hal"
        ->toContain('<tr><td class="n">1.</td><td>Peraturan Menteri Pendidikan Dasar dan Menengah Republik Indonesia Nomor 8 Tahun 2026 Hal : Penggunaan Dana BOS Kinerja bagi sekolah yang memiliki prestasi</td></tr>')
        ->toContain('<tr><td class="n">2.</td><td>Surat dari Panitia NSIF 2026 Nomor 012/NSIF/IX/2026 Tanggal 15 September 2026</td></tr>')
        ->not->toContain('Tanggal 15 September 2026 Hal')
        ->toContain('Kepala SMA Negeri 3 Semarang memberikan tugas kepada :')
        ->toContain('<th class="no">NO</th>')
        ->toContain('NAMA MURID')
        ->toContain('<td class="nama">RAKA PRADIPTA</td>')
        ->toContain('<td class="kelas">XI - 4</td>')
        ->toContain('untuk mengikuti Lomba National Science and Invention Fair (NSIF) 2026, pada :')
        ->toContain('<td class="awal">7 November 2026</td>')
        ->toContain('<td class="sd">s.d</td>')
        ->toContain('<td class="awal">07.00 WIB</td>')
        ->toContain('Gedung Serbaguna Universitas Contoh')
        ->toContain('Demikian surat tugas ini dibuat')
        ->toContain('class="s-ttd utuh"')
        // kop: both emblems from resources/pdf/assets, the three address lines
        ->toContain('logo-pemprov.png')
        ->toContain('logo-sekolah.jpg')
        ->toContain('Kode Pos 50132, Telepon 3544287, Faksimile 024-3544291')
        // verification + "replaces" footer stay
        ->toContain('Kode verifikasi: '.str_repeat('a', 32))
        ->toContain('Surat ini menggantikan surat nomor 400.3.8/300/MRD/X/2026 yang telah dicabut.')
        // Helvetica (= Arial metrics); equal left and right margins, so the letter stands in the middle of the page (RV1)
        ->toContain('font-family: Helvetica, Arial, sans-serif')
        ->toContain('@page { margin: 56.7pt 61.14pt 56.7pt 61.14pt; }');
});

it('gives the student letter one Dasar row and the kop measures of the school letter (D-154)', function (): void {
    // Without an invitation (TU emptied "Surat dari"): one Dasar, no numbers (D-157).
    $withoutInvitation = new LetterDraft(
        number: '400.3.8/313/MRD/X/2026',
        issuedOn: CarbonImmutable::parse('2026-10-04'),
        basisFrom: '',
        basisNumber: null,
        basisDate: null,
        scheduleDay: 'Sabtu',
        scheduleDate: '7 November 2026',
        scheduleTime: '07.00 WIB s.d Selesai',
        place: 'Daring',
    );
    $html = m1aLayoutHtml('surat-tugas-siswa', m1aLayoutContent($this, draft: $withoutInvitation), '400.3.8/313/MRD/X/2026');
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($dom);

    expect($xpath->query('//table[@class="s-dasar"]//tr')->length)->toBe(1)
        ->and($xpath->query('//table[@class="s-daftar"]')->length)->toBe(0)
        ->and($html)->not->toContain('Surat dari')
        ->and(trim($xpath->query('//table[@class="s-dasar"]//td[@class="k"]')->item(0)->textContent))->toBe('Dasar')
        // close under the Nomor, two empty rows before the intro
        ->and((string) preg_replace('/\s+/', ' ', $html))->toContain('<div class="s-jarak-dasar"></div> <table class="s-dasar">')
        ->and((string) preg_replace('/\s+/', ' ', $html))->toContain('</table> <div class="s-baris"></div> <div class="s-baris"></div> <div class="s-lebar">Kepala SMA Negeri 3 Semarang')
        ->and($html)->toContain('.kop-siswa img { height: 75pt; }')
        ->and($html)->toContain('.kop-siswa .kop-kiri { width: 70pt; padding-top: 3pt; }')
        ->and($html)->toContain('.s-dasar .k { width: 103pt; }');

    // The longest kop line still fits between the two emblems at 75 pt (no wrapped address line).
    $metrics = (new Dompdf)->getFontMetrics();
    $longest = max(array_map(
        fn (string $line): float => $metrics->getTextWidth($line, $metrics->getFont('Helvetica', 'normal'), 8.2),
        LetterTemplate::current()->kopDetailLines(),
    ));
    expect($longest)->toBeLessThanOrEqual(473 - 70 - 56.5);

    // The Dasar text of the template takes the school year code.
    $tpl = LetterTemplate::draft(['siswa_dasar' => 'Program {sekolah} {tahun_ajaran}']);
    expect($tpl->siswaDasar('2026/2027'))->toBe('Program SMA Negeri 3 Semarang 2026/2027');

    // With the invitation: still one "Dasar" label, beside it a numbered list of the two texts (D-157).
    $numbered = m1aLayoutHtml('surat-tugas-siswa', m1aLayoutContent($this, ['Citra Ayu Lestari']), '400.3.8/314/MRD/X/2026');
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$numbered);
    $xpath = new DOMXPath($dom);

    expect($xpath->query('//table[@class="s-dasar"]//td[@class="k"]')->length)->toBe(1)
        ->and($xpath->query('//table[@class="s-daftar"]//tr')->length)->toBe(2)
        ->and(trim($xpath->query('//table[@class="s-daftar"]//td[@class="n"]')->item(1)->textContent))->toBe('2.')
        ->and($numbered)->toContain('.s-daftar .n { width: 16pt; }');
});

it('lays the guru letter out like the school Word file, "Kepada" stacked with its colons', function (): void {
    $html = m1aLayoutHtml('surat-perintah-tugas-guru', m1aLayoutContent($this), '400.3.8.6/1681/2026');
    $flat = (string) preg_replace('/\s+/', ' ', $html);

    expect($html)->toContain('<div class="g-judul">SURAT PERINTAH TUGAS</div>')
        ->toContain('<div class="g-judul">Nomor 400.3.8.6/1681/2026</div>')
        ->toContain('Peraturan Daerah Provinsi Jawa Tengah Nomor 11 Tahun 2025')
        ->toContain('<td class="n">4.</td><td class="rata">Surat dari Panitia NSIF 2026 Nomor 012/NSIF/IX/2026 Tanggal 15 September 2026 Hal Undangan</td>')
        ->toContain('MEMERINTAHKAN:')
        ->toContain('kurung-kurawal.svg')
        ->toContain('Melaporkan hasil pelaksanaan tugas kepada pejabat pemberi tugas.')
        ->toContain('Perintah ini dilaksanakan dengan penuh tanggung jawab.')
        ->toContain('Lampiran Surat Tugas Kepala SMA Negeri 3 Semarang')
        ->toContain('DAFTAR PENDAMPING LOMBA NATIONAL SCIENCE AND INVENTION FAIR (NSIF)')
        ->toContain('TAHUN 2026')
        ->toContain(': Hendra Kusuma, S.Pd., M.Si.')
        ->toContain(': 198001012005011001')
        ->toContain('Kepala SMA Negeri 3 Semarang')
        ->toContain('@page { margin: 15.3pt 24.5pt 15.3pt 41.05pt; }')
        // the old one-line form is gone
        ->not->toContain('Nama/Pangkat/Gol/NIP/Jabatan');

    // "Kepada": four rows, each label with its own colon, "Terlampir" on the second.
    expect($flat)->toContain('<td class="k">Kepada</td> <td class="s">:</td> <td class="n">1.</td> <td class="label">Nama</td> <td class="titik">:</td>')
        ->toContain('<td class="label">Pangkat/Gol</td> <td class="titik">:</td> <td class="lampir">Terlampir</td>')
        ->toContain('<td class="label">NIP</td> <td class="titik">:</td>')
        ->toContain('<td class="label">Jabatan</td> <td class="titik">:</td>');
});

// Real PDFs ------------------------------------------------------------------------------------------

it('renders the student letter on one page for a team of three and for one student', function (array $names): void {
    $document = m1aLayoutPdf('surat-tugas-siswa', m1aLayoutContent($this, $names), '400.3.8/313/MRD/X/2026');

    expect($document->bytes)->toStartWith('%PDF-')
        ->and($document->pageCount)->toBe(1);
})->with([
    'team of 3' => [['Raka Pradipta', 'Bima Satria Wicaksono', 'Yoga Pratama']],
    'one student' => [['Hana Safira']],
]);

it('renders the guru letter on two pages: the letter and its Lampiran', function (): void {
    $document = m1aLayoutPdf('surat-perintah-tugas-guru', m1aLayoutContent($this), '400.3.8.6/1681/2026');

    expect($document->bytes)->toStartWith('%PDF-')
        ->and($document->pageCount)->toBe(2);
});

it('ships the letterhead emblems and the brace inside the dompdf chroot', function (): void {
    foreach (['logo-pemprov.png' => IMAGETYPE_PNG, 'logo-sekolah.jpg' => IMAGETYPE_JPEG] as $file => $type) {
        $info = getimagesize(resource_path('pdf/assets/'.$file));

        expect($info)->not->toBeFalse()
            ->and($info[2])->toBe($type);
    }

    // The Pemprov emblem has no alpha channel (dompdf embeds it without a per-pixel pass).
    $png = (string) file_get_contents(resource_path('pdf/assets/logo-pemprov.png'));
    expect(ord($png[25]))->toBe(2)
        ->and(file_get_contents(resource_path('pdf/assets/kurung-kurawal.svg')))->toContain('<path')->not->toContain('<script');
});

// QA8: explore round after PR 17 (2026-10-08) -------------------------------------------------------

it('keeps the end of each letter with its signature, so a long lomba never leaves the signature alone on a page (QA8)', function (): void {
    $content = m1aLayoutContent($this);
    $guru = m1aLayoutHtml('surat-perintah-tugas-guru', $content, '400.3.8.6/1681/2026');
    $siswa = m1aLayoutHtml('surat-tugas-siswa', $content, '400.3.8/313/MRD/X/2026');

    // A realistic long lomba name, penyelenggara and place pushed only the (unbreakable) signature block of the guru
    // letter onto page 2. "Untuk" (guru) and the closing sentence (siswa) now travel with the signature: one `.utuh`
    // box holds both (checked in the DOM, so a box that closes before the signature fails).
    $boxes = static function (string $html, string $signature): array {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $nodes = (new DOMXPath($dom))->query('//div[@class="utuh"][.//*[contains(concat(" ", normalize-space(@class), " "), " '.$signature.' ")]]');

        return array_map(static fn (DOMElement $box): string => preg_replace('/\s+/', ' ', $box->textContent), iterator_to_array($nodes));
    };
    $guruBoxes = $boxes($guru, 'g-ttd');
    $siswaBoxes = $boxes($siswa, 's-ttd');

    expect($guruBoxes)->toHaveCount(1)
        ->and($guruBoxes[0])->toContain('Untuk')
        ->and($siswaBoxes)->toHaveCount(1)
        ->and($siswaBoxes[0])->toContain(trim(preg_replace('/\s+/', ' ', LetterTemplate::current()->siswaClosing())));
});

// SV6, D-183: the signature + stamp image about 30 % larger, the lines around it where they were ------------------

/**
 * Lays one letter out with dompdf (the renderer's own options and a sandbox holding the 640 × 320 sample signature)
 * and returns, per signature block in document order, the boxes in pt as [x, y, w, h]: the block, the reserved image
 * box (`.gambar`), the image and the signer's name under it.
 *
 * @return list<array{block: list<float>, gambar: list<float>, img: list<float>, nama: list<float>}>
 */
function sv6SignatureBoxes(string $view, LetterContent $content, string $number): array
{
    View::addNamespace(PdfRenderer::VIEW_NAMESPACE, resource_path('pdf'));
    $sandbox = TestDisks::directory('pdf-ttd');
    file_put_contents($sandbox.'/ttd.png', SignatureSeeder::generatePng());

    $html = view('pdf::'.$view, [
        'content' => $content,
        'number' => $number,
        'verification' => ['code' => str_repeat('a', 32), 'url' => 'https://contoh.test/verifikasi-surat/'.str_repeat('a', 32)],
        'pdfImages' => ['ttd.png' => $sandbox.'/ttd.png'],
    ])->render();

    $box = static function (Frame $frame): array {
        $border = $frame->get_border_box();

        return [(float) $border['x'], (float) $border['y'], (float) $border['w'], (float) $border['h']];
    };
    $blocks = [];

    $dompdf = new Dompdf(app(PdfRenderer::class)->options($sandbox));
    $dompdf->setBasePath($sandbox);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    // dompdf drops each page's frames once it is drawn, so the boxes are read while the image is drawn (its page is
    // laid out completely by then, the lines under it included).
    $dompdf->setCallbacks([['event' => 'end_frame', 'f' => static function (Frame $frame) use ($box, &$blocks): void {
        $node = $frame->get_node();

        if (! $node instanceof DOMElement || $node->tagName !== 'img' || $node->getAttribute('alt') !== 'Tanda tangan dan stempel') {
            return;
        }

        $gambar = $frame->get_parent();
        $block = $gambar->get_parent();
        $nama = null;

        foreach ($block->get_children() as $child) {
            if ($child->get_node() instanceof DOMElement && $child->get_node()->getAttribute('class') === 'nama') {
                $nama = $child;
            }
        }

        expect($gambar->get_node()->getAttribute('class'))->toBe('gambar')
            ->and($nama)->not->toBeNull();

        $blocks[] = ['block' => $box($block), 'gambar' => $box($gambar), 'img' => $box($frame), 'nama' => $box($nama)];
    }]]);
    $dompdf->render();

    return $blocks;
}

it('makes the signature + stamp image about 30 % larger and keeps the reserved box, so no line moves (SV6, D-183)', function (): void {
    $siswa = m1aLayoutHtml('surat-tugas-siswa', m1aLayoutContent($this), '400.3.8/313/MRD/X/2026');
    $guru = m1aLayoutHtml('surat-perintah-tugas-guru', m1aLayoutContent($this), '400.3.8.6/1681/2026');

    // The boxes keep the heights of the school files; only the image grows and is pulled up by half its growth.
    expect($siswa)->toContain('.s-ttd .gambar { height: 60.95pt; }')
        ->toContain('.s-ttd .gambar img { height: 79.25pt; position: relative; top: -9.15pt; }')
        ->not->toContain('.s-ttd .gambar img { height: 60.95pt; }')
        ->and($guru)->toContain('.g-ttd .gambar { height: 58pt; }')
        ->toContain('.g-ttd .gambar img { height: 75.4pt; position: relative; top: -8.7pt; }')
        ->not->toContain('.g-ttd .gambar img { height: 58pt; }')
        // the Lampiran block is a .g-ttd too, so its image follows the same rule
        ->toContain('class="g-ttd g-ttd-lampiran utuh"');
});

it('lays the larger signature + stamp out over the lines around it in a real PDF, the box and the lines unmoved (SV6, D-183)', function (string $view, string $number, float $box, float $image, int $blocks): void {
    $found = sv6SignatureBoxes($view, m1aLayoutContent($this), $number);

    expect($found)->toHaveCount($blocks);

    foreach ($found as ['block' => $block, 'gambar' => $gambar, 'img' => $img, 'nama' => $nama]) {
        [$blockX, , $blockW] = $block;
        [, $gambarY, , $gambarH] = $gambar;
        [$imgX, $imgY, $imgW, $imgH] = $img;
        $grow = ($image - $box) / 2;

        // the reserved box and the name under it stay where they were
        expect($gambarH)->toEqualWithDelta($box, 0.01)
            ->and($nama[1])->toEqualWithDelta($gambarY + $box, 0.01)
            // the image: 30 % taller, 2:1 like the sample, half of the growth above the box and half below it
            ->and($imgH)->toEqualWithDelta($image, 0.01)
            ->and($imgH / $box)->toEqualWithDelta(1.3, 0.005)
            ->and($imgW)->toEqualWithDelta($image * 2, 0.05)
            ->and($imgY)->toEqualWithDelta($gambarY - $grow, 0.01)
            ->and($imgY + $imgH)->toEqualWithDelta($gambarY + $box + $grow, 0.01)
            // and it still fits the width of its block
            ->and($imgX)->toBeGreaterThanOrEqual($blockX - 0.01)
            ->and($imgX + $imgW)->toBeLessThanOrEqual($blockX + $blockW + 0.01);
    }
})->with([
    'siswa' => ['surat-tugas-siswa', '400.3.8/313/MRD/X/2026', 60.95, 79.25, 1],
    'guru and its Lampiran' => ['surat-perintah-tugas-guru', '400.3.8.6/1681/2026', 58.0, 75.4, 2],
]);

it('lays the dashed placeholder of the TU preview over the lines around its slot the same way (SV6, D-183)', function (string $ttd): void {
    $html = view('livewire.tata-usaha.partials.ttd', ['ttd' => $ttd, 'content' => m1aLayoutContent($this)])->render();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($dom);
    $placeholder = $xpath->query('//span[not(*)][normalize-space(.)="Tanda tangan + stempel"]');

    // The 52 px slot stays in the flow (the lines keep their places); the dashed box lies over it, 8 px higher and
    // 8 px lower (68 px, about 30 % more), and lets clicks through to the text under it.
    expect($placeholder->length)->toBe(1);

    $box = explode(' ', $placeholder->item(0)->getAttribute('class'));
    $slot = explode(' ', $placeholder->item(0)->parentNode->getAttribute('class'));

    expect($slot)->toContain('relative', 'h-[52px]', 'w-full')
        ->and($box)->toContain('absolute', 'inset-x-0', '-top-2', '-bottom-2', 'border-dashed', 'pointer-events-none')
        ->not->toContain('h-[52px]')
        ->and($html)->not->toContain('style=');
})->with(['siswa', 'guru']);
