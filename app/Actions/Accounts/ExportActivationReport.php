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
 * The Excel files of the students (K9), one implementation for every download of Status aktivasi and of the Kelas page
 * (Admin, Kesiswaan and Tata Usaha). SV6, D-185 moved it here from StatusAktivasi::export(); since 2026-11-05 (the lead:
 * "download semua siswa … harusnya lengkap, ada nama, nisn, dll. … fix-nya ke semua biar konsisten") two kinds:
 * - ActivationReport::KIND_DATA "Data siswa" (the Kelas page): No, Kelas, Nama, NIS, NISN, Tanggal lahir, No HP, Email;
 * - ActivationReport::KIND_STATUS "Status aktivasi" (Status aktivasi): the same columns, then Status, Aktivasi, Login
 *   terakhir, Batas kata sandi awal, Catatan, and for the whole school the sheet "Ringkasan" last.
 *
 * - `student.view` (Kesiswaan, Tata Usaha, Admin; the Kepala Sekolah holds it too but has no screen with a download).
 *   The screens ask for a password from the last 15 minutes before calling this (SECURITY §3m-9; RequiresRecentPassword,
 *   the download resumes by itself after the password page).
 * - The rows are ActivationReport::rows(…, personal: true): one class (`$classroomId`: one sheet named after the class),
 *   else one tingkat (`$level`: one sheet), else the whole school (one sheet per tingkat that has rows); the status and
 *   the name/NISN search of Status aktivasi › Daftar siswa narrow them further. A class wins over a tingkat. Both
 *   screens choose the class or tingkat with ONE chooser: scopeOptions() / scope().
 * - The count comes first: no row = null, no file and no audit row (the screen says MSG_EMPTY in a warning toast).
 * - Audit `account.activation_report_exported` (log `account`): the file kind, the tingkat, the class name, the status
 *   filter, whether a search was used ("ya", never the text), the screen and the row count. No student name or NISN.
 * - More than LargeExportNotice::LIMIT rows: every Admin gets LargeExportNotice (QA10).
 * For a student who has not activated yet the birth date in the file is the key to the initial password (D-173): the
 * file is personal data of the staff who may download it. Read-only: no state changes, so no transaction. The caller
 * streams the file and deletes it (deleteFileAfterSend).
 */
final class ExportActivationReport
{
    public const EVENT = 'account.activation_report_exported';

    public const MSG_EMPTY = 'Tidak ada siswa untuk pilihan ini.';

    /** The screen that asked, for the audit row. */
    public const SOURCE_ACTIVATION = 'aktivasi';

    public const SOURCE_CLASSES = 'kelas';

    /** Chooser value of the whole school; the tingkat values are SCOPE_LEVELS, every other value is a classroom id. */
    public const SCOPE_ALL = '';

    public const SCOPE_LEVELS = ['10' => 'Kelas X', '11' => 'Kelas XI', '12' => 'Kelas XII'];

    /** The option group of the single classes in the chooser. */
    public const SCOPE_CLASS_GROUP = 'Satu kelas';

    public function __construct(
        private readonly ActivationReport $report,
        private readonly AdminRecipients $recipients,
    ) {}

    /**
     * The ONE chooser of every student download (Status aktivasi and the Kelas page offer exactly this list): "Semua
     * kelas", "Kelas X", "Kelas XI", "Kelas XII", then every class that has a student outside the Sampah, in class order.
     *
     * @return array<string, string|array<string, string>> value => label; the classes as one option group
     */
    public static function scopeOptions(): array
    {
        $classes = ActivationReport::classOptions()->map(fn (mixed $name): string => (string) $name)->all();

        return [self::SCOPE_ALL => 'Semua kelas'] + self::SCOPE_LEVELS + ($classes !== [] ? [self::SCOPE_CLASS_GROUP => $classes] : []);
    }

    /**
     * A chooser value as what the file holds, or null for a value the chooser does not offer (now).
     *
     * @return array{classroom: ?string, level: ?int}|null
     */
    public static function scope(string $value): ?array
    {
        if ($value === self::SCOPE_ALL) {
            return ['classroom' => null, 'level' => null];
        }

        if (array_key_exists($value, self::SCOPE_LEVELS)) {
            return ['classroom' => null, 'level' => (int) $value];
        }

        return ActivationReport::classOptions()->has($value) ? ['classroom' => $value, 'level' => null] : null;
    }

