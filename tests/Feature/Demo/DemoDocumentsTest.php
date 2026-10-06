<?php

declare(strict_types=1);

use App\Domain\Files\PdfScanner;
use App\Domain\Files\StoreUpload;
use App\Domain\Letters\PdfDocument;
use App\Enums\Bidang;
use App\Enums\FilePurpose;
use App\Enums\Level;
use App\Enums\OrganizerType;
use App\Enums\ResultType;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\Demo\DemoAward;
use Database\Seeders\Support\Demo\DemoCertificate;
use Database\Seeders\Support\Demo\DemoDocumentKind;
use Database\Seeders\Support\Demo\DemoEvent;
use Database\Seeders\Support\Demo\DemoGuideline;
use Database\Seeders\Support\Demo\DemoPdfRenderer;
use Database\Seeders\Support\Demo\DemoPeople;
use Database\Seeders\Support\Demo\DemoSamples;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

/*
| M3-D · realistic sample documents of the local demo data (database/seeders/Support/Demo, templates in
| database/seeders/demo). One render per kind and look (DemoSamples, no database needed to build them):
| - each PDF is accepted by the app's own upload path (StoreUpload → UploadRules + PdfScanner) and is small;
| - each page carries the CONTOH footer line; certificate numbers and the stamp say CONTOH;
| - only fictional people are named; signers are invented committee roles;
| - the same document always renders to the same bytes (how a later refresh sees a file is up to date).
| The demo seeder itself keeps the tiny placeholders in tests: see RefreshDemoFilesTest.
*/

uses(RefreshDatabase::class);

/** One render per sample for the whole file (the samples are fixed, so tests can share them). */
function m3dSamplePdf(string $name): PdfDocument
{
    static $rendered = [];

    return $rendered[$name] ??= app(DemoPdfRenderer::class)->render(DemoSamples::documents()[$name]);
}

/** The page content of a dompdf PDF: every stream, inflated. */
function m3dPdfText(string $bytes): string
{
    // No optional "\r" here: it would eat a compressed stream's last byte when that byte happens to be 0x0D.
    preg_match_all('~stream\n(.*?)\nendstream~s', $bytes, $matches);

    return implode("\n", array_map(fn (string $stream): string => (string) @gzuncompress($stream), $matches[1]));
}

it('renders every sample kind as a small PDF that the upload path accepts, with the CONTOH line on every page', function (): void {
    // One test for all samples: a render takes a few hundred milliseconds, booting the app per sample would double it.
    foreach (DemoSamples::documents() as $name => $document) {
        foreach ($document->images as $file => $bytes) {
            if (str_ends_with($file, '.svg')) {
                // A broken SVG does not fail the render: dompdf draws a grey box with a cross instead.
                expect(@simplexml_load_string($bytes))->not->toBeFalse("{$name}: {$file} is not valid XML")
                    ->and($bytes)->not->toContain('<text', '<image', 'href');
            }
        }

        $pdf = m3dSamplePdf($name);

        expect(str_starts_with($pdf->bytes, '%PDF-'))->toBeTrue($name)
            ->and(PdfScanner::hasActiveContent($pdf->bytes))->toBeFalse("{$name}: refused by the upload scanner")
            ->and($pdf->size())->toBeLessThan(100 * 1024, $name)
            ->and(substr_count(m3dPdfText($pdf->bytes), 'bukan dokumen resmi'))->toBeGreaterThanOrEqual($pdf->pageCount, "{$name}: a page without the CONTOH line");

        // The whole upload path a student's or a staff file goes through.
        $purpose = in_array($document->kind, [DemoDocumentKind::Juknis, DemoDocumentKind::Brosur, DemoDocumentKind::Poster], true)
            ? FilePurpose::Juknis
            : FilePurpose::ReportEvidence;
        $stored = app(StoreUpload::class)->store(UploadedFile::fake()->createWithContent($name.'.pdf', $pdf->bytes), $purpose, null);

        expect($stored->mime)->toBe('application/pdf')
            ->and($stored->sha256)->toBe($pdf->sha256());
    }

    expect(count(DemoSamples::documents()))->toBeGreaterThanOrEqual(19);
});

