<?php

declare(strict_types=1);

use App\Domain\Files\PdfScanner;
use App\Domain\Files\StoreUpload;

/*
| StoreUpload::pdfHasActiveContent() on crafted byte strings (SECURITY §3d). The object-stream payloads are
| compressed, so a "hidden" case is only found when the scanner really inflates and scans that stream; each
| dictionary is also tried with a harmless payload to prove the verdict comes from the payload.
*/

/** Object-stream payloads: "number offset" pairs, then the objects. */
const PDF_SCAN_HOSTILE = '11 0 12 28 << /OpenAction 12 0 R >> << /S /JavaScript /JS (app.alert(1)) >>';
const PDF_SCAN_CLEAN = '11 0 12 18 << /Type /Pages >> << /Type /Page /Parent 11 0 R >>';

/** A small PDF whose object 5 is a stream with the given dictionary and body. */
function pdfScanFile(string $dictionary, string $body, string $keyword = "stream\n", string $end = "\nendstream\nendobj\n"): string
{
    return "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."5 0 obj\n{$dictionary}\n{$keyword}{$body}{$end}"
        ."xref\n0 1\n0000000000 65535 f \ntrailer\n<< /Root 1 0 R /Size 6 >>\nstartxref\n9\n%%EOF\n";
}

dataset('object stream dictionaries', [
    'declared /ObjStm' => ['<< /Type /ObjStm /N 2 /First 11 /Filter /FlateDecode >>'],
    'hex-escaped /ObjStm' => ['<< /Type /#4FbjStm /N 2 /First 11 /Filter /FlateDecode >>'],
    'nested dictionary before the keyword' => ['<< /Type /ObjStm /N 2 /First 11 /Filter /FlateDecode /Info << /K 1 >> >>'],
    'no /Type at all' => ['<< /N 2 /First 11 /Filter /FlateDecode >>'],
    'only /N' => ['<< /N 2 /Filter /FlateDecode >>'],
    'hex-escaped keys' => ['<< /#4E 2 /F#69rst 11 /Filter /FlateDecode >>'],
    'hex-escaped filter name' => ['<< /Type /ObjStm /N 2 /First 11 /Filter /Fl#61teDecode >>'],
    'abbreviated filter name' => ['<< /N 2 /First 11 /Filter /Fl >>'],
    'filter array' => ['<< /Type /ObjStm /N 2 /First 11 /Filter [/FlateDecode] >>'],
    'string that imitates the next object' => ["<< /N 2 /First 11 /Filter /FlateDecode /Note (endobj 9 0 obj << /Length 3 % )\n >>"],
    'comment between dictionary and keyword' => ['<< /N 2 /First 11 /Filter /FlateDecode >> % x'],
]);

it('finds active content inside a compressed object stream', function (string $dictionary): void {
    expect(StoreUpload::pdfHasActiveContent(pdfScanFile($dictionary, gzcompress(PDF_SCAN_HOSTILE))))->toBeTrue()
        ->and(StoreUpload::pdfHasActiveContent(pdfScanFile($dictionary, gzcompress(PDF_SCAN_CLEAN))))->toBeFalse();
})->with('object stream dictionaries');

it('finds active content in an object stream after any end-of-line form of the keyword', function (string $keyword): void {
    $dictionary = '<< /N 2 /First 11 /Filter /FlateDecode >>';

    expect(StoreUpload::pdfHasActiveContent(pdfScanFile($dictionary, gzcompress(PDF_SCAN_HOSTILE), $keyword)))->toBeTrue()
        ->and(StoreUpload::pdfHasActiveContent(pdfScanFile($dictionary, gzcompress(PDF_SCAN_CLEAN), $keyword)))->toBeFalse();
})->with(["stream\n", "stream\r\n", "stream\r", "stream \n", "stream\t\r\n"]);

it('finds active content in an object stream without a filter or without endstream', function (): void {
    expect(StoreUpload::pdfHasActiveContent(pdfScanFile('<< /N 2 /First 11 >>', PDF_SCAN_HOSTILE)))->toBeTrue()
        ->and(StoreUpload::pdfHasActiveContent(pdfScanFile('<< /N 2 /First 11 >>', PDF_SCAN_CLEAN)))->toBeFalse()
        ->and(StoreUpload::pdfHasActiveContent(pdfScanFile('<< /N 2 /First 11 /Filter /FlateDecode >>', gzcompress(PDF_SCAN_HOSTILE), end: '')))->toBeTrue();
});

