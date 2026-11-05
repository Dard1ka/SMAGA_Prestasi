<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Actions\Exports\ExportedFile;
use App\Actions\Users\AdminRecipients;
use App\Enums\Permission;
use App\Models\Classroom;
use App\Models\User;
use App\Notifications\Admin\LargeExportNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The Excel file of the activation report (K9), SV6, D-185 (the lead, 5 Nov 2026: "bisa ngedownload list siswa termasuk
 * statusnya mereka", the file "masih kosong saja tanpa ada siswanya"). Moved here from StatusAktivasi::export() so
 * Status aktivasi (both tabs and its class dialog) and Admin › Kelas ("Download semua kelas", "Download Excel" in Lihat
 * siswa) write the same file.
 *
 * - `student.view` (Kesiswaan, Admin). The screens ask for a password from the last 15 minutes before calling this
 *   (SECURITY §3m-9; RequiresRecentPassword, the download resumes by itself after the password page).
 * - The rows are ActivationReport::rows(): one class (`$classroomId`: one sheet named after the class), else one tingkat
 *   (`$level`: one sheet), else the whole school (sheet "Ringkasan" first, then the tingkat sheets that have rows); the
 *   status and the name/NISN search of Status aktivasi › Daftar siswa narrow them further. A class wins over a tingkat.
 * - The count comes first: no row = null, no file and no audit row (the screen says MSG_EMPTY in a warning toast).
 * - Audit `account.activation_report_exported` (log `account`): the tingkat, the class name, the status filter, whether
 *   a search was used ("ya", never the text), the screen and the row count. No student name or NISN.
 * - More than LargeExportNotice::LIMIT rows: every Admin gets LargeExportNotice (QA10).
 * Read-only: no state changes, so no transaction. The caller streams the file and deletes it (deleteFileAfterSend).
 */
final class ExportActivationReport
{
    public const EVENT = 'account.activation_report_exported';

    public const MSG_EMPTY = 'Tidak ada siswa untuk pilihan ini.';

    /** The screen that asked, for the audit row. */
    public const SOURCE_ACTIVATION = 'aktivasi';

    public const SOURCE_CLASSES = 'kelas';

    public function __construct(
        private readonly ActivationReport $report,
        private readonly AdminRecipients $recipients,
    ) {}

    /**
     * @param  ?string  $classroomId  one class (it must exist), or null
     * @param  ?int  $level  one tingkat of ActivationReport::LEVELS, or null for every tingkat (ignored with a class)
     * @param  string  $state  one of AccountControls::STATES, or '' for every status
     * @param  string  $search  the name or exact NISN search of Status aktivasi, or ''
     * @return ExportedFile|null null when no student matches: nothing written, nothing audited
     *
     * @throws InvalidArgumentException for an unknown tingkat, status, class or screen (the screens never send one)
     */
    public function handle(
        User $actor,
        ?string $classroomId = null,
        ?int $level = null,
        string $state = '',
        string $search = '',
        string $source = self::SOURCE_ACTIVATION,
    ): ?ExportedFile {
        Gate::forUser($actor)->authorize(Permission::StudentView->value);

        if (! in_array($source, [self::SOURCE_ACTIVATION, self::SOURCE_CLASSES], true)) {
            throw new InvalidArgumentException('Unknown screen.');
        }

        if ($state !== '' && ! array_key_exists($state, AccountControls::STATES)) {
            throw new InvalidArgumentException('Unknown status.');
        }

        $classroom = null;

        if ($classroomId !== null) {
            $classroom = Classroom::query()->find($classroomId, ['id', 'name', 'level']);

            if ($classroom === null) {
                throw new InvalidArgumentException('Unknown class.');
            }

            $level = null;
        } elseif ($level !== null && ! array_key_exists($level, ActivationReport::LEVELS)) {
            throw new InvalidArgumentException('Unknown tingkat.');
        }

        $rows = $this->report->rows($classroomId, $state !== '' ? $state : null, $search)
            ->when($level !== null, fn ($c) => $c->where('level', $level))
            ->values();
        $count = $rows->count();

        if ($count === 0) {
            return null;
        }

        $exportedAt = CarbonImmutable::now();
        $tingkat = $level === null ? 'semua' : ActivationReport::LEVELS[$level];

        $base = (string) tempnam(sys_get_temp_dir(), 'sth');
        @unlink($base);
        $path = $base.'.xlsx';
        $this->report->writeXlsx($rows, $path, $level, $classroom?->name);

        activity('account')
            ->causedBy($actor)
            ->event(self::EVENT)
            ->withProperties(array_filter([
                'filters' => array_filter(['status' => $state, 'q' => trim($search) !== '' ? 'ya' : '']),
                'tingkat' => $classroom !== null ? null : $tingkat,
                'kelas' => $classroom?->name,
                'source' => $source,
                'rows' => $count,
            ], fn (mixed $value): bool => $value !== null))
            ->log(self::EVENT);

        // SECURITY §3m-9: the file of the whole school (names, classes, account states) alerts every Admin above
        // LargeExportNotice::LIMIT rows, like the other exports (QA10).
        if ($count > LargeExportNotice::LIMIT) {
            Notification::send($this->recipients->admins(), new LargeExportNotice((string) $actor->name, 'laporan status aktivasi', $count, $exportedAt->translatedFormat('j F Y H:i')));
        }

        $scope = $classroom !== null ? 'kelas-'.Str::slug($classroom->name) : mb_strtolower($tingkat);

        return new ExportedFile($path, 'status-aktivasi-'.$scope.'-'.$exportedAt->format('Ymd-His').'.xlsx', $count);
    }
}