it('has a sample for every kind a demo file can be, except the letters (they need a registration)', function (): void {
    $kinds = array_unique(array_map(fn ($document): string => $document->kind->value, DemoSamples::documents()));
    sort($kinds);

    $expected = array_map(fn (DemoDocumentKind $kind): string => $kind->value, array_filter(
        DemoDocumentKind::cases(),
        fn (DemoDocumentKind $kind): bool => ! in_array($kind, [DemoDocumentKind::SuratSiswa, DemoDocumentKind::SuratGuru], true),
    ));
    sort($expected);

    expect($kinds)->toBe($expected);
});

it('lays out page sizes and page counts as the kinds promise', function (): void {
    expect(m3dSamplePdf('sertifikat-emas')->pageCount)->toBe(1)
        ->and(m3dSamplePdf('sertifikat-emas')->bytes)->toContain('/MediaBox [0.000 0.000 841.890 595.280]') // landscape A4
        ->and(m3dSamplePdf('piagam-hijau')->pageCount)->toBe(1)
        ->and(m3dSamplePdf('piagam-hijau')->bytes)->toContain('/MediaBox [0.000 0.000 595.280 841.890]') // portrait A4
        ->and(m3dSamplePdf('brosur-riset')->pageCount)->toBe(1)
        ->and(m3dSamplePdf('poster-film')->pageCount)->toBe(1)
        ->and(m3dSamplePdf('juknis-sains')->pageCount)->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(4)
        ->and(m3dSamplePdf('laporan-penelitian')->pageCount)->toBeGreaterThanOrEqual(10);
});

it('prints the award on a certificate: recipient, lomba, result, a number with CONTOH and two invented signers', function (): void {
    $document = DemoSamples::documents()['sertifikat-nasional'];
    $text = m3dPdfText(m3dSamplePdf('sertifikat-nasional')->bytes);

    expect($text)->toContain('Raka Pradipta', 'JUARA I', 'Bidang Informatika', 'OSN Provinsi Jawa Tengah 2024', 'Semarang, 18 September 2024')
        ->and($document->data['number'])->toContain('/CONTOH/OSN/IX/2024')
        ->and($text)->toContain($document->data['number'], 'Ketua Panitia', 'Ketua Dewan Juri')
        ->and($document->data['signers'])->toHaveCount(2)
        ->and($document->data['signers'][0]['name'])->not->toBe($document->data['signers'][1]['name']);

    // Signers are never people of the demo set and never carry an office of a real institution.
    foreach (DemoSamples::documents() as $sample) {
        foreach ($sample->data['signers'] ?? [] as $signer) {
            expect(DemoPeople::isFictional($signer['name']))->toBeFalse()
                ->and($signer['role'])->not->toMatch('/kepala|rektor|dekan|menteri|gubernur|wali ?kota|bupati|direktur/i');
        }
    }
});

it('writes an English certificate for an international event', function (): void {
    $text = m3dPdfText(m3dSamplePdf('sertifikat-internasional')->bytes);

    expect($text)->toContain('CERTIFICATE', 'OF ACHIEVEMENT', 'GOLD MEDALIST', 'This certificate is proudly presented to', 'Raka Pradipta', '22 November 2025')
        ->and($text)->not->toContain('Diberikan kepada');
});

it('gives the piagam a formal table and the sports wording', function (): void {
    $text = m3dPdfText(m3dSamplePdf('piagam-hijau')->bytes);

    expect($text)->toContain('PIAGAM PENGHARGAAN', 'Bima Satria Wicaksono', 'Cabang olahraga', 'Atletik', 'PERAIH MEDALI PERAK', 'Popda Kota Semarang 2026', 'Koordinator Pertandingan', 'sportivitas');
});

