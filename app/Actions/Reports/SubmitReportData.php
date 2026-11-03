<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Enums\ResultType;
use Illuminate\Http\UploadedFile;

/**
 * Input of SubmitReport (laporan hasil). Files are raw uploads; SubmitReport stores them through
 * App\Domain\Files\StoreUpload. On a revision (perlu_revisi) a kind that gets new files replaces the
 * old files of that kind; kinds without new files keep what was sent before.
 */
final readonly class SubmitReportData
{
    /**
     * @param  list<UploadedFile>  $photos  foto dokumentasi (at least one in total, max SubmitReport::MAX_PHOTOS)
     */
    public function __construct(
        public ResultType $result,
        public bool $authentic,
        public ?UploadedFile $certificate = null,
        public array $photos = [],
        public ?UploadedFile $announcement = null,
        public ?string $researchTitle = null,
        public ?string $researchAbstract = null,
        public ?UploadedFile $researchReport = null,
        public ?UploadedFile $article = null,
        public ?UploadedFile $poster = null,
    ) {}
}
