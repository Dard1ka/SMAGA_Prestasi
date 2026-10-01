<?php

declare(strict_types=1);

namespace App\Actions\Exports;

use App\Domain\Archive\SchoolYear;
use App\Domain\Points\PointFormatter;
use App\Domain\Support\Decimal;
use App\Enums\UserStatus;
use App\Models\Achievement;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Excel export of the Kesiswaan "Database prestasi" (backlog #4, owner B4; SECURITY §3f, §3m-9).
 *
 * - `achievement.export` (AchievementPolicy::export); the screen also asks for a password from the last
 *   15 minutes before calling this.
 * - Same rows as the screen (AchievementFilters), at most MAX_ROWS; no NISN, phone, email or birth date.
 * - Formula injection: every text cell is written as an explicit string cell (OpenSpout would turn a
 *   leading "=" into a formula) AND values starting with = + - @ tab CR LF get a leading apostrophe
 *   (OWASP), so a re-saved CSV stays safe too.
 * - Watermark rows "Diekspor oleh {nama} {waktu}" + the active filters on top; audited as
 *   `achievement.exported` (filters, row count, `large` when > 500 rows, SECURITY §3m alert threshold).
 * Read-only: no state changes, so no transaction. Points are written as Excel numbers (display only;
 * every sum in the app stays bcmath).
 */
final class ExportAchievements
{
    public const MAX_ROWS = 10000;

    public const LARGE_EXPORT = 500;

    /** @var list<string> leading characters a spreadsheet may read as a formula */
    public const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r", "\n"];

    private const HEADER = [
        'No', 'Nama', 'NIS', 'Kelas', 'Angkatan', 'Status akun', 'Lomba', 'Penyelenggara', 'Jenis penyelenggara',
        'Kategori', 'Status ajang', 'Bidang', 'Tingkat', 'Capaian', 'Peserta', 'Pembimbing', 'Tahun ajaran',
        'Tanggal final', 'Bobot tingkat', 'Bobot capaian', 'Bobot penyelenggara', 'Poin', 'Diverifikasi', 'Verifikator',
    ];

    public function __construct(private readonly PointFormatter $formatter = new PointFormatter) {}

    /** @throws ValidationException (key `export`) when the filtered list is larger than MAX_ROWS */
    public function handle(User $actor, AchievementFilters $filters): ExportedFile
    {
        Gate::forUser($actor)->authorize('export', Achievement::class);

        $query = $filters->apply(Achievement::query());
        $count = (clone $query)->count();

        if ($count > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'export' => 'Terlalu banyak baris ('.number_format($count, 0, ',', '.').'). Persempit filter, maks. '
                    .number_format(self::MAX_ROWS, 0, ',', '.').' baris.',
            ]);
        }

        $exportedAt = CarbonImmutable::now();
        $base = (string) tempnam(sys_get_temp_dir(), 'prestasi');
        @unlink($base);
        $path = $base.'.xlsx';

        $writer = SimpleExcelWriter::create($path)->noHeaderRow();
        $bold = (new Style)->setFontBold();
        $describe = $filters->describe();

        $writer->addRow(self::textRow(['Database prestasi SMAGA Talent Hub'], $bold));
        $writer->addRow(self::textRow(['Diekspor oleh '.$actor->name.' · '.$exportedAt->format('d-m-Y H.i').' WIB']));
        $writer->addRow(self::textRow(['Filter: '.($describe === [] ? 'semua prestasi terverifikasi' : implode('; ', $describe))]));
        $writer->addRow(self::textRow(['']));
        $writer->addRow(self::textRow(self::HEADER, $bold));

        $number = 0;
        $total = '0.00';

        $query->with([
            'student' => fn ($s) => $s->withTrashed(),
            'student.user' => fn ($u) => $u->withTrashed(),
            'student.classroom',
            'competition',
            'report.registration.mentor',
            'report.verifier',
        ])
            ->orderByDesc('achievements.verified_at')
            ->orderBy('achievements.id')
            ->lazy(500)
            ->each(function (Achievement $a) use ($writer, &$number, &$total): void {
                $number++;
                $points = Decimal::roundHalfUp((string) $a->points, 2);
                $total = bcadd($total, $points, 2);
                $writer->addRow($this->dataRow($number, $a, $points));
            });

        $writer->addRow(new Row(array_merge(
            [new StringCell('', null), new StringCell('Total poin', $bold)],
            array_map(fn (): Cell => new StringCell('', null), range(3, 21)),
            [new NumericCell((float) $total, $bold)],
        )));
        $writer->close();

        activity('achievement')
            ->causedBy($actor)
            ->event('achievement.exported')
            ->withProperties([
                'filters' => $filters->toArray(),
                'rows' => $count,
                'large' => $count > self::LARGE_EXPORT,
                'format' => 'xlsx',
            ])
            ->log('achievement.exported');

        return new ExportedFile($path, 'database-prestasi-'.$exportedAt->format('Ymd-Hi').'.xlsx', $count);
    }

    /** Text for one spreadsheet cell; a value that could start a formula gets a leading apostrophe. */
    public static function cell(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], self::FORMULA_PREFIXES, true) ? "'".$value : $value;
    }

    private function dataRow(int $number, Achievement $a, string $points): Row
    {
        $student = $a->student;
        $user = $student?->user;
        $competition = $a->competition;
        $registration = $a->report?->registration;
        $finalOn = $competition?->final_on;

        $status = match (true) {
            $user === null || $user->trashed() || $student?->trashed() => 'Dihapus',
            $user->status === UserStatus::Archived => 'Alumni',
            $user->status === UserStatus::Disabled => 'Nonaktif',
            default => 'Aktif',
        };

        $grade12 = $student?->grade12_start_date;

        return new Row([
            new NumericCell($number, null),
            self::text($user?->name),
            self::text($student?->nis),
            self::text($student?->classroom?->name),
            self::text($grade12 !== null ? (string) ($grade12->year - 2) : ''),
            self::text($status),
            self::text($competition?->name),
            self::text($competition?->organizer_name),
            self::text($competition?->organizer_type?->label()),
            self::text($a->weight_category->label()),
            self::text($competition?->event_status?->label()),
            self::text($competition?->bidang?->label()),
            self::text($a->level->label()),
            self::text($a->result->label()),
            self::text($registration?->type?->label()),
            self::text($registration?->mentor?->name),
            self::text(self::schoolYear($finalOn ?? $a->verified_at)),
            self::text($finalOn?->format('Y-m-d')),
            self::text($this->formatter->format((string) $a->w_level, 2)),
            self::text($this->formatter->formatWeight((string) $a->w_result)),
            self::text($this->formatter->formatWeight((string) $a->w_organizer)),
            new NumericCell((float) $points, null),
            self::text($a->verified_at->format('Y-m-d')),
            self::text($a->report?->verifier?->name),
        ]);
    }

    /** @param list<string> $values */
    private static function textRow(array $values, ?Style $style = null): Row
    {
        return new Row(array_map(fn (string $value): Cell => self::text($value, $style), $values));
    }

    private static function text(?string $value, ?Style $style = null): StringCell
    {
        return new StringCell(self::cell($value), $style);
    }

    private static function schoolYear(?DateTimeInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        try {
            return SchoolYear::containing($date)->toString();
        } catch (InvalidArgumentException) {
            return '';
        }
    }
}