it('picks a different look per kind of lomba', function (): void {
    $award = fn (?OrganizerType $type, ?Bidang $bidang, ?Level $level, bool $piagam = false): DemoAward => new DemoAward(
        recipient: 'Nadia Putri Anindya', competition: 'Lomba Contoh 2026', organizer: 'Panitia', result: ResultType::Juara1,
        organizerType: $type, bidang: $bidang, level: $level, piagam: $piagam,
    );

    expect(DemoCertificate::theme($award(OrganizerType::PemerintahPusat, Bidang::Sains, Level::Provinsi)))->toBe('nasional') // OSN
        ->and(DemoCertificate::theme($award(OrganizerType::PemerintahDaerah, Bidang::Riset, Level::Kota)))->toBe('inovasi') // KRENOVA
        ->and(DemoCertificate::theme($award(OrganizerType::Ptn, Bidang::Sains, Level::Nasional)))->toBe('kampus') // university olympiad
        ->and(DemoCertificate::theme($award(OrganizerType::Lainnya, Bidang::SeniBudaya, Level::Kota)))->toBe('festival')
        ->and(DemoCertificate::theme($award(OrganizerType::YayasanLembaga, Bidang::Riset, Level::Internasional)))->toBe('internasional')
        ->and(DemoCertificate::theme($award(OrganizerType::PerusahaanSwasta, Bidang::Sains, Level::Nasional)))->toBe('emas')
        ->and(DemoCertificate::theme($award(null, null, null)))->toBe('emas')
        ->and(DemoCertificate::document($award(OrganizerType::FederasiOlahraga, Bidang::Olahraga, Level::Provinsi))->kind)->toBe(DemoDocumentKind::Piagam)
        ->and(DemoCertificate::document($award(OrganizerType::PemerintahDaerah, Bidang::Sains, Level::Kota, piagam: true))->kind)->toBe(DemoDocumentKind::Piagam)
        ->and(DemoCertificate::document($award(OrganizerType::PemerintahDaerah, Bidang::Sains, Level::Kota))->orientation)->toBe('landscape');

    // Every look is in the samples, so each one is rendered by the first test.
    $themes = array_map(fn (DemoAward $a): string => $a->isPiagam() ? 'piagam-'.DemoCertificate::piagamVariant($a) : DemoCertificate::theme($a), DemoSamples::awards());

    expect(array_values(array_unique($themes)))->toEqualCanonicalizing([...DemoCertificate::THEMES, 'piagam-merah', 'piagam-hijau', 'piagam-biru']);
});

it('writes a research report with every part a school report has, and page numbers in the table of contents', function (): void {
    $document = DemoSamples::documents()['laporan-penelitian'];
    $pdf = m3dSamplePdf('laporan-penelitian');
    $text = m3dPdfText($pdf->bytes);

    expect($text)->toContain(
        'BIOPLASTIK DARI LIMBAH KULIT PISANG', 'LAPORAN PENELITIAN', 'Keisha Larasati', 'NIS 225226166', 'Retno Wulandari, S.Pd.', 'SMA NEGERI 3 SEMARANG',
        'LEMBAR PENGESAHAN', 'ABSTRAK', 'Kata kunci:', 'KATA PENGANTAR', 'DAFTAR ISI',
        'PENDAHULUAN', '1.1 Latar Belakang', '1.2 Rumusan Masalah', '1.3 Tujuan Penelitian', '1.4 Manfaat Penelitian',
        'TINJAUAN PUSTAKA', 'METODE PENELITIAN', 'HASIL DAN PEMBAHASAN', 'Tabel 4.2', 'PENUTUP', '5.1 Simpulan', 'DAFTAR PUSTAKA',
    );

    // Five chapters, each on its own page after the front matter; the references are invented and say so.
    expect(array_column($document->data['chapters'], 'number'))->toBe(['BAB I', 'BAB II', 'BAB III', 'BAB IV', 'BAB V'])
        ->and(implode(' ', $document->data['references']))->toContain('Contoh')->not->toContain('doi', 'http')
        ->and($text)->toContain('daftar pustaka ini contoh', 'data contoh')
        // The lembar pengesahan names the fictional Kepala Sekolah of the demo set, whatever the settings say.
        ->and($text)->toContain(DemoPeople::PRINCIPAL['name']);

    // Table of contents: front matter in roman numbers, chapters from 1 (second pass of the renderer).
    expect($text)->toMatch('~\(LEMBAR PENGESAHAN\).{0,400}?\(ii\)~s')
        ->and($text)->toMatch('~\(BAB I PENDAHULUAN\).{0,400}?\(1\)~s')
        ->and($text)->toMatch('~\(DAFTAR PUSTAKA\).{0,400}?\(\d+\)~s');
});

