<?php

declare(strict_types=1);

use App\Domain\Files\InvalidUpload;
use App\Domain\Files\StoreUpload;
use App\Domain\Letters\PdfQrCode;
use App\Domain\Letters\PdfRenderer;
use App\Enums\FilePurpose;
use App\Livewire\TataUsaha\TemplateSurat;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| The PDF scan on the real upload path (StoreUpload, SECURITY §3d). The crafted byte strings are covered in
| tests/Unit/Domain/Files/PdfActiveContentTest.php.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    $this->nadia = F::user(F::student());
});

it('accepts the letter PDF the app itself generates (dompdf, with images) as an upload', function (): void {
    $stamp = imagecreatetruecolor(120, 60);
    imagefill($stamp, 0, 0, imagecolorallocate($stamp, 49, 72, 214));
    ob_start();
    imagepng($stamp);
    $stampPng = (string) ob_get_clean();

    $letter = app(PdfRenderer::class)->render('surat-tugas-siswa', [
        'content' => TemplateSurat::sample(),
        'number' => '400.3.8/313/MRD/X/2026',
        'verification' => ['code' => str_repeat('b', 32), 'url' => 'https://contoh.test/verifikasi-surat/'.str_repeat('b', 32)],
    ], ['qr.png' => (new PdfQrCode)->png('https://contoh.test/verifikasi-surat/'.str_repeat('b', 32)), 'ttd.png' => $stampPng]);

    expect(substr_count($letter->bytes, "\nstream\n"))->toBeGreaterThan(3)
        ->and(StoreUpload::pdfHasActiveContent($letter->bytes))->toBeFalse();

    $file = app(StoreUpload::class)->store(
        UploadedFile::fake()->createWithContent('surat-tugas.pdf', $letter->bytes),
        FilePurpose::ReportEvidence,
        $this->nadia,
    );

    expect($file->mime)->toBe('application/pdf')
        ->and(Storage::disk('local')->get($file->path))->toBe($letter->bytes);
});

it('refuses an upload that hides a script in a compressed object stream', function (string $dictionary): void {
    $objects = '11 0 12 28 << /OpenAction 12 0 R >> << /S /JavaScript /JS (app.alert(1)) >>';
    $pdf = "%PDF-1.5\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n5 0 obj\n{$dictionary}\nstream\n"
        .gzcompress($objects)."\nendstream\nendobj\n%%EOF\n";

    try {
        app(StoreUpload::class)->store(UploadedFile::fake()->createWithContent('juknis.pdf', $pdf), FilePurpose::Juknis, $this->nadia);
        $this->fail('Expected InvalidUpload.');
    } catch (InvalidUpload $e) {
        expect($e->errors)->toBe([StoreUpload::MSG_PDF_ACTIVE]);
    }

    expect(File::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'hex-escaped /ObjStm' => ['<< /Type /#4FbjStm /N 2 /First 11 /Filter /FlateDecode >>'],
    'nested dictionary' => ['<< /Type /ObjStm /N 2 /First 11 /Filter /FlateDecode /DecodeParms << /Columns 1 >> >>'],
    'no /Type' => ['<< /N 2 /First 11 /Filter /FlateDecode >>'],
    'unsupported filter' => ['<< /Type /ObjStm /N 2 /First 11 /Filter /LZWDecode >>'],
]);
