<?php

declare(strict_types=1);

namespace Database\Seeders\Support\Demo;

use App\Domain\Letters\PdfDocument;
use App\Domain\Letters\PdfRenderer;
use DOMElement;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Frame;
use Dompdf\Helpers;
use Dompdf\Options;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Renders a sample document of the local demo data with dompdf (LOCAL ONLY: the callers, DemoFileRefresher and
 * the tests, refuse to run elsewhere). Templates live in database/seeders/demo and are never rendered by the app
 * itself. Locked down like App\Domain\Letters\PdfRenderer: no remote fetch, no JavaScript, no PHP in templates,
 * only file:// inside the template folder, resources/pdf (the school's letter templates and their two emblems,
 * used by the demo letters only) and a per-render sandbox that holds the generated pictures.
 *
 * - Core PDF fonts only (Times, Helvetica): nothing is embedded, a certificate is a few kB.
 * - The output is deterministic: fixed creation date and a file identifier made from the content, so the same
 *   document always gives the same bytes. The identifier is also how a later run sees that a stored file is
 *   already up to date (id() / hasId()) without rendering it again.
 * - One render per distinct document and process (cache), so identical files cost one render.
 */
final class DemoPdfRenderer
{
    public const VIEW_NAMESPACE = 'demo-pdf';

    /** On every page of every sample document. */
    public const FOOTER = 'CONTOH · data fiktif untuk uji SMAGA Talent Hub · bukan dokumen resmi';

    /** Bump to make every stored demo file out of date. */
    public const VERSION = '1';

    private const IMAGE_NAME = '/^[a-z0-9][a-z0-9-]{0,40}\.(svg|png|jpe?g)$/';

    /** "Today" of the demo data (DESIGN-SPEC-v2 §5); a fixed date keeps the bytes the same on every run. */
    private const CREATION_DATE = 'D:20260930';

    /** @var array<string, PdfDocument> */
    private array $cache = [];

    private ?string $fingerprint = null;

    /**
     * @param  string|null  $sandboxRoot  parent folder of the per-render sandboxes (default: defaultSandboxRoot())
     */
    public function __construct(
        private readonly ViewFactory $views,
        private readonly Filesystem $files,
        private readonly ?string $sandboxRoot = null,
    ) {}

    public static function templatePath(): string
    {
        return database_path('seeders/demo');
    }

    /**
     * The system temp folder, not storage/: on the Windows bind mount of the dev stack every file operation costs
     * milliseconds and a certificate needs half a dozen pictures (0.8 s per certificate there, see the M3-D note).
     */
    public static function defaultSandboxRoot(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\').'/sth-demo-pdf';
    }

    /**
     * 32 hex characters that identify this exact document: its data and pictures, the templates and the code that
     * draws them. Written into the PDF trailer (/ID).
     */
    public function id(DemoDocument $document): string
    {
        $images = array_map(fn (string $bytes): string => hash('sha256', $bytes), $document->images);
        ksort($images);

        return substr(hash('sha256', serialize([
            self::VERSION,
            $this->fingerprint(),
            $document->kind->value,
            $document->view,
            $document->orientation,
            $document->paging,
            $document->title,
            $document->data,
            $images,
        ])), 0, 32);
    }

    /** Was this PDF rendered from the document with that id? */
    public static function hasId(string $bytes, string $id): bool
    {
        return str_contains(substr($bytes, -1024), '/ID[<'.$id.'><'.$id.'>]');
    }

    /**
     * @param  bool  $compress  false keeps the page content readable (tests look for text in it)
     */
    public function render(DemoDocument $document, bool $compress = true): PdfDocument
    {
        // Second lock behind the callers' APP_ENV=local checks: these sample documents never exist in production.
        if (app()->isProduction()) {
            throw new RuntimeException('Dokumen contoh tidak dibuat di production.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9.-]{0,60}$/', $document->view) !== 1) {
            throw new InvalidArgumentException('Nama template dokumen contoh tidak valid.');
        }

        $id = $this->id($document);
        $key = $id.($compress ? '' : '-plain');

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $this->views->addNamespace(self::VIEW_NAMESPACE, self::templatePath());
        $this->views->addNamespace(PdfRenderer::VIEW_NAMESPACE, resource_path('pdf'));

        $sandbox = rtrim($this->sandboxRoot ?? self::defaultSandboxRoot(), '/').'/demo-'.Str::lower((string) Str::ulid());
        $this->files->ensureDirectoryExists($sandbox, 0700);

        try {
            $paths = [];

            foreach ($document->images as $name => $bytes) {
                $paths[$name] = $this->writeImage($sandbox, (string) $name, $bytes);
            }

            $data = $document->data + ['img' => $paths, 'pdfImages' => $paths, 'contoh' => self::FOOTER, 'toc' => [], 'bodyStart' => 1];
            $view = self::VIEW_NAMESPACE.'::'.$document->view;

            if ($document->paging === DemoDocument::PAGING_REPORT) {
                // First pass: on which page does every marked heading land? Second pass prints the table of contents.
                $marks = [];
                $this->dompdf($sandbox, $document, $this->views->make($view, $data)->render(), $marks);
                $bodyStart = max(1, (int) ($marks['bab-1'] ?? 1));
                $data['bodyStart'] = $bodyStart;
                $data['toc'] = array_map(
                    fn (int $page): string => $page < $bodyStart ? Helpers::dec2roman($page) : (string) ($page - $bodyStart + 1),
                    $marks,
                );
            }

            $unused = [];
            $dompdf = $this->dompdf($sandbox, $document, $this->views->make($view, $data)->render(), $unused, (int) $data['bodyStart']);
            $dompdf->addInfo('Title', $document->title);
            $dompdf->addInfo('Author', 'SMAGA Talent Hub (data contoh)');
            $dompdf->addInfo('Subject', self::FOOTER);
            $dompdf->addInfo('CreationDate', self::CREATION_DATE);
            $dompdf->addInfo('ModDate', self::CREATION_DATE);

            $canvas = $dompdf->getCanvas();

            if (method_exists($canvas, 'get_cpdf')) {
                $canvas->get_cpdf()->fileIdentifier = $id;
            }

            $bytes = (string) $dompdf->output(['compress' => $compress ? 1 : 0]);
            $pages = (int) $canvas->get_page_count();
        } finally {
            $this->files->deleteDirectory($sandbox);
        }

        if (! str_starts_with($bytes, '%PDF-') || ($compress && ! self::hasId($bytes, $id))) {
            throw new RuntimeException('PDF contoh gagal dibuat: '.$document->view);
        }

        return $this->cache[$key] = new PdfDocument($bytes, max(1, $pages));
    }

