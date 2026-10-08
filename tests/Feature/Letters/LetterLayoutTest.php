<?php

declare(strict_types=1);

use App\Actions\Letters\LetterContent;
use App\Actions\Letters\LetterDraft;
use App\Domain\Letters\PdfDocument;
use App\Domain\Letters\PdfQrCode;
use App\Domain\Letters\PdfRenderer;
use App\Enums\RegistrationStatus;
use App\Models\Letter;
use App\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Database\Seeders\SignatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

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
        ->toContain('Surat dari Panitia NSIF 2026')
        ->toContain('012/NSIF/IX/2026')
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
        // Helvetica (= Arial metrics), sheet margins
        ->toContain('font-family: Helvetica, Arial, sans-serif')
        ->toContain('@page { margin: 56.7pt 39.7pt 56.7pt 39.7pt; }');
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
    // letter onto page 2. "Untuk" (guru) and the closing sentence (siswa) now travel with the signature.
    expect($guru)->toMatch('/<div class="utuh">\s*<table class="g-blok">\s*<tr>\s*<td class="k-untuk">Untuk<\/td>(?:(?!<div class="halaman-baru">).)*class="g-ttd utuh"/s')
        ->and($siswa)->toMatch('/<div class="utuh">\s*<div>[^<]+<\/div>\s*<div class="s-baris"><\/div>(?:(?!<\/div>\s*@).)*class="s-ttd utuh"/s');
});
