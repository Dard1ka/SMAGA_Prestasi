<?php

declare(strict_types=1);

namespace Database\Seeders\Support\Demo;

use App\Enums\FilePurpose;
use App\Enums\ReportFileKind;
use App\Models\File;
use App\Models\Report;
use App\Models\ReportFile;
use Database\Seeders\Support\DemoFiles;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Turns the placeholder PDFs of the local demo data into realistic sample documents (LOCAL ONLY: refuses to run
 * unless APP_ENV=local). Used by DemoSeeder right after seeding and by `php artisan sth:demo:refresh-files` for a
 * database that was seeded earlier.
 *
 * What it touches, and nothing else:
 * - `files` rows on the `local` disk whose path is "demo/<name>.pdf" (what DemoFiles writes) with MIME
 *   application/pdf. Files uploaded by hand live in other folders and are never read or changed;
 * - of such a row: the bytes of the file at the SAME path (so no orphan file), `size` and `sha256`.
 *
 * Running it again changes nothing: every PDF carries the identifier of the document it was rendered from
 * (DemoPdfRenderer::id), so an up-to-date file is neither rendered nor written again. A template or data change
 * gives another identifier and the file is renewed.
 *
 * attachMissing() is the one exception to "no other table" and only runs when asked for (`--lengkapi`): it adds
 * the research report file a fresh seed attaches to demo reports that have a research title.
 */
final class DemoFileRefresher
{
    public const DIRECTORY = DemoFiles::DIRECTORY;

    private const DEMO_PDF_PATH = '~^'.self::DIRECTORY.'/[A-Za-z0-9_-]+\.pdf$~';

    public function __construct(
        private readonly DemoDocumentResolver $resolver,
        private readonly DemoPdfRenderer $renderer,
    ) {}

    /**
     * @param  bool  $force  render and write every file again, also the ones that are up to date
     * @param  (callable(File, string): void)|null  $progress  called per file with what happened
     * @param  list<string>  $names  only files whose original name matches one of these (`*` = anything); [] = all
     * @return array{total: int, written: array<string, int>, unchanged: int, skipped: array<string, int>, general: array<string, int>, bytes: int}
     */
    public function refresh(bool $force = false, ?callable $progress = null, array $names = []): array
    {
        self::assertLocal();

        $summary = ['total' => 0, 'written' => [], 'unchanged' => 0, 'skipped' => [], 'general' => [], 'bytes' => 0];
        $disk = Storage::disk('local');

        $files = File::query()
            ->where('disk', 'local')
            ->where('path', 'like', self::DIRECTORY.'/%')
            ->orderBy('id')
            ->get();

        foreach ($files as $file) {
            if ($names !== [] && ! Str::is($names, $file->original_name)) {
                continue;
            }

            $summary['total']++;

            if (preg_match(self::DEMO_PDF_PATH, $file->path) !== 1 || $file->mime !== 'application/pdf') {
                $summary['skipped']['bukan PDF demo'] = ($summary['skipped']['bukan PDF demo'] ?? 0) + 1;
                $progress?->__invoke($file, 'dilewati');

                continue;
            }

            try {
                [$document, $note] = $this->resolver->resolve($file);
                $id = $this->renderer->id($document);
                $current = $disk->exists($file->path) ? (string) $disk->get($file->path) : null;

                if (! $force && $current !== null && DemoPdfRenderer::hasId($current, $id)) {
                    if ($file->size !== strlen($current) || $file->sha256 !== hash('sha256', $current)) {
                        $file->forceFill(['size' => strlen($current), 'sha256' => hash('sha256', $current)])->save();
                    }

                    $summary['unchanged']++;
                    $summary['bytes'] += strlen($current);
                    $progress?->__invoke($file, 'sudah terbaru');

                    continue;
                }

                $pdf = $this->renderer->render($document);

                if ($disk->put($file->path, $pdf->bytes) === false) {
                    throw new RuntimeException('Berkas tidak bisa ditulis.');
                }

                $file->forceFill(['size' => $pdf->size(), 'sha256' => $pdf->sha256()])->save();
            } catch (Throwable $e) {
                report($e);
                $summary['skipped']['gagal dibuat'] = ($summary['skipped']['gagal dibuat'] ?? 0) + 1;
                $progress?->__invoke($file, 'gagal: '.$e->getMessage());

                continue;
            }

            $kind = $document->kind->label();
            $summary['written'][$kind] = ($summary['written'][$kind] ?? 0) + 1;
            $summary['bytes'] += $pdf->size();

            if ($note !== null) {
                $summary['general'][$note] = ($summary['general'][$note] ?? 0) + 1;
            }

            $progress?->__invoke($file, $kind);
        }

        ksort($summary['written']);

        return $summary;
    }

    /**
     * Adds what a fresh demo seed attaches but an older demo database lacks: a research report file (kind
     * laporan_riset) for every demo report that has a research title and no such file yet. A "demo report" is one
     * whose evidence files all live in demo/. Inserts one `files` row (a placeholder, rendered by the next
     * refresh()) and one `report_files` row per report. Only on request.
     *
     * @return int number of reports that got the file
     */
    public function attachMissing(): int
    {
        self::assertLocal();

        $added = 0;
        $reports = Report::query()
            ->whereNotNull('research_title')
            ->where('research_title', '!=', '')
            ->whereDoesntHave('files', fn ($q) => $q->where('kind', ReportFileKind::LaporanRiset))
            ->with('files.file')
            ->get();

        foreach ($reports as $report) {
            $files = $report->files->map(fn (ReportFile $rf): ?File => $rf->file)->filter();

            if ($files->isEmpty() || $files->contains(fn (File $f): bool => ! str_starts_with($f->path, self::DIRECTORY.'/'))) {
                continue;
            }

            ReportFile::query()->create([
                'report_id' => $report->getKey(),
                'kind' => ReportFileKind::LaporanRiset,
                'file_id' => DemoFiles::pdf(DemoFiles::researchReportName((string) $report->research_title), FilePurpose::ReportEvidence, $report->submitted_by)->getKey(),
            ]);
            $added++;
        }

        return $added;
    }

    /**
     * The summary as lines for the console.
     *
     * @param  array{total: int, written: array<string, int>, unchanged: int, skipped: array<string, int>, general: array<string, int>, bytes: int}  $summary
     * @return list<string>
     */
    public static function lines(array $summary): array
    {
        $lines = [sprintf('Berkas demo di folder %s/: %d', self::DIRECTORY, $summary['total'])];

        foreach ($summary['written'] as $kind => $count) {
            $lines[] = sprintf('  dibuat ulang · %s: %d', $kind, $count);
        }

        if ($summary['unchanged'] > 0) {
            $lines[] = sprintf('  sudah terbaru (tidak diubah): %d', $summary['unchanged']);
        }

        foreach ($summary['skipped'] as $reason => $count) {
            $lines[] = sprintf('  dilewati · %s: %d', $reason, $count);
        }

        foreach ($summary['general'] as $reason => $count) {
            $lines[] = sprintf('  catatan · %s: %d', $reason, $count);
        }

        $lines[] = sprintf('  ukuran seluruh berkas: %d kB', (int) round($summary['bytes'] / 1024));

        return $lines;
    }

    private static function assertLocal(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Berkas demo hanya boleh dibuat pada APP_ENV=local.');
        }
    }
}