    /**
     * @param  array<string, int>  $marks  filled with data-toc key => first page it is drawn on
     */
    private function dompdf(string $sandbox, DemoDocument $document, string $html, array &$marks, int $bodyStart = 1): Dompdf
    {
        $options = $this->options($sandbox);
        $this->warmFontCache($options);

        $dompdf = new Dompdf($options);
        $dompdf->setBasePath($sandbox);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $document->orientation === 'landscape' ? 'landscape' : 'portrait');

        $callbacks = [[
            'event' => 'end_frame',
            'f' => function (Frame $frame, Canvas $canvas) use (&$marks): void {
                $node = $frame->get_node();

                if ($node instanceof DOMElement && $node->hasAttribute('data-toc')) {
                    $marks[$node->getAttribute('data-toc')] ??= (int) $canvas->get_page_number();
                }
            },
        ]];

        if ($document->paging !== null) {
            $callbacks[] = ['event' => 'end_document', 'f' => $this->pageNumbers($document->paging, $bodyStart)];
        }

        $dompdf->setCallbacks($callbacks);
        $dompdf->render();

        return $dompdf;
    }

    /** Page numbers are drawn on the finished pages, so front matter and chapters can count separately. */
    private function pageNumbers(string $paging, int $bodyStart): callable
    {
        return function (int $page, int $count, Canvas $canvas, FontMetrics $fonts) use ($paging, $bodyStart): void {
            if ($paging === DemoDocument::PAGING_REPORT) {
                if ($page === 1) {
                    return;
                }

                $label = $page < $bodyStart ? Helpers::dec2roman($page) : (string) ($page - $bodyStart + 1);
                $font = $fonts->getFont('Times', 'normal');
                // Centre of the text area: margins 4 cm left, 3 cm right.
                $x = (113.4 + ($canvas->get_width() - 85.05)) / 2 - $fonts->getTextWidth($label, $font, 11) / 2;
                $canvas->text($x, $canvas->get_height() - 58, $label, $font, 11, [0, 0, 0]);

                return;
            }

            $label = sprintf('Halaman %d dari %d', $page, $count);
            $font = $fonts->getFont('Helvetica', 'normal');
            // Right edge of the text area of the juknis (margin 2.3 cm).
            $canvas->text($canvas->get_width() - 65.2 - $fonts->getTextWidth($label, $font, 8), $canvas->get_height() - 44, $label, $font, 8, [0.35, 0.35, 0.35]);
        };
    }

    private function options(string $sandbox): Options
    {
        // Parsed metrics of the core fonts; also kept out of the bind mount (rebuilt in milliseconds when missing).
        $fontCache = self::defaultSandboxRoot().'/fonts';
        $this->files->ensureDirectoryExists($fontCache, 0700);

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setAllowedProtocols(['file://']);
        $options->setChroot([self::templatePath(), resource_path('pdf'), $sandbox]);
        $options->setTempDir($sandbox);
        $options->setFontDir(base_path('vendor/dompdf/dompdf/lib/fonts'));
        $options->setFontCache($fontCache);
        $options->setDefaultFont('serif');
        $options->setDefaultPaperSize('a4');
        $options->setDpi(96);

        return $options;
    }

    /**
     * php-svg-lib selects its default font (Times-Roman) for every shape, and finds it only when its metrics are
     * already in the font cache: a PDF with pictures then carries one more font entry than the same PDF rendered
     * on an empty cache. Render one word in Times first, so the cache always holds it and the bytes never differ.
     */
    private function warmFontCache(Options $options): void
    {
        if ($this->files->exists($options->getFontCache().'/Times-Roman.afm.json')) {
            return;
        }

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml('<html><body style="font-family: Times, serif;">contoh</body></html>', 'UTF-8');
        $dompdf->render();
    }

    private function writeImage(string $sandbox, string $name, string $bytes): string
    {
        if (preg_match(self::IMAGE_NAME, $name) !== 1 || $bytes === '') {
            throw new InvalidArgumentException('Gambar dokumen contoh tidak valid: '.$name);
        }

        $path = $sandbox.DIRECTORY_SEPARATOR.$name;
        $this->files->put($path, $bytes);

        return $path;
    }

    /** Changes whenever a template, an emblem of the letters or the drawing code changes. */
    private function fingerprint(): string
    {
        if ($this->fingerprint !== null) {
            return $this->fingerprint;
        }

        $parts = [];

        foreach ([self::templatePath(), resource_path('pdf'), __DIR__] as $folder) {
            if (! $this->files->isDirectory($folder)) {
                continue;
            }

            $found = array_map(fn (SplFileInfo $file): string => $file->getRelativePathname().':'.md5_file($file->getPathname()), $this->files->allFiles($folder));
            sort($found);
            $parts[] = $found;
        }

        return $this->fingerprint = hash('sha256', serialize($parts));
    }
}