it('keeps compressed data that ends in a line break intact', function (): void {
    // A line break sits between the data and "endstream"; a data byte that is itself \n or \r must not be trimmed with it.
    for ($i = 0, $clean = ''; ! str_ends_with($clean, "\n") && $i < 600; $i++) {
        $clean = gzcompress(PDF_SCAN_CLEAN.' %'.str_repeat('a', $i));
    }
    for ($i = 0, $hostile = ''; ! str_ends_with($hostile, "\r") && $i < 600; $i++) {
        $hostile = gzcompress(PDF_SCAN_HOSTILE.' %'.str_repeat('a', $i));
    }

    expect(str_ends_with($clean, "\n"))->toBeTrue()
        ->and(str_ends_with($hostile, "\r"))->toBeTrue()
        ->and(StoreUpload::pdfHasActiveContent(pdfScanFile('<< /Type /ObjStm /N 2 /First 11 /Filter /FlateDecode >>', $clean)))->toBeFalse()
        ->and(StoreUpload::pdfHasActiveContent(pdfScanFile('<< /Type /ObjStm /N 2 /First 11 /Filter /FlateDecode >>', $hostile, end: "\r\nendstream\nendobj\n")))->toBeTrue();
});

it('refuses an object stream it cannot fully read, whatever is inside', function (string $dictionary, string $body): void {
    expect(StoreUpload::pdfHasActiveContent(pdfScanFile($dictionary, $body)))->toBeTrue();
})->with([
    'unsupported filter' => ['<< /Type /ObjStm /N 2 /First 11 /Filter /ASCIIHexDecode >>', bin2hex(PDF_SCAN_CLEAN).'>'],
    'unsupported filter, no /Type' => ['<< /N 2 /First 11 /Filter /LZWDecode >>', gzcompress(PDF_SCAN_CLEAN)],
    'abbreviated unsupported filter' => ['<< /N 2 /First 11 /Filter /AHx >>', bin2hex(PDF_SCAN_CLEAN).'>'],
    'filter chain' => ['<< /Type /ObjStm /N 2 /First 11 /Filter [/ASCIIHexDecode /FlateDecode] >>', bin2hex((string) gzcompress(PDF_SCAN_CLEAN)).'>'],
    'compressed twice' => ['<< /Type /ObjStm /N 2 /First 11 /Filter [/FlateDecode /FlateDecode] >>', gzcompress((string) gzcompress(PDF_SCAN_CLEAN))],
    'predictor' => ['<< /Type /ObjStm /N 2 /First 11 /Filter /FlateDecode /DecodeParms << /Predictor 12 /Columns 1 >> >>', gzcompress(PDF_SCAN_CLEAN)],
    'indirect filter' => ['<< /Type /ObjStm /N 2 /First 11 /Filter 8 0 R >>', gzcompress(PDF_SCAN_CLEAN)],
    'damaged data' => ['<< /Type /ObjStm /N 2 /First 11 /Filter /FlateDecode >>', substr((string) gzcompress(PDF_SCAN_CLEAN), 0, -6)],
    'not compressed although declared' => ['<< /N 2 /First 11 /Filter /FlateDecode >>', PDF_SCAN_CLEAN],
]);

it('refuses a decompression bomb instead of inflating it', function (): void {
    $bomb = gzcompress(PDF_SCAN_CLEAN.str_repeat(' ', PdfScanner::MAX_INFLATED_BYTES));
    $pdf = pdfScanFile('<< /Type /ObjStm /N 2 /First 11 /Filter /FlateDecode >>', $bomb);
    $before = memory_get_peak_usage();

    expect(strlen($pdf))->toBeLessThan(256 * 1024)
        ->and(StoreUpload::pdfHasActiveContent($pdf))->toBeTrue()
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(3 * PdfScanner::MAX_INFLATED_BYTES);
});

it('adds up the inflated size of all object streams', function (): void {
    $half = gzcompress(PDF_SCAN_CLEAN.str_repeat(' ', intdiv(PdfScanner::MAX_INFLATED_BYTES, 2)));
    $stream = fn (int $number): string => "{$number} 0 obj\n<< /Type /ObjStm /N 2 /First 11 /Filter /FlateDecode >>\nstream\n{$half}\nendstream\nendobj\n";

    expect(StoreUpload::pdfHasActiveContent("%PDF-1.5\n".$stream(5).'%%EOF'))->toBeFalse()
        ->and(StoreUpload::pdfHasActiveContent("%PDF-1.5\n".$stream(5).$stream(6).$stream(7).'%%EOF'))->toBeTrue();
});

