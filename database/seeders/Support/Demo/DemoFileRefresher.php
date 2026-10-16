<?php

declare(strict_types=1);

namespace Database\Seeders\Support\Demo;

use App\Domain\Files\CoverThumbnail;
use App\Enums\FilePurpose;
use App\Enums\LetterType;
use App\Enums\ReportFileKind;
use App\Models\Competition;
use App\Models\File;
use App\Models\Letter;
use App\Models\Report;
use App\Models\ReportFile;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\DemoCoverArt;
use Database\Seeders\Support\DemoFiles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Turns the placeholder PDFs of the local demo data into realistic sample documents, and the placeholder covers of
 * the demo lomba into drawn posters (LOCAL ONLY: refuses to run unless APP_ENV=local). Used by DemoSeeder right
 * after seeding and by `php artisan sth:demo:refresh-files` for a database that was seeded earlier.
 *
 * What it touches, and nothing else:
 * - `files` rows on the `local` disk whose path is "demo/<name>.pdf" (what DemoFiles writes) with MIME
 *   application/pdf, and rows with purpose `cover` whose path is "demo/<name>.jpg" with MIME image/jpeg. Files
 *   uploaded by hand live in other folders and are never read or changed;
 * - of such a row: the bytes of the file at the SAME path (so no orphan file), `size` and `sha256`;
 * - of a cover whose bytes it replaced: the small picture the app keeps of it (CoverThumbnail::forget(), a derived
 *   file under sampul/ that the next request makes again).
 *
 * Running it again changes nothing: every PDF carries the identifier of the document it was rendered from
 * (DemoPdfRenderer::id) and every poster the identifier of what it was drawn from (DemoCoverArt::id), so an
 * up-to-date file is neither made nor written again. A template, drawing or data change gives another identifier
 * and the file is renewed.
 *
 * attachMissing(), attachMissingCovers() and fillLetterBasis() are the exceptions to "no other table" and only run
 * when asked for (`--lengkapi`): they add the research report file a fresh seed attaches to demo reports that have
 * a research title, the cover a fresh seed gives the demo lomba of DemoCoverArt::POSTERS, and the invitation number
 * and date a fresh seed gives the demo letters (QA5). missing() counts all three without changing anything, for
 * the hint the command prints without `--lengkapi`.
 */
final class DemoFileRefresher
{
    public const DIRECTORY = DemoFiles::DIRECTORY;

    private const DEMO_PDF_PATH = '~^'.self::DIRECTORY.'/[A-Za-z0-9_-]+\.pdf$~';

    private const DEMO_COVER_PATH = '~^'.self::DIRECTORY.'/[A-Za-z0-9_-]+\.jpg$~';

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

            if (self::isCover($file)) {
                try {
                    $written = $this->refreshCover($file, $force);
                } catch (Throwable $e) {
                    report($e);
                    $summary['skipped']['gagal dibuat'] = ($summary['skipped']['gagal dibuat'] ?? 0) + 1;
                    $progress?->__invoke($file, 'gagal: '.$e->getMessage());

                    continue;
                }

                if ($written) {
                    $summary['written'][DemoCoverArt::LABEL] = ($summary['written'][DemoCoverArt::LABEL] ?? 0) + 1;
                } else {
                    $summary['unchanged']++;
                }

                $summary['bytes'] += $file->size;
                $progress?->__invoke($file, $written ? DemoCoverArt::LABEL : 'sudah terbaru');

                continue;
            }

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

