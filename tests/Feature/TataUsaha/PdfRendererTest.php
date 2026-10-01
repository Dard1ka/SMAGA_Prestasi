<?php

declare(strict_types=1);

use App\Domain\Letters\PdfQrCode;
use App\Domain\Letters\PdfRenderer;

it('locks dompdf down: no remote, no JavaScript, no PHP, file:// only, narrow chroot', function (): void {
    $sandbox = storage_path('app/pdf-tmp/uji');
    $options = app(PdfRenderer::class)->options($sandbox);

    expect($options->getIsRemoteEnabled())->toBeFalse()
        ->and($options->getIsJavascriptEnabled())->toBeFalse()
        ->and($options->getIsPhpEnabled())->toBeFalse()
        ->and(array_keys($options->getAllowedProtocols()))->toBe(['file://'])
        ->and($options->getChroot())->toBe([resource_path('pdf'), $sandbox])
        ->and($options->getTempDir())->toBe($sandbox);
});

it('rejects unknown template names and non-image sandbox files', function (): void {
    $renderer = app(PdfRenderer::class);

    expect(fn () => $renderer->render('../../.env'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $renderer->render('surat-tugas-siswa', [], ['ttd.png' => '<svg onload="x"/>']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $renderer->render('surat-tugas-siswa', [], ['../ttd.png' => (new PdfQrCode)->png('x')]))->toThrow(InvalidArgumentException::class);

    expect(glob($this->pdfSandboxRoot.'/*') ?: [])->toBe([]);
});

it('draws a scannable-size QR PNG', function (): void {
    $png = (new PdfQrCode)->png('https://prestasi.sman3-smg.sch.id/verifikasi-surat/'.str_repeat('a', 32), 6);
    $info = getimagesizefromstring($png);

    expect($info[2])->toBe(IMAGETYPE_PNG)
        ->and($info[0])->toBe($info[1])
        ->and($info[0])->toBeGreaterThan(150);
});