it('is not fooled by a stream keyword inside a string or comment', function (string $pdf): void {
    expect(StoreUpload::pdfHasActiveContent($pdf))->toBeTrue();
})->with([
    'string hides /AA' => ["%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Note (xstream\n) /AA << /WC 7 0 R >> /Tail (endstream) >>\nendobj\n%%EOF"],
    'string hides /OpenAction' => ["%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Note (xstream\n) /OpenAction 7 0 R /Tail (endstream) >>\nendobj\n%%EOF"],
    'comment hides /JS' => ["%PDF-1.4\n1 0 obj\n<< /S /Rendition % >> stream\n /JS (x) % endstream\n>>\nendobj\n%%EOF"],
    'object stream inside a string' => ["%PDF-1.5\n1 0 obj\n(\n9 0 obj\n<< /N 2 /First 11 /Filter /FlateDecode >>\nstream\n".gzcompress(PDF_SCAN_HOSTILE)."\nendstream\nendobj\n)\nendobj\n%%EOF"],
    'keyword without a dictionary' => ["%PDF-1.4\n5 0 obj\nstream\n".gzcompress(PDF_SCAN_HOSTILE)."\nendstream\nendobj\n%%EOF"],
    'tokens between dictionary and keyword' => [pdfScanFile('<< /Length 9 >> 7', 'abc')],
    // Lenient readers skip the comment and the rest of the keyword's line; ordinary files never look like this.
    'comment before and text after the keyword' => [pdfScanFile('<< /N 2 /First 11 /Filter /FlateDecode >> % x', (string) gzcompress(PDF_SCAN_HOSTILE), "stream x\n")],
]);

it('refuses objects tucked into the body of another stream', function (string $body): void {
    // A cross-reference table can point a reader at an object header anywhere in the file.
    expect(StoreUpload::pdfHasActiveContent(pdfScanFile('<< /Length 99 >>', $body)))->toBeTrue();
})->with([
    'object with /AA' => ["\xFF\xD8 7 0 obj\n<< /Type /Page /AA << /O 8 0 R >> >>\nendobj"],
    'header split by NUL and comments' => ["7\x000%x\n%y\nobj << /AA << /O 8 0 R >> >> endobj"],
    'header behind an earlier comment sign' => ["5%x 7%y\n0 obj << /AA << /O 8 0 R >> >> endobj"],
    'object stream' => ["9 0 obj\n<< /N 2 /First 11 /Filter /FlateDecode >>\nstream\n".gzcompress(PDF_SCAN_HOSTILE)],
    'inside stored (uncompressed) deflate data' => [(string) gzcompress("7 0 obj\n<< /AA << /O 8 0 R >> >>\nendobj", 0)],
]);

it('still finds names written in clear text, hex-escaped or not', function (string $pdf): void {
    expect(StoreUpload::pdfHasActiveContent($pdf))->toBeTrue();
})->with([
    ["%PDF-1.4\n<< /S /JavaScript /JS (app.alert(1)) >>\n%%EOF"],
    ["%PDF-1.4\n<< /J#61vaScript 3 0 R >>\n%%EOF"],
    ["%PDF-1.4\n<< /#4a#61#76#61#53#63#72#69#70#74 3 0 R >>\n%%EOF"],
    ["%PDF-1.4\n<< /EmbeddedFiles 4 0 R >>\n%%EOF"],
    ["%PDF-1.4\n<< /Type /Catalog /OpenAction [3 0 R /Fit] >>\n%%EOF"],
    ["%PDF-1.4\n<< /Type /Page /AA << /O 5 0 R >> >>\n%%EOF"],
    ["%PDF-1.4\n<< /S /Launch /F (cmd.exe) >>\n%%EOF"],
]);

it('accepts ordinary PDFs', function (string $pdf): void {
    expect(StoreUpload::pdfHasActiveContent($pdf))->toBeFalse();
})->with([
    'minimal' => ["%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n"],
    'binary noise in an image' => [pdfScanFile('<< /Type /XObject /Subtype /Image /Filter /DCTDecode /Length 9 >>', "\xFF\xD8/JS\x00/AA\x01(<\xFF\xD9")],
    'strings, hex strings and comments' => [pdfScanFile(
        "<< /Title (Juknis \\(final\\) (v2) 100% ) /ID [<48656C6C6F> <4A53>] % catatan ( <\n/Length 3 >>",
        'abc',
    )],
    'empty stream' => [pdfScanFile('<< /Length 0 >>', '', end: "endstream\nendobj\n")],
    // Only the stream's own keys count: the /N of a function inside the colour space does not make it an object stream.
    'image with /N in a nested dictionary' => [pdfScanFile(
        '<< /Type /XObject /Subtype /Image /ColorSpace [/Separation /Black /DeviceGray << /FunctionType 2 /Domain [0 1] /N 1 >>] /Filter /DCTDecode /Length 9 >>',
        "\xFF\xD8/JS\x00/AA\x01(<\xFF\xD9",
    )],
]);