    /** The label of a chooser value ("Semua kelas", "Kelas XI", "Kelas X-4"), for the line under the button. */
    public static function scopeLabel(string $value): string
    {
        if ($value === self::SCOPE_ALL) {
            return 'Semua kelas';
        }

        if (array_key_exists($value, self::SCOPE_LEVELS)) {
            return self::SCOPE_LEVELS[$value];
        }

        $name = ActivationReport::classOptions()->get($value);

        return $name !== null ? 'Kelas '.$name : 'Kelas yang dipilih';
    }

    /**
     * @param  ?string  $classroomId  one class (it must exist), or null
     * @param  ?int  $level  one tingkat of ActivationReport::LEVELS, or null for every tingkat (ignored with a class)
     * @param  string  $state  one of AccountControls::STATES, or '' for every status
     * @param  string  $search  the name or exact NISN search of Status aktivasi, or ''
     * @param  string  $kind  ActivationReport::KIND_STATUS or ActivationReport::KIND_DATA
     * @return ExportedFile|null null when no student matches: nothing written, nothing audited
     *
     * @throws InvalidArgumentException for an unknown tingkat, status, class, screen or kind (the screens never send one)
     */
    public function handle(
        User $actor,
        ?string $classroomId = null,
        ?int $level = null,
        string $state = '',
        string $search = '',
        string $source = self::SOURCE_ACTIVATION,
        string $kind = ActivationReport::KIND_STATUS,
    ): ?ExportedFile {
        Gate::forUser($actor)->authorize(Permission::StudentView->value);

        if (! in_array($source, [self::SOURCE_ACTIVATION, self::SOURCE_CLASSES], true)) {
            throw new InvalidArgumentException('Unknown screen.');
        }

        if (! in_array($kind, ActivationReport::KINDS, true)) {
            throw new InvalidArgumentException('Unknown file kind.');
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

        $rows = $this->report->rows($classroomId, $state !== '' ? $state : null, $search, personal: true)
            ->when($level !== null, fn ($c) => $c->where('level', $level))
            ->values();
        $count = $rows->count();

        if ($count === 0) {
            return null;
        }

        $exportedAt = CarbonImmutable::now();
        $tingkat = $level === null ? 'semua' : ActivationReport::LEVELS[$level];
        $status = $kind === ActivationReport::KIND_STATUS;

        $base = (string) tempnam(sys_get_temp_dir(), 'sth');
        @unlink($base);
        $path = $base.'.xlsx';
        $this->report->writeXlsx($rows, $path, $level, $classroom?->name, $kind);

        activity('account')
            ->causedBy($actor)
            ->event(self::EVENT)
            ->withProperties(array_filter([
                'file' => $kind,
                'filters' => array_filter(['status' => $state, 'q' => trim($search) !== '' ? 'ya' : '']),
                'tingkat' => $classroom !== null ? null : $tingkat,
                'kelas' => $classroom?->name,
                'source' => $source,
                'rows' => $count,
            ], fn (mixed $value): bool => $value !== null))
            ->log(self::EVENT);

        // SECURITY §3m-9: a file with the names, classes, birth dates and phone numbers of many students alerts every
        // Admin above LargeExportNotice::LIMIT rows, like the other exports (QA10).
        if ($count > LargeExportNotice::LIMIT) {
            Notification::send($this->recipients->admins(), new LargeExportNotice((string) $actor->name, $status ? 'laporan status aktivasi' : 'data siswa', $count, $exportedAt->translatedFormat('j F Y H:i')));
        }

        $scope = $classroom !== null ? 'kelas-'.Str::slug($classroom->name) : mb_strtolower($tingkat);

        return new ExportedFile($path, ($status ? 'status-aktivasi-' : 'data-siswa-').$scope.'-'.$exportedAt->format('Ymd-His').'.xlsx', $count);
    }
}
