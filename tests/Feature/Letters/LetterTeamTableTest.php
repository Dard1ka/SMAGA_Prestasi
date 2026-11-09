<?php

declare(strict_types=1);

use App\Actions\Letters\LetterContent;
use App\Actions\Letters\LetterDraft;
use App\Domain\Letters\PdfRenderer;
use App\Enums\RegistrationStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\SignatureSeeder;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\Frame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;
use Tests\Support\TestDisks;

/*
 * SV8, D-196 (lead, 2026-11-09: "Cek juga tabel nama di suratnya tetap rapi kalau anggotanya banyak"): the member table
 * NO · NAMA MURID · NIS · KELAS of the student letter with up to 30 people, laid out by dompdf and read back per page:
 * every member once and inside the margins (a row never splits), the header row on every page with members, a long
 * name wrapping inside its cell, the last two rows on the signature's page. And the same table in the TU preview.
 * Fictional names only (DESIGN-SPEC-v2 §5 parts).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    $this->mentor = F::mentor();
});

/** A4 portrait, the student letter's 56.7 pt top and bottom margins (layout.blade.php). */
const D196_TOP = 56.7;
const D196_BOTTOM = 841.89 - 56.7;

/** @return list<string> $count fictional names; with $long every fifth one is too long for one line of NAMA MURID */
function d196Names(int $count, bool $long = false): array
{
    $first = ['Raka', 'Bima', 'Yoga', 'Hana', 'Aurelia', 'Rizky', 'Citra', 'Salsabila', 'Dimas', 'Keisha', 'Nadia'];
    $last = ['Pradipta', 'Satria Wicaksono', 'Pratama', 'Safira', 'Maharani', 'Maulana', 'Ayu Lestari', 'Kirana', 'Arya Nugroho', 'Larasati'];
    $names = [];

    for ($i = 0; $i < $count; $i++) {
        $name = $first[$i % count($first)].' '.$last[($i * 7) % count($last)];
        $names[] = $long && $i % 5 === 3 ? 'Raden Ajeng Kusumawardhanisetyaningrumpuspitasari '.$name.' Adiwijaya Prasetyo' : $name;
    }

    return $names;
}

/** @param list<string> $names */
function d196Content(object $t, array $names): LetterContent
{
    $registration = F::registration(RegistrationStatus::Disetujui, $names, $t->mentor);

    return LetterContent::build($registration, new LetterDraft(
        number: '400.3.8/313/MRD/X/2026',
        issuedOn: CarbonImmutable::parse('2026-10-04'),
        basisFrom: 'Panitia NSIF 2026',
        basisNumber: '012/NSIF/IX/2026',
        basisDate: CarbonImmutable::parse('2026-09-15'),
        scheduleDay: 'Sabtu',
        scheduleDate: '7 November 2026 s.d 9 November 2026',
        scheduleTime: '07.00 WIB s.d Selesai',
        place: 'Gedung Serbaguna Universitas Contoh',
    ));
}

/**
 * Lays the student letter out with dompdf (the renderer's own options, the sample signature in a sandbox) and reads it
 * back while each page is drawn: the member table's rows (header or not, the NO cell, page, top and bottom, the cells'
 * widths), the pages of "untuk mengikuti …" and of the signature block, and the page count. Boxes in pt.
 *
 * @return array{pages: int, rows: list<array{header: bool, no: string, page: int, top: float, bottom: float, cells: list<float>}>, untuk: list<int>, ttd: list<int>}
 */
function d196Layout(LetterContent $content): array
{
    View::addNamespace(PdfRenderer::VIEW_NAMESPACE, resource_path('pdf'));
    $sandbox = TestDisks::directory('pdf-tim');
    file_put_contents($sandbox.'/ttd.png', SignatureSeeder::generatePng());

    $html = view('pdf::surat-tugas-siswa', [
        'content' => $content,
        'number' => '400.3.8/313/MRD/X/2026',
        'verification' => ['code' => str_repeat('a', 32), 'url' => 'https://contoh.test/verifikasi-surat/'.str_repeat('a', 32)],
        'pdfImages' => ['ttd.png' => $sandbox.'/ttd.png'],
    ])->render();

    $layout = ['pages' => 0, 'rows' => [], 'untuk' => [], 'ttd' => []];
    $element = static fn (?Frame $frame): ?DOMElement => $frame?->get_node() instanceof DOMElement ? $frame->get_node() : null;

    $dompdf = new Dompdf(app(PdfRenderer::class)->options($sandbox));
    $dompdf->setBasePath($sandbox);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    // dompdf drops a page's frames once it is drawn (and draws a repeated header from a copy), so every box is read
    // while its frame is drawn, with the page the canvas is on.
    $dompdf->setCallbacks([['event' => 'end_frame', 'f' => static function (Frame $frame, Canvas $canvas) use ($element, &$layout): void {
        $node = $element($frame);

        if ($node === null) {
            return;
        }

        $page = $canvas->get_page_number();
        $class = ' '.$node->getAttribute('class').' ';

        if ($node->tagName === 'tr' && str_contains(' '.$element($frame->get_parent()?->get_parent())?->getAttribute('class').' ', ' s-murid ')) {
            $box = $frame->get_border_box();
            $cells = [];
            $texts = [];

            foreach ($frame->get_children() as $cell) {
                if ($element($cell) !== null) {
                    $cells[] = round((float) $cell->get_border_box()['w'], 2);
                    $texts[] = trim((string) $element($cell)->textContent);
                }
            }

            $layout['rows'][] = [
                'header' => $element($frame->get_parent())?->tagName === 'thead',
                'no' => $texts[0] ?? '',
                'page' => $page,
                'top' => (float) $box['y'],
                'bottom' => (float) $box['y'] + (float) $box['h'],
                'cells' => $cells,
            ];
        } elseif ($node->tagName === 'div' && str_starts_with(trim($node->textContent), 'untuk mengikuti')) {
            $layout['untuk'][] = $page;
        } elseif (str_contains($class, ' s-ttd ')) {
            $layout['ttd'][] = $page;
        }
    }]]);
    $dompdf->render();
    $layout['pages'] = (int) $dompdf->getCanvas()->get_page_count();

    return $layout;
}

