<?php

declare(strict_types=1);

namespace App\Domain\Letters;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Renders a static Blade template from resources/pdf with dompdf ≥ 3.1.6, locked down per SECURITY §3g/§3m-6:
 * remote fetch, JavaScript and PHP off; only the `file://` protocol (no data:, http:, https:, phar:);
 * chroot = resources/pdf + a per-render sandbox under storage/app/pdf-tmp. Images the template needs
 * (signature + stamp, QR code) are written into that sandbox from bytes and the sandbox is deleted after
 * the render, so the signature file itself never gets a URL and never leaves the PDF.
 *
 * Templates only print data with {{ }}; never render Blade from a database string (SSTI).
 * Configured here, not in config/dompdf.php, so the options cannot be loosened by accident.
 */
final class PdfRenderer
{
    public const VIEW_NAMESPACE = 'pdf';

    /** Allowed sandbox image names: lowercase, one of png/jpg/jpeg. */
    private const IMAGE_NAME = '/^[a-z0-9][a-z0-9-]{0,40}\.(png|jpe?g)$/';

    /**
     * @param  string|null  $sandboxRoot  parent folder of the per-render sandboxes (default storage/app/pdf-tmp;
     *                                    tests give each run its own folder)
     */
    public function __construct(
        private readonly ViewFactory $views,
        private readonly Filesystem $files,
        private readonly ?string $sandboxRoot = null,
    ) {}

    /**
     * @param  string  $template  view name inside resources/pdf, e.g. 'surat-tugas-siswa'
     * @param  array<string, mixed>  $data  view data (escaped with {{ }} in the template)
     * @param  array<string, string>  $images  sandbox file name => raw PNG/JPEG bytes; the view receives
     *                                         `$pdfImages[name]` = absolute path inside the sandbox
     */
    public function render(string $template, array $data = [], array $images = []): PdfDocument
    {
        if (preg_match('/^[a-z0-9][a-z0-9.-]{0,60}$/', $template) !== 1) {
            throw new InvalidArgumentException('Nama template surat tidak valid.');
        }

        $this->views->addNamespace(self::VIEW_NAMESPACE, resource_path('pdf'));

        $sandbox = rtrim($this->sandboxRoot ?? storage_path('app/pdf-tmp'), '/').'/'.Str::lower((string) Str::ulid());
        $this->files->ensureDirectoryExists($sandbox, 0700);

        try {
            $paths = [];

            foreach ($images as $name => $bytes) {
                $paths[$name] = $this->writeImage($sandbox, (string) $name, $bytes);
            }

            $html = $this->views->make(self::VIEW_NAMESPACE.'::'.$template, $data + ['pdfImages' => $paths])->render();

            $dompdf = new Dompdf($this->options($sandbox));
            $dompdf->setBasePath($sandbox);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $bytes = (string) $dompdf->output(['compress' => 1]);
            $pages = (int) $dompdf->getCanvas()->get_page_count();
        } finally {
            $this->files->deleteDirectory($sandbox);
        }

        if (! str_starts_with($bytes, '%PDF-')) {
            throw new RuntimeException('PDF surat gagal dibuat.');
        }

        return new PdfDocument($bytes, max(1, $pages));
    }

    public function options(string $sandbox): Options
    {
        $fontCache = storage_path('app/pdf-fonts');
        $this->files->ensureDirectoryExists($fontCache, 0700);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setAllowedProtocols(['file://']);
        $options->setChroot([resource_path('pdf'), $sandbox]);
        $options->setTempDir($sandbox);
        $options->setFontDir(base_path('vendor/dompdf/dompdf/lib/fonts'));
        $options->setFontCache($fontCache);
        $options->setDefaultFont('serif');
        $options->setDefaultPaperSize('a4');
        $options->setDpi(96);

        return $options;
    }

    private function writeImage(string $sandbox, string $name, string $bytes): string
    {
        if (preg_match(self::IMAGE_NAME, $name) !== 1) {
            throw new InvalidArgumentException('Nama gambar surat tidak valid.');
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false || ! in_array($info[2] ?? null, [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw new InvalidArgumentException('Gambar surat harus PNG atau JPEG.');
        }

        $path = $sandbox.DIRECTORY_SEPARATOR.$name;

        try {
            $this->files->put($path, $bytes);
        } catch (Throwable $e) {
            throw new RuntimeException('Gambar surat tidak bisa disiapkan.', 0, $e);
        }

        return $path;
    }
}