it('reads the keys of the stream dictionary only, not strings or nested dictionaries', function (): void {
    $payload = gzcompress(PDF_SCAN_HOSTILE);

    // /N directly followed by a string, and a second /Filter key: still an object stream, still must be readable.
    expect(StoreUpload::pdfHasActiveContent(pdfScanFile('<< /First 11 /N(2)x /Filter /FlateDecode >>', $payload)))->toBeTrue()
        ->and(StoreUpload::pdfHasActiveContent(pdfScanFile('<< /N 2 /First 11 /Filter /FlateDecode /Filter /ASCIIHexDecode >>', gzcompress(PDF_SCAN_CLEAN))))->toBeTrue()
        // Keys that only exist inside a string or a nested dictionary are not the stream's keys.
        ->and(StoreUpload::pdfHasActiveContent(pdfScanFile('<< /Note (/N 2 /First 11) /Info << /Type /ObjStm >> /Filter /DCTDecode /Length 9 >>', "\xFF\xD8/JS\x00/AA\x01\xFF\xD9")))->toBeFalse();
});

it('accepts a typical exported PDF: object stream, xref stream, images, colour profile, outline', function (): void {
    $noise = implode('', array_map(fn (int $i): string => hash('sha512', 'sth'.$i, true), range(1, 64))); // 4096 fixed bytes
    $stream = fn (int $number, string $dictionary, string $body): string => "{$number} 0 obj\n{$dictionary}\nstream\r\n{$body}\r\nendstream\nendobj\n";

    $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n"
        .$stream(3, '<< /Type /ObjStm /N 2 /First 11 /Filter /FlateDecode /Length 60 >>', (string) gzcompress(PDF_SCAN_CLEAN))
        .$stream(4, '<< /Filter /FlateDecode /Length 40 >>', (string) gzcompress("q 1 0 0 1 72 720 cm BT /F1 12 Tf (Sertifikat) Tj ET Q\n"))
        ."6 0 obj\n<< /Type /Outlines /First 7 0 R /Last 7 0 R /Count 1 >>\nendobj\n"
        ."7 0 obj\n<< /Title (Bab 1 \\(pembuka\\)) /Parent 6 0 R /Dest [5 0 R /Fit] >>\nendobj\n"
        .$stream(8, '<< /Type /XObject /Subtype /Image /Width 64 /Height 64 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 3 /Columns 64 >> /Length 4107 >>', (string) gzcompress($noise, 0))
        .$stream(9, '<< /Type /XObject /Subtype /Image /Width 64 /Height 64 /ColorSpace /DeviceGray /BitsPerComponent 1 /Filter /CCITTFaxDecode /DecodeParms << /K -1 /Columns 64 >> /Length 4096 >>', $noise)
        .$stream(10, '<< /N 3 /Alternate /DeviceRGB /Filter /FlateDecode /Length 3200 >>', (string) gzcompress("\x00\x00\x0C\x48Lino\x02\x10\x00\x00mntrRGB XYZ ".$noise))
        .$stream(11, '<< /Length1 4096 /Filter /FlateDecode /Length 4107 >>', (string) gzcompress($noise, 0))
        .$stream(12, '<< /Type /XRef /Size 13 /W [1 2 1] /Root 1 0 R /Filter /FlateDecode /DecodeParms << /Columns 4 /Predictor 12 >> /Length 30 >>', (string) gzcompress(str_repeat("\x02\x01\x00\x10\x00", 13)))
        ."startxref\n999\n%%EOF\n";

    expect(StoreUpload::pdfHasActiveContent($pdf))->toBeFalse();
});

it('scans hostile sizes and shapes in linear time', function (string $pdf): void {
    $started = microtime(true);
    StoreUpload::pdfHasActiveContent($pdf);

    expect(microtime(true) - $started)->toBeLessThan(5.0);
})->with([
    'digits' => [pdfScanFile('<< /Length 9 >>', str_repeat('1', 4_000_000)).str_repeat('7', 2_000_000)],
    'digits and spaces' => [pdfScanFile('<< /Length 9 >>', str_repeat('1 ', 2_000_000))],
    'comment openers' => [pdfScanFile('<< /Length 9 >>', str_repeat('%', 2_000_000)).str_repeat("%\n", 1_000_000)],
    'dictionary openers' => ["%PDF-1.4\n".str_repeat('<<', 1_000_000)."\nstream\nx\nendstream"],
    'string openers' => ["%PDF-1.4\n".str_repeat('(', 2_000_000)."\nstream\nx\nendstream"],
    'slashes' => ["%PDF-1.4\n".str_repeat('/', 2_000_000).str_repeat('/#4a', 500_000)],
    'stream keywords' => ["%PDF-1.4\n".str_repeat("<< >>\nstream\n\nendstream\n", 100_000)],
]);