/** The checks every member table must pass, whatever its length. */
function d196AssertTidy(array $layout, int $count): void
{
    $members = array_values(array_filter($layout['rows'], fn (array $r): bool => ! $r['header']));
    $headers = array_values(array_filter($layout['rows'], fn (array $r): bool => $r['header']));
    $last = $layout['pages'];

    // Every member once, in order: a row split over two pages would be drawn twice.
    expect(array_column($members, 'no'))->toBe(array_map('strval', range(1, $count)));

    foreach ($members as $row) {
        // Inside the page margins, and in the columns of the header.
        expect($row['top'])->toBeGreaterThanOrEqual(D196_TOP - 0.3)
            ->and($row['bottom'])->toBeLessThanOrEqual(D196_BOTTOM + 0.3)
            ->and($row['cells'])->toHaveCount(4);

        foreach ($row['cells'] as $i => $width) {
            expect($width)->toEqualWithDelta($headers[0]['cells'][$i], 0.05);
        }
    }

    // The header row repeats: once on every page with members, right above the first of them, never alone.
    $memberPages = array_values(array_unique(array_column($members, 'page')));
    expect(array_column($headers, 'page'))->toBe($memberPages)
        ->and(array_column($headers, 'no'))->each->toBe('NO');

    foreach ($headers as $header) {
        $first = collect($members)->firstWhere('page', $header['page']);
        expect($first['top'])->toEqualWithDelta($header['bottom'], 0.6);
    }

    // The signature never stands alone: its page also has "untuk mengikuti …", the header and the last two rows.
    expect($layout['ttd'])->toBe([$last])
        ->and($layout['untuk'])->toBe([$last])
        ->and($members[$count - 1]['page'])->toBe($last)
        ->and($members[$count - 2]['page'])->toBe($last)
        ->and(end($memberPages))->toBe($last);
}

it('keeps the member table tidy for teams of 3, 10, 20 and 30 (SV8, D-196)', function (int $count, int $pages): void {
    $layout = d196Layout(d196Content($this, d196Names($count)));

    expect($layout['pages'])->toBe($pages);
    d196AssertTidy($layout, $count);

    // One line per name here: every member row keeps the sheet's 20.1 pt (plus the 0.5 pt border).
    foreach (array_filter($layout['rows'], fn (array $r): bool => ! $r['header']) as $row) {
        expect($row['bottom'] - $row['top'])->toBeLessThan(21.0);
    }
})->with([
    'team of 3: one page' => [3, 1],
    'team of 10: the last two rows go over with the signature' => [10, 2],
    'team of 20' => [20, 2],
    'team of 30: header repeated on page 2' => [30, 2],
]);

it('wraps a long name inside NAMA MURID: the columns keep their widths, the row grows (SV8, D-196)', function (int $count): void {
    $layout = d196Layout(d196Content($this, d196Names($count, long: true)));
    $members = array_values(array_filter($layout['rows'], fn (array $r): bool => ! $r['header']));
    $heights = array_map(fn (array $r): float => $r['bottom'] - $r['top'], $members);

    expect($layout['pages'])->toBeLessThanOrEqual(3)
        // A wrapped name takes two or three 13 pt lines; the other rows stay one line high.
        ->and(max($heights))->toBeGreaterThan(26.0)
        ->and(min($heights))->toBeLessThan(21.0);

    d196AssertTidy($layout, $count);
})->with([10, 30]);

it('shows the same table in the TU preview: fixed columns, names wrapping in their cell, every member once (SV8, D-196)', function (): void {
    $names = d196Names(30, long: true);
    $html = view('livewire.tata-usaha.partials.pratinjau-siswa', [
        'content' => d196Content($this, $names),
        'number' => '400.3.8/313/MRD/X/2026',
    ])->render();

    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($dom);
    $table = $xpath->query('//table[@data-tabel="murid"]')->item(0);
    $classes = static fn (DOMElement $e): array => explode(' ', $e->getAttribute('class'));

    expect($table)->not->toBeNull()
        ->and($classes($table))->toContain('table-fixed', 'w-full', 'border-collapse');

    $heads = $xpath->query('.//thead/tr/th', $table);
    $rows = $xpath->query('.//tbody/tr', $table);

    expect(array_map(fn (DOMElement $th): string => trim($th->textContent), iterator_to_array($heads)))->toBe(['NO', 'NAMA MURID', 'NIS', 'KELAS'])
        ->and($classes($heads->item(0)))->toContain('w-7')
        ->and($classes($heads->item(2)))->toContain('w-[70px]')
        ->and($classes($heads->item(3)))->toContain('w-[72px]')
        ->and($rows->length)->toBe(30);

    foreach ($rows as $i => $row) {
        $cells = $xpath->query('./td', $row);

        expect($classes($row))->toContain('break-inside-avoid')
            ->and(trim($cells->item(0)->textContent))->toBe((string) ($i + 1))
            ->and($classes($cells->item(1)))->toContain('break-words', 'align-middle', 'leading-[12.2px]')
            ->and($classes($cells->item(2)))->toContain('whitespace-nowrap');
    }

    expect($html)->toContain(e(mb_strtoupper($names[3])));
});