        foreach ($this->reportsWithoutResearchFile() as $report) {
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
     * Adds the cover a fresh demo seed gives the lomba of DemoCoverArt::POSTERS to those that have none in an older
     * demo database. A "demo lomba" is one with such a name whose juknis lives in demo/, so a lomba somebody typed
     * in with the same name is left alone. Inserts one `files` row (the placeholder, drawn by the next refresh())
     * and sets `competitions.cover_file_id`, without model events and so without an audit entry. Only on request.
     *
     * @return int number of lomba that got a cover
     */
    public function attachMissingCovers(): int
    {
        self::assertLocal();

        $added = 0;

        foreach ($this->competitionsWithoutCover() as $competition) {
            $owner = $competition->submitted_by === null ? null : (int) $competition->submitted_by;
            $file = DemoFiles::cover((string) DemoCoverArt::fileNameFor((string) $competition->name), $owner);

            Competition::query()->whereKey($competition->getKey())->toBase()->update(['cover_file_id' => $file->getKey()]);
            $added++;
        }

        return $added;
    }

    /**
     * Gives every demo guru letter (its PDF in demo/, named the way DemoSeeder names it) that lacks the number or the
     * date of the invitation it answers the values a fresh seed writes (DemoLetterBasis). A letter Tata Usaha made
     * is never touched. Written without model events, so no audit entry. Only on request.
     *
     * @return int number of letters filled
     */
    public function fillLetterBasis(): int
    {
        self::assertLocal();

        $filled = 0;

        foreach ($this->lettersWithoutBasis() as $letter) {
            $basis = DemoLetterBasis::for((string) DemoLetterBasis::keyOf($letter), CarbonImmutable::parse($letter->issued_on->toDateString()));

            Letter::query()->whereKey($letter->getKey())->update([
                'basis_number' => filled($letter->basis_number) ? $letter->basis_number : $basis['number'],
                'basis_date' => $letter->basis_date?->toDateString() ?? $basis['date'],
            ]);
            $filled++;
        }

        return $filled;
    }

    /**
     * What `--lengkapi` would add, without changing anything.
     *
     * @return array{reports: int, letters: int, covers: int}
     */
    public function missing(): array
    {
        self::assertLocal();

        return [
            'reports' => $this->reportsWithoutResearchFile()->count(),
            'letters' => $this->lettersWithoutBasis()->count(),
            'covers' => $this->competitionsWithoutCover()->count(),
        ];
    }

    /** A cover picture of the demo data: purpose `cover`, a JPEG at demo/<name>.jpg (what DemoFiles::cover() writes). */
    private static function isCover(File $file): bool
    {
        return $file->purpose === FilePurpose::Cover
            && $file->mime === 'image/jpeg'
            && preg_match(self::DEMO_COVER_PATH, $file->path) === 1;
    }

    /**
     * Draws the poster of a demo cover over the file at the same path, unless the stored picture is already the
     * one this data gives (and $force is off).
     *
     * @return bool true = written, false = up to date
     */
    private function refreshCover(File $file, bool $force): bool
    {
        $disk = Storage::disk('local');
        $spec = DemoCoverArt::describe($file->original_name, Competition::query()->where('cover_file_id', $file->getKey())->first());
        $current = $disk->exists($file->path) ? (string) $disk->get($file->path) : null;
        $bytes = ! $force && $current !== null && DemoCoverArt::hasId($current, DemoCoverArt::id($spec)) ? null : DemoCoverArt::render($spec);

        if ($bytes !== null && $disk->put($file->path, $bytes) === false) {
            throw new RuntimeException('Berkas tidak bisa ditulis.');
        }

        $stored = $bytes ?? (string) $current;

        if ($bytes !== null || $file->size !== strlen($stored) || $file->sha256 !== hash('sha256', $stored)) {
            $file->forceFill(['size' => strlen($stored), 'sha256' => hash('sha256', $stored)])->save();
            // The app keeps a small picture of every cover it has shown; that one is of the old bytes.
            CoverThumbnail::forget($file);
        }

        return $bytes !== null;
    }

    /**
     * Demo lomba of DemoCoverArt::POSTERS (juknis in demo/) that have no cover.
     *
     * @return Collection<int, Competition>
     */
    private function competitionsWithoutCover(): Collection
    {
        return Competition::query()
            ->whereNull('cover_file_id')
            ->whereIn('name', DemoCoverArt::competitions())
            ->whereHas('juknisFile', fn ($q) => $q->where('disk', 'local')->where('path', 'like', self::DIRECTORY.'/%'))
            ->orderBy('id')
            ->get();
    }

    /**
     * Demo reports (every evidence file in demo/) with a research title and no research report file.
     *
     * @return Collection<int, Report>
     */
    private function reportsWithoutResearchFile(): Collection
    {
        return Report::query()
            ->whereNotNull('research_title')
            ->where('research_title', '!=', '')
            ->whereDoesntHave('files', fn ($q) => $q->where('kind', ReportFileKind::LaporanRiset))
            ->with('files.file')
            ->get()
            ->filter(function (Report $report): bool {
                $files = $report->files->map(fn (ReportFile $rf): ?File => $rf->file)->filter();

                return $files->isNotEmpty() && ! $files->contains(fn (File $f): bool => ! str_starts_with($f->path, self::DIRECTORY.'/'));
            })
            ->values();
    }

    /**
     * Demo guru letters without the invitation's number or date (the student letter names none since D-154).
     *
     * @return Collection<int, Letter>
     */
    private function lettersWithoutBasis(): Collection
    {
        return Letter::query()
            ->where('type', LetterType::Guru)
            ->where(fn ($q) => $q->whereNull('basis_number')->orWhere('basis_number', '')->orWhereNull('basis_date'))
            ->whereHas('file', fn ($q) => $q->where('disk', 'local')->where('path', 'like', self::DIRECTORY.'/%'))
            ->with('file')
            ->get()
            ->filter(fn (Letter $letter): bool => DemoLetterBasis::keyOf($letter) !== null)
            ->values();
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