it('builds the juknis schedule from the dates of the lomba and prints only fictional contact data', function (): void {
    $event = DemoSamples::events()['juknis-sains'];
    $schedule = DemoGuideline::schedule($event);
    $text = m3dPdfText(m3dSamplePdf('juknis-sains')->bytes);

    expect($schedule)->toHaveCount(4)
        ->and($schedule[0][2])->toBe('8 September s.d. 8 Oktober 2026')
        ->and($schedule[1][2])->toBe('11 Oktober 2026') // technical meeting: after the deadline, before the lomba
        ->and($schedule[2][2])->toBe('14 Oktober 2026')
        ->and($text)->toContain('PETUNJUK TEKNIS', 'OSN Tingkat Kota Semarang 2026', 'LATAR BELAKANG', 'KETENTUAN PESERTA', 'JADWAL KEGIATAN', 'MEKANISME LOMBA', 'KRITERIA PENILAIAN', 'PENGHARGAAN', 'NARAHUBUNG')
        ->and($text)->toContain('panitia@example.test', 'https://example.test/', 'nomor contoh', 'Halaman 1 dari');

    // The technical meeting never falls on or after the lomba, also when the deadline is one day before it.
    $tight = new DemoEvent(
        name: 'Lomba Kilat 2026', organizer: 'Panitia',
        opensOn: CarbonImmutable::parse('2026-10-01'), closesOn: CarbonImmutable::parse('2026-10-09'),
        startsOn: CarbonImmutable::parse('2026-10-10'), finalOn: CarbonImmutable::parse('2026-10-10'),
    );

    expect(DemoGuideline::schedule($tight)[1][2])->toBe('9 Oktober 2026');

    // A brochure never prints the website of the lomba row, only example.test.
    expect(m3dPdfText(m3dSamplePdf('brosur-riset')->bytes))->toContain('https://example.test/international-science-project-olympiad')->not->toContain('ispo.or.id');
});

it('renders the same document to the same bytes, and tells by the file identifier what it was rendered from', function (): void {
    $document = DemoSamples::documents()['piagam-biru'];
    $other = DemoSamples::documents()['piagam-merah'];
    $first = app(DemoPdfRenderer::class)->render($document);
    $second = app(DemoPdfRenderer::class)->render($document); // another renderer: nothing cached in memory

    expect($second->bytes)->toBe($first->bytes)
        ->and($first->bytes)->toBe(m3dSamplePdf('piagam-biru')->bytes)
        ->and(DemoPdfRenderer::hasId($first->bytes, app(DemoPdfRenderer::class)->id($document)))->toBeTrue()
        ->and(DemoPdfRenderer::hasId($first->bytes, app(DemoPdfRenderer::class)->id($other)))->toBeFalse()
        ->and($first->bytes)->toContain('/CreationDate (D:20260930)');
});

it('never renders a sample document in production', function (): void {
    app()['env'] = 'production';

    try {
        expect(fn () => app(DemoPdfRenderer::class)->render(DemoSamples::documents()['umum']))->toThrow(RuntimeException::class, 'production');
    } finally {
        app()['env'] = 'testing';
    }
});

it('does not repeat the level when the name of the lomba already says it', function (): void {
    $body = fn (string $name, Level $level): string => DemoCertificate::document(new DemoAward(
        recipient: 'Nadia Putri Anindya', competition: $name, organizer: 'Panitia', result: ResultType::Juara1, level: $level,
    ))->data['body'];

    expect($body('OPSI 2026', Level::Nasional))->toStartWith('dalam OPSI 2026 tingkat nasional yang diselenggarakan oleh')
        ->and($body('FLS3N Nasional 2025', Level::Nasional))->toStartWith('dalam FLS3N Nasional 2025 yang diselenggarakan oleh')
        ->and($body('OSN Tingkat Kota Semarang 2026', Level::Kota))->toStartWith('dalam OSN Tingkat Kota Semarang 2026 yang diselenggarakan oleh')
        ->and($body('Olimpiade Fisika Jawa Tengah 2026', Level::Provinsi))->toStartWith('dalam Olimpiade Fisika Jawa Tengah 2026 tingkat provinsi yang');
});

it('only knows the people of the demo set as fictional', function (): void {
    expect(DemoPeople::isFictional('Nadia Putri Anindya'))->toBeTrue()
        ->and(DemoPeople::isFictional('Drs. Bambang Hartono, M.Pd.'))->toBeTrue()
        ->and(DemoPeople::isFictional('Seseorang Yang Lain'))->toBeFalse()
        ->and(DemoPeople::isFictional(null))->toBeFalse()
        ->and(DemoPeople::nameOr('Seseorang Yang Lain', DemoPeople::FALLBACK_STUDENT['name']))->toBe('Nadia Putri Anindya')
        ->and(DemoPeople::signers('OSN 2026', ['Ketua Panitia', 'Ketua Dewan Juri']))->toBe(DemoPeople::signers('OSN 2026', ['Ketua Panitia', 'Ketua Dewan Juri']));
});
