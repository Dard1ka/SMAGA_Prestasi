<?php

declare(strict_types=1);

namespace Database\Seeders\Support\Demo;

/**
 * One sample document, ready for DemoPdfRenderer: which template of database/seeders/demo, the data it prints
 * and the pictures it needs (LOCAL ONLY). Two documents with the same content render to the same bytes.
 */
final readonly class DemoDocument
{
    /** Page numbers of a research report: none on the cover, roman up to the first chapter, then 1, 2, … */
    public const PAGING_REPORT = 'laporan';

    /** "Halaman 2 dari 4" in the bottom right corner. */
    public const PAGING_PLAIN = 'halaman';

    /**
     * @param  string  $view  template name inside database/seeders/demo, e.g. 'sertifikat'
     * @param  array<string, mixed>  $data  view data, printed with {{ }} only
     * @param  array<string, string>  $images  sandbox file name (svg, png, jpg) => bytes; the view gets `$img[name]`
     * @param  'portrait'|'landscape'  $orientation
     * @param  self::PAGING_*|null  $paging
     */
    public function __construct(
        public DemoDocumentKind $kind,
        public string $view,
        public array $data,
        public array $images = [],
        public string $orientation = 'portrait',
        public ?string $paging = null,
        public string $title = 'Dokumen contoh',
    ) {}
}
