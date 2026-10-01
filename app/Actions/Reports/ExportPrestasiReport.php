<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Actions\Templates\LetterTemplate;
use App\Actions\Users\AdminRecipients;
use App\Domain\Letters\PdfRenderer;
use App\Domain\Points\PointFormatter;
use App\Enums\Bidang;
use App\Models\Achievement;
use App\Models\User;
use App\Notifications\Admin\LargeExportNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\HeaderFooter;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Kepala Sekolah › Laporan: period report as PDF or XLSX (UI-MAP §4b `kepsek.laporan`, backlog #6, owner B5).
 *
 * - Needs AchievementPolicy::exportSchoolReport (`achievement.view_all` + `dashboard.view` + `achievement.export`).
 * - Watermarked with the exporter's name + username + timestamp: PDF = diagonal text and a footer line on every
 *   page; XLSX = the first rows of every sheet, the print header/footer and the document properties.
 * - Internal document: real names (no consent gate) but never NIS/NISN/phone/email/birth date.
 * - XLSX cells are explicit string/number cells (no formulas, SECURITY §3f); PDF through the locked-down
 *   App\Domain\Letters\PdfRenderer (static template `resources/pdf/laporan-prestasi.blade.php`, {{ }} only).
 * - Audited (`school_report.exported`: format, period, bidang, rows, sha256, watermark); more than
 *   LargeExportNotice::LIMIT rows alert every Admin (SECURITY §3m-9).
 *
 * Returns a temporary file the caller streams and deletes (nothing is kept on disk).
 */
final class ExportPrestasiReport
{
    public const FORMATS = ['pdf', 'xlsx'];

    public function __construct(
        private readonly ExportPrestasiData $data,
        private readonly PdfRenderer $pdf,
        private readonly AdminRecipients $recipients,
        private readonly PointFormatter $points,
    ) {}

    /**
     * @return array{path: string, filename: string, rows: int, mime: string}
     */
    public function handle(User $actor, string $format, ExportPeriod $period, ?Bidang $bidang = null): array
    {
        Gate::forUser($actor)->authorize('exportSchoolReport', Achievement::class);

        if (! in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException('Format laporan tidak dikenal.');
        }

        $rows = $this->data->rows($period, $bidang);
        $summary = $this->data->summary($rows);
        $now = CarbonImmutable::now(config('app.timezone'));
        $watermark = self::watermark($actor, $now);
        $meta = [
            'title' => 'Laporan Prestasi Siswa',
            'period' => $period->label,
            'bidang' => $bidang?->label() ?? 'Semua bidang',
            'watermark' => $watermark,
            'generatedAt' => $now->locale('id')->translatedFormat('j F Y, H.i').' WIB',
            'exporter' => (string) $actor->name,
        ];

        $path = $format === 'pdf'
            ? $this->writePdf($rows, $summary, $meta)
            : $this->writeXlsx($rows, $summary, $meta, $actor);

        $base = 'laporan-prestasi-'.Str::slug($period->key).($bidang !== null ? '-'.$bidang->cssKey() : '').'-'.$now->format('Ymd-Hi');

        activity('export')
            ->causedBy($actor)
            ->event('school_report.exported')
            ->withProperties([
                'format' => $format,
                'period' => $period->toArray(),
                'bidang' => $bidang?->value,
                'rows' => $rows->count(),
                'sha256' => hash_file('sha256', $path),
                'watermark' => $watermark,
            ])
            ->log('school_report.exported');

        if ($rows->count() > LargeExportNotice::LIMIT) {
            Notification::send($this->recipients->admins(), new LargeExportNotice((string) $actor->name, 'laporan prestasi', $rows->count(), $now->translatedFormat('j F Y H:i')));
        }

        return [
            'path' => $path,
            'filename' => $base.'.'.$format,
            'rows' => $rows->count(),
            'mime' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    /** "Diunduh oleh Dra. Sri Wahyuni (kepsek) · 1 Okt 2026 10.15 WIB · dokumen internal" */
    public static function watermark(User $actor, CarbonImmutable $at): string
    {
        $who = (string) $actor->name.($actor->username ? ' ('.$actor->username.')' : '');

        return 'Diunduh oleh '.$who.' · '.$at->locale('id')->translatedFormat('j M Y H.i').' WIB · dokumen internal';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $summary
     * @param  array<string, string>  $meta
     */
    private function writePdf(Collection $rows, array $summary, array $meta): string
    {
        $document = $this->pdf->render('laporan-prestasi', [
            'meta' => $meta,
            'summary' => $summary,
            'rows' => $rows->map(fn (array $r): array => $this->detailRow($r))->all(),
            'fmt' => fn (string $points): string => $this->points->format($points),
            'tpl' => LetterTemplate::current(),
        ]);

        return $this->temp('pdf', $document->bytes);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $summary
     * @param  array<string, string>  $meta
     */
    private function writeXlsx(Collection $rows, array $summary, array $meta, User $actor): string
    {
        $path = $this->temp('xlsx', '');
        $options = new Options;
        $options->setProperties(new Properties(
            title: $meta['title'].' · '.$meta['period'],
            subject: 'SMAGA Talent Hub',
            application: 'SMAGA Talent Hub',
            creator: (string) $actor->name,
            lastModifiedBy: (string) $actor->name,
            description: $meta['watermark'],
            category: 'Dokumen internal',
            language: 'id-ID',
            customProperties: ['Diunduh oleh' => (string) $actor->name, 'Waktu unduh' => $meta['generatedAt']],
        ));
        $mark = str_replace('&', '&&', $meta['watermark']);
        $options->setHeaderFooter(new HeaderFooter(
            oddHeader: '&L&8'.str_replace('&', '&&', $meta['title'].' · '.$meta['period']).'&R&8'.$mark,
            oddFooter: '&L&8'.$mark.'&RHalaman &P dari &N',
        ));

        $writer = new Writer($options);
        $writer->openToFile($path);
        $bold = (new Style)->setFontBold();
        $muted = (new Style)->setFontItalic()->setFontColor('6B7280');

        $this->watermarkRows($writer, $meta, $bold, $muted);
        $writer->getCurrentSheet()->setName('Ringkasan');

        $totals = $summary['totals'];
        $writer->addRow(new Row([ExportCells::string('Jumlah', $bold), ExportCells::string('')]));
        foreach ([
            'Prestasi terverifikasi' => $totals['prestasi'],
            'Siswa berprestasi' => $totals['siswa'],
            'Lomba' => $totals['lomba'],
            'Nasional dan internasional' => $totals['nasional'],
        ] as $label => $value) {
            $writer->addRow(new Row([ExportCells::string($label), ExportCells::number($value)]));
        }
        $writer->addRow(new Row([ExportCells::string('Total poin'), ExportCells::number($totals['points'])]));

        foreach (['bidang' => 'Per bidang', 'tingkat' => 'Per tingkat', 'capaian' => 'Per capaian', 'kelas' => 'Per kelas', 'pembimbing' => 'Per guru pembimbing'] as $key => $title) {
            $writer->addRow(new Row([ExportCells::string('')]));
            $writer->addRow(new Row([ExportCells::string($title, $bold)]));
            $writer->addRow(new Row(array_map(fn (string $h) => ExportCells::string($h, $bold), [$key === 'pembimbing' ? 'Guru pembimbing' : ucfirst($key), 'Prestasi', 'Siswa', 'Poin'])));

            foreach ($summary[$key] as $group) {
                $writer->addRow(new Row([
                    ExportCells::string($group['label']),
                    ExportCells::number($group['prestasi']),
                    ExportCells::number($group['siswa']),
                    ExportCells::number($group['points']),
                ]));
            }
        }

        $writer->addNewSheetAndMakeItCurrent()->setName('Rincian');
        $this->watermarkRows($writer, $meta, $bold, $muted);
        $writer->addRow(new Row(array_map(fn (string $h) => ExportCells::string($h, $bold),
            ['No', 'Nama', 'Kelas', 'Status', 'Lomba', 'Penyelenggara', 'Bidang', 'Tingkat', 'Capaian', 'Poin', 'Tanggal', 'Guru pembimbing'])));

        foreach ($rows->values() as $index => $row) {
            $d = $this->detailRow($row);
            $writer->addRow(new Row([
                ExportCells::number($index + 1),
                ExportCells::string($d['name']),
                ExportCells::string($d['kelas']),
                ExportCells::string($d['status']),
                ExportCells::string($d['lomba']),
                ExportCells::string($d['organizer']),
                ExportCells::string($d['bidang']),
                ExportCells::string($d['tingkat']),
                ExportCells::string($d['capaian']),
                ExportCells::number($row['points']),
                ExportCells::string($row['date']),
                ExportCells::string($d['mentor']),
            ]));
        }

        $writer->close();

        return $path;
    }

    /** @param array<string, string> $meta */
    private function watermarkRows(Writer $writer, array $meta, Style $bold, Style $muted): void
    {
        $writer->addRow(new Row([ExportCells::string($meta['title'].' · '.$meta['period'].' · '.$meta['bidang'], $bold)]));
        $writer->addRow(new Row([ExportCells::string($meta['watermark'], $muted)]));
        $writer->addRow(new Row([ExportCells::string('Data internal sekolah. Jangan dibagikan di luar SMA Negeri 3 Semarang.', $muted)]));
        $writer->addRow(new Row([ExportCells::string('')]));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function detailRow(array $row): array
    {
        return [
            'name' => $row['name'],
            'kelas' => $row['kelas'] !== '' ? $row['kelas'] : '-',
            'status' => match ($row['status']) {
                'alumni' => 'Alumni',
                'nonaktif' => 'Nonaktif',
                default => 'Aktif',
            },
            'lomba' => $row['lomba'],
            'organizer' => $row['organizer'],
            'bidang' => $row['bidang']->label(),
            'tingkat' => $row['level']->label(),
            'capaian' => $row['result']->label(),
            'points' => $this->points->format($row['points']),
            'date' => CarbonImmutable::parse($row['date'])->locale('id')->translatedFormat('j M Y'),
            'mentor' => $row['mentor'] !== '' ? $row['mentor'] : '-',
        ];
    }

    private function temp(string $extension, string $bytes): string
    {
        $base = tempnam(sys_get_temp_dir(), 'laporan');

        if ($base === false) {
            throw new RuntimeException('Berkas laporan tidak bisa dibuat.');
        }

        @unlink($base);
        $path = $base.'.'.$extension;
        file_put_contents($path, $bytes);

        return $path;
    }
}
