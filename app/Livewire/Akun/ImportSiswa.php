<?php

declare(strict_types=1);

namespace App\Livewire\Akun;

use App\Actions\Accounts\ImportStudents;
use App\Actions\Accounts\StudentImportReport;
use App\Domain\Archive\GraduationCalculator;
use App\Domain\Archive\SchoolYear;
use App\Domain\Files\InvalidUpload;
use App\Domain\Files\StoreUpload;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\StudentImportStatus;
use App\Jobs\ImportStudentsJob;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Livewire\Kesiswaan\Concerns\WithKesiswaanBadges;
use App\Models\StudentImport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Import siswa (UI-MAP #23; `kesiswaan.import` and `admin.import`, D-26). Three steps: download the
 * template (generated on the fly), upload + choose the tahun ajaran → dry run with ImportStudents,
 * review valid/invalid rows, then import. The preview rows stay server-side in the cache (keyed per user),
 * never in the Livewire payload. The initial password pattern (D-24) is never shown or described.
 * B2 (backlog #19): more than ImportStudentsJob::SYNC_MAX_ROWS rows to write → the commit is queued (StudentImport row,
 * progress polled with wire:poll); rows whose cells lost a leading = + - @ show "Sel dibersihkan".
 * M1-C (lead, 2026-10-04): the file lists every student with the current class ("Kelas"). The preview sums up
 * baru · pindah kelas · tidak berubah · ditolak; a registered student only moves class (ImportStudents), the
 * account and its password are never touched or shown.
 * QA9 (SECURITY §3m-9): the import itself needs a password confirmation from the last 15 minutes (Kesiswaan and
 * Admin); the password page brings the user back here, and the file is chosen again.
 * D-128 (lead, 2026-10-10): "Impor N siswa" asks in the app's own dialog (IMPORT_DIALOG), not in the browser's
 * confirm(). askImport() runs the checks of the import first and opens it; import() is the confirm step and does
 * nothing without that question.
 * SV1 second browser check (2026-10-31): a queued import that is over is forgotten when the next file is read (its
 * "Impor file lain" hid "Impor N siswa" of that file); while one runs, the next file waits (IMPORT_RUNNING). The
 * tiles take their columns from the width of the step-3 column and the row status stands under the name. An empty
 * file says so; a cleaned cell is a row note; a running import never says "Menunggu giliran" or "0 akun siswa
 * dibuat" before its first slice is counted.
 * SV1 review (2026-11-01): a file read while an import of this person runs is read again once that import is over,
 * before its dialog opens (`previewWhileImporting`, PREVIEW_RECHECKED); the result of a queued import names only what
 * the job counted (new accounts, class moves), never a number from the preview; step 2 asks for the password also
 * while an import runs, so a file chosen to wait is not lost on the password page; an import started in another tab
 * takes over the banner when this screen is refused (importRunning()).
 * SV1 third check (2026-11-02): two staff importing the same new students at once: ImportStudents leaves a row registered
 * meanwhile to the other import, the result names those rows (TAKEN_BY_OTHER_IMPORT), and a unique index the import still
 * met reads the file again (IMPORT_COLLIDED) instead of Livewire's 500. A finished queued import above a file it did not
 * import names its own file and says that the file on screen is not imported yet.
 * SV4 (lead, 2026-11-04): "Tahun ajaran data" ends with "Tahun lain…" (OTHER_YEAR): a start year typed under it
 * ("Tahun mulai", OTHER_YEAR_SPAN years around the current school year) gives "YYYY/YYYY+1". effectiveSchoolYear() is
 * the one year the dry run, the import and the queued import use; a typed year that is not complete or refused drops
 * the preview of the previous year and keeps the chosen file, which is read as soon as the year is valid.
 */
#[Title('Import data siswa')]
#[Layout('layouts::app', ['subtitle' => 'Akun siswa dibuat otomatis dari file Excel.'])]
class ImportSiswa extends Component
{
    use RequiresRecentPassword;
    use WithFileUploads;
    use WithKesiswaanBadges;

    public const PREVIEW_ROWS = 50;

    /** id of the "Impor data siswa?" confirm dialog (<x-sth.modal>). */
    public const IMPORT_DIALOG = 'impor-siswa';

    /** One import per person at a time (B2, #19): the refusal while a queued import of that person still runs. */
    public const IMPORT_RUNNING = 'Masih ada impor yang berjalan. Tunggu sampai selesai.';

    /** Step 3 for a file with a header row and no student under it (the untouched template, a wrong sheet). */
    public const EMPTY_FILE = 'File ini tidak berisi data siswa. Isi template, lalu upload lagi.';

    /**
     * The click on "Impor N siswa" for a file read while an import of this person ran, once that import is over: the
     * file was read again (the preview had counted the database before that import wrote its rows).
     */
    public const PREVIEW_RECHECKED = 'Impor sebelumnya sudah berakhir. File ini sudah diperiksa ulang; cek angkanya sebelum mengimpor.';

    /**
     * The title of a finished queued import that created no account and moved nobody. ImportStudentsJob counts only
     * those two kinds, so the corrections it may have written are not named with a number (no prediction of the
     * preview as a result). One clause without a final period, like every other result title (resultLabel(),
     * NOTHING_CHANGED).
     */
    public const QUEUED_NOTHING_COUNTED = 'Impor selesai tanpa siswa baru atau pindah kelas';

    /**
     * The click on "Impor N siswa" when the write still met the unique index of a NIS or NISN another import registered
     * at the same moment (SV1 third check; ImportStudents normally leaves such a row out): nothing was written, the file
     * was read again.
     */
    public const IMPORT_COLLIDED = 'Impor lain baru saja menulis sebagian siswa di file ini. File ini sudah diperiksa ulang; cek angkanya sebelum mengimpor.';

    /**
     * After a synchronous import, the new rows it did not create because another import registered their NIS or NISN
     * meanwhile (":n" = the number). ImportStudents counts only what it wrote.
     */
    public const TAKEN_BY_OTHER_IMPORT = ':n siswa tidak dibuat karena NIS atau NISN-nya baru saja didaftarkan oleh impor lain.';

    /** The line under a result that created students (sync and queued). */
    public const NEW_STUDENTS_LOGIN = 'Siswa baru bisa masuk dengan NISN dan kata sandi awal dari sekolah.';

    /**
     * D-173 (smaga_server): the rule of the initial password under the preview, with a fictional example only (never a
     * student's own value, SECURITY K12).
     */
    public const INITIAL_PASSWORD_NOTE = 'Kata sandi awal dibuat hanya untuk siswa baru, dari tanggal lahirnya: SMAGA_ diikuti tanggal lahir ddmmyyyy (01-02-2010 menjadi SMAGA_01022010), dan wajib diganti saat aktivasi. Kata sandi siswa lama tidak berubah.';

    /** The last choice of "Tahun ajaran data": the school year starts in the year typed under it ("Tahun mulai"). */
    public const OTHER_YEAR = 'lain';

    /** "Tahun mulai" accepts the start year of the current school year minus and plus this many years. */
    public const OTHER_YEAR_SPAN = 10;

    /** Address keys that carry the year choice across the password page (SV4, returnUrl()). */
    public const YEAR_QUERY = 'tahun';

    public const CUSTOM_YEAR_QUERY = 'tahun_mulai';

    /**
     * The click on "Impor N siswa" when the preview on screen was read with another school year than the one chosen
     * now: the file was read again with the chosen year.
     */
    public const PREVIEW_YEAR_RECHECKED = 'Tahun ajaran berubah. File ini sudah diperiksa ulang dengan tahun ajaran yang dipilih; cek angkanya sebelum mengimpor.';

    /** The result of an import that wrote nothing (the same words as the dialog for that case). */
    private const NOTHING_CHANGED = 'Tidak ada data yang berubah';

    private const CACHE_MINUTES = 30;

    /**
     * What happens to a row (StudentImportReport::kindOf()) => [label, chip tone], in the preview and in "Download baris
     * yang perlu dicek". The tiles count the same kinds, so a row and its tile always carry the same name.
     */
    private const KIND_LABELS = [
        StudentImportReport::NEW => ['Baru', 'green'],
        StudentImportReport::MOVED => ['Pindah kelas', 'blue'],
        // A student who stays in the class but gets the archive dates or the No HP of the file (SV1 follow-up).
        StudentImportReport::CORRECTED => ['Data diperbarui', 'blue'],
        StudentImportReport::UNCHANGED => ['Tidak berubah', 'gray'],
        StudentImportReport::SKIPPED => ['Dilewati', 'gray'],
        StudentImportReport::REJECTED => ['Ditolak', 'red'],
    ];

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    /** A listed school year "YYYY/YYYY", or OTHER_YEAR for the year typed in `customYear`. */
    public string $schoolYear = '';

    /**
     * "Tahun mulai" under "Tahun lain…": kept exactly as typed (never trimmed and written back while it is typed, SV1);
     * it is trimmed where it is read (customYearProblem(), effectiveSchoolYear()).
     */
    public string $customYear = '';

    /** all | bad (ditolak) | notes (rows with a note) */
    public string $filter = 'all';

    #[Locked]
    public ?string $previewToken = null;

    /** The preview (`previewToken`) the open "Impor data siswa?" dialog asks about; set only by askImport(). */
    #[Locked]
    public ?string $importAsked = null;

    /**
     * Summary after the real import; `taken` only when new rows were left to another import (TAKEN_BY_OTHER_IMPORT).
     *
     * @var array{created: int, moved: int, corrected: int, invalid: int, taken?: int}|null
     */
    #[Locked]
    public ?array $imported = null;

    /** B2 (#19): the queued import (StudentImport id) whose progress is polled. */
    #[Locked]
    public ?string $importId = null;

    /**
     * The preview on screen was read while an import of this person was queued or running (or such an import refused
     * it), so it counted the database before that import wrote its rows. Once no import runs any more, askImport() (and
     * import()) read the file again before anything is asked or written. SV1 review: the dialog said "Buat 312 akun
     * siswa." and the result was "Tidak ada data yang berubah".
     */
    #[Locked]
    public bool $previewWhileImporting = false;

    /**
     * The preview (`previewToken`) the queued import `importId` started from. Its rows stay under the banner and it
     * offers "Impor file lain", as a preview with `imported` does; a file read while that import runs offers its own
     * "Impor N siswa", which waits until the import is over.
     */
    #[Locked]
    public ?string $queuedPreview = null;

    /** QA9: page to come back to after the password confirmation (kesiswaan.import or admin.import). */
    #[Locked]
    public string $returnTo = '';

    public function mount(): void
    {
        $this->authorize(Permission::StudentImport->value);
        $this->schoolYear = SchoolYear::containing(CarbonImmutable::now())->toString();
        $this->restoreYearChoice();
        $this->importId = StudentImport::query()->activeFor($this->actor())->latest()->value('id');
        $this->returnTo = request()->routeIs('admin.import') || (! request()->routeIs('kesiswaan.import') && $this->actor()->hasRole(RoleName::Admin->value))
            ? route('admin.import')
            : route('kesiswaan.import');
    }

    /**
     * Step 2 asks for the password BEFORE the upload (QA9): the password page does not keep an uploaded file or its
     * preview, so asking only when "Impor" is pressed made the user upload the file twice. import() still checks.
     */
    public function askPassword(): void
    {
        $this->passwordRecentlyConfirmed();
    }

    public function downloadTemplate(): BinaryFileResponse
    {
        $this->authorize(Permission::StudentImport->value);

        $path = self::tempPath('xlsx');
        $writer = SimpleExcelWriter::create($path);

        // SV3 (lead, 2026-11-03): every column opens wide enough for its header and the example, no dragging needed.
        foreach (ImportStudents::TEMPLATE_WIDTHS as $i => $width) {
            $writer->getWriter()->getCurrentSheet()->setColumnWidth($width, $i + 1);
        }

        $writer->addHeader(ImportStudents::TEMPLATE_HEADER);

        // A General cell turns 0098765432 into 98765432 and 0812… into 812… (QA Q1-21, backlog #66), and a typed
        // 01-02-2010 into 2 Jan 2010 on a PC with a US date setting (SV2 review: the birth date is the initial password
        // since D-173; ImportStudents::TEMPLATE_TEXT_COLUMNS). OpenSpout cannot
        // give a whole column a format, so every row up to the import limit gets an empty Text cell there (an empty
        // string: OpenSpout leaves out rows of only EmptyCells). Such rows are no data: the import skips them.
        $text = (new Style)->setFormat('@');

        // SV3: one grey italic example row (fictional); typing into a cell replaces it, and a row left as it is is
        // skipped by the import (ImportStudents::isTemplateExample()).
        $example = (new Style)->setFontItalic()->setFontColor('808080');
        $exampleText = (new Style)->setFontItalic()->setFontColor('808080')->setFormat('@');
        $writer->addRow(new Row(array_map(
            fn (string $column, string $value): Cell => new StringCell(
                $value,
                in_array($column, ImportStudents::TEMPLATE_TEXT_COLUMNS, true) ? $exampleText : $example,
            ),
            ImportStudents::TEMPLATE_HEADER,
            ImportStudents::TEMPLATE_EXAMPLE,
        )));

        for ($i = 1; $i < ImportStudents::MAX_ROWS; $i++) {
            $writer->addRow(new Row(array_map(
                fn (string $column): Cell => in_array($column, ImportStudents::TEMPLATE_TEXT_COLUMNS, true)
                    ? new StringCell('', $text)
                    : new EmptyCell(null, null),
                ImportStudents::TEMPLATE_HEADER,
            )));
        }

        $writer->close();

        return response()->download($path, 'template-import-siswa.xlsx')->deleteFileAfterSend();
    }

    /** Runs as soon as a file is chosen: dry run → preview. */
    public function updatedFile(ImportStudents $import): void
    {
        $this->check($import);
    }

    public function updatedSchoolYear(ImportStudents $import): void
    {
        $this->yearChanged($import, typed: false);
    }

    /** "Tahun mulai" (debounced in the view): only while "Tahun lain…" is chosen. */
    public function updatedCustomYear(ImportStudents $import): void
    {
        if ($this->schoolYear === self::OTHER_YEAR) {
            $this->yearChanged($import, typed: true);
        }
    }

    /**
     * The school year changed: a listed year, "Tahun lain…" or the typed start year. A complete year reads the chosen
     * file again (as before for a listed year). Without one ("Tahun lain…" with an empty, incomplete or refused start
     * year) the preview and the result of the previous year go and the file stays chosen; the reason stands under
     * "Tahun mulai" once something was typed there (a fresh "Tahun lain…" opens without a red line).
     */
    private function yearChanged(ImportStudents $import, bool $typed): void
    {
        $this->resetErrorBag('customYear');

        if ($this->effectiveSchoolYear() !== null) {
            if ($this->file !== null) {
                $this->check($import);
            }

            return;
        }

        if ($typed || trim($this->customYear) !== '') {
            $this->addError('customYear', (string) $this->customYearProblem());
        }

        if ($this->previewToken !== null || $this->imported !== null) {
            $this->imported = null;
            $this->forgetFinishedImport();
            $this->forgetPreview();
        }
    }

    /**
     * A new dry run: the preview and the result of an earlier import go, a queued import that is over goes with them
     * (SV1 second check: the finished import kept "Impor file lain" in place of "Impor N siswa" for the next file).
     */
    public function check(ImportStudents $import): void
    {
        $this->imported = null;
        $this->forgetFinishedImport();
        $this->readFile($import);
    }

    /**
     * The dry run of the chosen file with effectiveSchoolYear(): the old preview goes, the new one is cached. False, with
     * the reason on the field and the file dropped, when the file or the school year is refused; false with the reason
     * under "Tahun mulai" and the file kept when "Tahun lain…" has no valid start year yet.
     */
    private function readFile(ImportStudents $import): bool
    {
        $this->resetErrorBag();
        $this->forgetPreview();
        // An import of this person that ends while the dry run reads the database may have written part of its rows
        // before or during that read: checked before and after it (SV1 review).
        $importingBefore = StudentImport::query()->activeFor($this->actor())->exists();

        try {
            $this->validate(
                ['file' => ['required', 'file', 'max:2048', 'extensions:xlsx,csv'], 'schoolYear' => ['required', 'string']],
                [
                    'file.required' => 'Pilih file .xlsx atau .csv.',
                    'file.max' => 'Ukuran file maksimal 2 MB.',
                    'file.extensions' => 'Pilih file .xlsx atau .csv.',
                    'schoolYear.required' => 'Pilih tahun ajaran.',
                ],
            );

            $schoolYear = $this->effectiveSchoolYear();

            if ($schoolYear === null) {
                // "Tahun lain…" without a valid start year: nothing is read, the file waits for the year.
                $this->addError('customYear', (string) $this->customYearProblem());

                return false;
            }

            $report = $import->handle($this->actor(), (string) $this->file->getRealPath(), $this->extension(), $schoolYear, dryRun: true);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
            $this->reset('file');

            return false;
        }

        $this->previewToken = (string) Str::ulid();
        Cache::put($this->cacheKey(), [
            'rows' => $report->rows,
            'fileName' => $this->file->getClientOriginalName(),
            'schoolYear' => $report->schoolYear,
        ], now()->addMinutes(self::CACHE_MINUTES));
        $this->filter = 'all';
        // Read next to a queued or running import: these counts hold only until that import writes its rows.
        $this->previewWhileImporting = $importingBefore || StudentImport::query()->activeFor($this->actor())->exists();

        return true;
    }

    /**
     * A preview read while an import of this person ran (`previewWhileImporting`), once no import runs any more: the
     * file (still the temporary upload) is read again and the click stops with a warning toast, so the tiles, the
     * button, the dialog and the choice between the synchronous and the queued way count the database as it is now.
     * True when it read again (the caller stops); the finished import keeps its banner until the next import.
     */
    private function recheckedAfterImport(ImportStudents $import): bool
    {
        if (! $this->previewWhileImporting) {
            return false;
        }

        $this->previewWhileImporting = false;

        if ($this->readFile($import)) {
            $this->dispatch('sth-toast', message: self::PREVIEW_RECHECKED, tone: 'warning');
        }

        return true;
    }

    /**
     * Defensive: the preview on screen was read with another school year than effectiveSchoolYear() (the year hooks
     * normally read the file again at once). The file is read again with the chosen year and the click stops with a
     * warning toast, so nothing is asked or written for a year nobody saw. True when it read again (the caller stops).
     */
    private function recheckedForYear(ImportStudents $import): bool
    {
        if (($this->preview()['schoolYear'] ?? null) === $this->effectiveSchoolYear()) {
            return false;
        }

        if ($this->readFile($import)) {
            $this->dispatch('sth-toast', message: self::PREVIEW_YEAR_RECHECKED, tone: 'warning');
        }

        return true;
    }

    /**
     * "Impor N siswa" under the preview (D-128): runs the checks of the import, then opens the dialog that says what
     * will be written. Nothing is written before import(). The password is asked here, before the question, so the
     * answer in the dialog is not followed by the password page (which does not keep the file).
     */
    public function askImport(ImportStudents $import): void
    {
        $this->authorize(Permission::StudentImport->value);
        $this->importAsked = null;
        $this->resetErrorBag('file');

        // A running import is refused before the password page, which would drop the file for nothing; a preview read
        // while one ran is read again first (it writes nothing), so the dialog never asks about counts gone stale.
        if ($this->previewToImport() === null
            || $this->importRunning()
            || $this->recheckedAfterImport($import)
            || $this->recheckedForYear($import)
            || ! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $this->importAsked = $this->previewToken;
        $this->dispatch('sth-modal-open', id: self::IMPORT_DIALOG);
    }

    /**
     * "Impor N siswa" in the dialog. Without a dialog opened by askImport() for the preview on screen there is nothing
     * to confirm: it returns without a word (never an error), so a second click after the import changes nothing.
     */
    public function import(ImportStudents $import): void
    {
        $asked = $this->importAsked !== null && $this->importAsked === $this->previewToken;
        $this->importAsked = null;
        $this->dispatch('sth-modal-close', id: self::IMPORT_DIALOG);

        if (! $asked) {
            return;
        }

        $preview = $this->previewToImport();

        if ($preview === null) {
            return;
        }

        // One import per person at a time: never a second one, queued or synchronous, next to a queued one (the rows
        // of both would be written at once), e.g. one started in another tab after the question. queueImport() checks
        // again right before it queues.
        if ($this->importRunning()) {
            return;
        }

        // Defensive (askImport() already read such a file again before it asked): never write from counts taken
        // before another import wrote its rows.
        if ($this->recheckedAfterImport($import) || $this->recheckedForYear($import)) {
            return;
        }

        // QA9 (SECURITY §3m-9): an import writes accounts, so it needs a password confirmation from the last 15
        // minutes, before both the synchronous and the queued way. The preview above writes nothing.
        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        // A queued import that finished while this file was on screen gives way: its banner would stand above this result.
        $this->forgetFinishedImport();

        // B2 (#19): many rows to write → queued job with progress (password hashing would outlast the request timeout).
        if (StudentImportReport::countChanges($preview['rows']) > ImportStudentsJob::SYNC_MAX_ROWS) {
            $this->queueImport(app(StoreUpload::class));

            return;
        }

        try {
            // The year the preview was read with (recheckedForYear() made sure it is the chosen one).
            $report = $import->handle($this->actor(), (string) $this->file->getRealPath(), $this->extension(), $preview['schoolYear'], dryRun: false);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        } catch (UniqueConstraintViolationException) {
            // Second line (SV1 third check): ImportStudents leaves a row that another import registered meanwhile out,
            // so this is only reached by a case it could not foresee. Its transaction rolled back, nothing was written:
            // the file is read again (the dialog is closed above), never Livewire's error page.
            if ($this->readFile($import)) {
                $this->dispatch('sth-toast', message: self::IMPORT_COLLIDED, tone: 'warning');
            }

            return;
        }

        // The report rows are the rows the commit checked: every correction among them was written with it. A new row
        // another import registered meanwhile is reported as skipped with ImportStudents::NOTE_TAKEN ($report->taken).
        // They replace the preview under the result, so no row the commit left out still reads "Baru" (SV1 re-check).
        $kinds = StudentImportReport::changeKinds($report->rows);
        $corrected = $kinds['corrected'];
        $taken = $report->taken;
        $cached = Cache::get($this->cacheKey());

        if (is_array($cached)) {
            Cache::put($this->cacheKey(), ['rows' => $report->rows] + $cached, now()->addMinutes(self::CACHE_MINUTES));
        }
        $this->imported = ['created' => $report->created, 'moved' => $report->moved, 'corrected' => $corrected, 'invalid' => $report->invalidCount()]
            + ($taken > 0 ? ['taken' => $taken] : []);
        $this->dispatch('sth-toast', message: (self::resultLabel($report->created, $report->moved, $corrected) ?? self::NOTHING_CHANGED).'.', tone: 'success');
    }

    /** "Impor file lain" / "Ganti file": the file and its preview go; a queued import keeps its banner while it runs. */
    public function startOver(): void
    {
        $this->forgetPreview();
        $this->reset(['file', 'imported', 'filter', 'queuedPreview']);
        $this->forgetFinishedImport();
        $this->resetErrorBag();
    }

    /**
     * B2 (#19): keep the spreadsheet privately (StoreUpload::storeSpreadsheet), record a StudentImport and queue
     * ImportStudentsJob; render() then shows its progress (wire:poll). One active import per person at a time.
     */
    private function queueImport(StoreUpload $store): void
    {
        $actor = $this->actor();
        $this->authorize('create', StudentImport::class);

        if ($this->importRunning()) {
            return;
        }

        try {
            $file = $store->storeSpreadsheet($this->file, $actor, ImportStudents::MAX_BYTES);
        } catch (InvalidUpload $e) {
            $this->addError('file', $e->getMessage());

            return;
        }

        $import = new StudentImport([
            'user_id' => $actor->getKey(),
            'extension' => $this->extension(),
            // The year the preview was read with, which is effectiveSchoolYear() (recheckedForYear()); never the select's
            // raw value, which is OTHER_YEAR for a typed year.
            'school_year' => $this->preview()['schoolYear'] ?? (string) $this->effectiveSchoolYear(),
            'file_name' => $file->original_name,
            'file_sha256' => $file->sha256,
        ]);
        $import->forceFill(['status' => StudentImportStatus::Queued, 'file_id' => $file->getKey()])->save();

        $this->importId = $import->getKey();
        $this->queuedPreview = $this->previewToken;
        ImportStudentsJob::dispatch($import->getKey());

        $this->dispatch('sth-toast', message: 'Impor berjalan di latar belakang. Kemajuannya tampil di sini.', tone: 'info');
    }

    /**
     * "Download baris yang perlu dicek": the refused rows and the rows with a note: row number, name, NISN, class,
     * what happens to the row (the label of the preview, KIND_LABELS) and why (formula-safe). Never a birth date, a
     * phone number or a password.
     */
    public function downloadInvalid(): ?BinaryFileResponse
    {
        $this->authorize(Permission::StudentImport->value);
        $preview = $this->preview();

        if ($preview === null) {
            return null;
        }

        $path = self::tempPath('xlsx');
        $writer = SimpleExcelWriter::create($path);

        foreach ($preview['rows'] as $row) {
            if ($row['errors'] === [] && ($row['notes'] ?? []) === []) {
                continue;
            }

            $writer->addRow([
                'Baris' => $row['row'],
                'Nama lengkap' => self::cell($row['name']),
                'NISN' => self::cell($row['nisn'] ?? null),
                'Kelas' => self::cell($row['classroom']),
                'Status' => self::KIND_LABELS[StudentImportReport::kindOf($row)][0],
                'Keterangan' => self::cell(implode(' ', [...$row['errors'], ...($row['notes'] ?? [])])),
            ]);
        }

        $writer->close();

        return response()->download($path, 'baris-perlu-dicek.xlsx')->deleteFileAfterSend();
    }

    public function render(): View
    {
        $preview = $this->preview();
        $rows = $preview['rows'] ?? [];
        $invalid = array_values(array_filter($rows, fn (array $r): bool => $r['errors'] !== []));
        $noted = array_values(array_filter($rows, fn (array $r): bool => ($r['notes'] ?? []) !== []));
        $summary = StudentImportReport::summarize($rows);
        $list = match ($this->filter) {
            'bad' => $invalid,
            'notes' => $noted,
            default => $rows,
        };
        $job = $this->importId !== null ? StudentImport::query()->find($this->importId) : null;
        $job = $job !== null && $this->actor()->can('view', $job) ? $job : null;
        $jobActive = $job?->isActive() ?? false;

        // The refusal of a click while an import ran ends with that import (Livewire would keep it beside the result).
        if (! $jobActive) {
            $this->forgetRunningRefusal();
        }

        // The preview on screen is the one the queued import started from (its rows stay under the banner).
        $queuedHere = $job !== null && $preview !== null && $this->queuedPreview !== null && $this->queuedPreview === $this->previewToken;
        // This preview was imported, or handed to the queued import: "Impor file lain" instead of "Impor N siswa".
        $previewDone = $this->imported !== null || $queuedHere;
        // A finished queued import above a file it did not import (the file that waited for it, or a file whose click took
        // over an import of another tab): its banner names its own file and says that the file on screen is not imported
        // yet (SV1 third check: "150 akun siswa dibuat" and "Semua baris sudah sesuai dengan file." stood under the header
        // of the waiting file).
        $otherFile = $job !== null && ! $jobActive && $preview !== null && ! $previewDone;
        $step = match (true) {
            $preview !== null || $this->imported !== null || $job !== null => 3,
            default => 2,
        };
        $changeCount = StudentImportReport::countChanges($rows);
        // new + moved + corrected = $changeCount: the button, the dialog and the tiles count the same students.
        $kinds = StudentImportReport::changeKinds($rows);
        $passwordAsked = ! $this->passwordConfirmedRecently();
        // SV4: "Tahun lain…" and the year typed under it.
        $otherYear = $this->schoolYear === self::OTHER_YEAR;
        $typedYear = $otherYear && $this->customYearProblem() === null ? new SchoolYear((int) trim($this->customYear)) : null;
        $chosenName = $preview['fileName'] ?? ($this->file instanceof TemporaryUploadedFile ? $this->file->getClientOriginalName() : null);
        // A chosen file that waits for the typed start year: no preview was read yet.
        $yearMissing = $preview === null && $chosenName !== null && $otherYear && $typedYear === null;

        return view('livewire.akun.import-siswa', [
            'step' => $step,
            // The note under step 3: a queued import finishes on its own (SV1: it never said "Selesai"). A file read while
            // a queued import runs is counted itself; the banner above it tells about the import.
            'stepNote' => match (true) {
                $this->imported !== null => 'Selesai',
                $job !== null && ($preview === null || $queuedHere) => $job->status->label(),
                $preview !== null => count($rows).' baris terbaca',
                $yearMissing => 'Menunggu tahun ajaran',
                default => 'Belum ada file',
            },
            'otherYear' => $otherYear,
            'otherYearLabel' => $typedYear !== null ? 'Tahun ajaran '.$typedYear->toString() : null,
            'otherYearExample' => (string) self::customYearExample(),
            'chosenName' => $chosenName,
            'yearMissing' => $yearMissing,
            'preview' => $preview,
            'rowCount' => count($rows),
            'previewDone' => $previewDone,
            // Another file waits while a queued import runs (askImport() refuses it as well, IMPORT_RUNNING).
            'jobActive' => $jobActive,
            'summary' => $summary,
            // Skipped rows that are alumni accounts; a row another import registered meanwhile is skipped too
            // (ImportStudents::NOTE_TAKEN) but is no alumnus (SV1 last check: the losing screen told staff to restore
            // archived accounts that were new ones).
            'alumniCount' => count(array_filter($rows, fn (array $r): bool => StudentImportReport::kindOf($r) === StudentImportReport::SKIPPED
                && ! in_array(ImportStudents::NOTE_TAKEN, $r['notes'] ?? [], true))),
            'changeCount' => $changeCount,
            'kinds' => $kinds,
            // "Tidak berubah": the rows labelled "Tidak berubah" or "Dilewati" (alumni); nothing is written for them (a
            // correction has its own tile and label).
            'unchangedCount' => count(array_filter($rows, fn (array $r): bool => in_array(
                StudentImportReport::kindOf($r),
                [StudentImportReport::UNCHANGED, StudentImportReport::SKIPPED],
                true,
            ))),
            'confirm' => self::confirmText($kinds),
            // The dialog shows its sentence and its confirm button only for the preview it was opened for.
            'importAsking' => $preview !== null && $this->importAsked !== null && $this->importAsked === $this->previewToken,
            'invalidCount' => count($invalid),
            'notedCount' => count($noted),
            // Registered students whose archive date changes: normal for a few (tinggal kelas, salah kelas), a sign of a
            // wrong school year when there are many.
            'archiveMonths' => app(GraduationCalculator::class)->archiveMonths(),
            'archiveCorrections' => count(array_filter($noted, fn (array $r): bool => collect($r['notes'])
                ->contains(fn (string $note): bool => str_starts_with($note, ImportStudents::NOTE_ARCHIVE_CORRECTED)))),
            'sanitizedCount' => count(array_filter($rows, fn (array $r): bool => $r['sanitized'] ?? false)),
            'job' => $job,
            // QA9: the import asks for the password when the last confirmation is older than 15 minutes.
            'passwordAsked' => $passwordAsked,
            // QA9: the hint under a preview that can still be imported, when that import will ask for the password again;
            // also under a file that waits for a running import (SV1 review: a long queued import outlasts the 15
            // minutes, and the password page drops the waiting file).
            'passwordHint' => $passwordAsked && $preview !== null && ! $previewDone,
            'jobProgress' => $job !== null && $jobActive ? self::progressLine($job) : null,
            // What the job wrote, as it counted it (CLAUDE.md §7: a result is read from what the Action wrote): new accounts
            // (`created_count`) and class moves (`updated_count`, ImportStudentsJob). The corrections it writes are not
            // counted there, so no number of them is named (SV1 review: the preview's prediction is no result). Failed:
            // what it wrote before it stopped, if anything.
            'jobResult' => match (true) {
                $job === null || $jobActive => null,
                $job->status === StudentImportStatus::Done => self::doneTitle($job, $otherFile),
                default => self::resultLabel($job->created_count, $job->updated_count),
            },
            'jobFailedTitle' => $otherFile ? 'Impor '.$job?->file_name.' gagal' : 'Impor gagal',
            // The line under a finished import's title: its rejected rows ("file itu" above another file), else the login
            // hint for new students. No claim that the rows match the file: the job does not count the new rows another
            // import registered meanwhile, nor its corrections (SV1 third check review; importedNote() of the sync path).
            'jobNote' => match (true) {
                $job === null || $jobActive || $job->status !== StudentImportStatus::Done => null,
                $otherFile => $job->invalid_rows > 0 ? $job->invalid_rows.' baris file itu ditolak dan tidak diimpor.' : null,
                $job->invalid_rows > 0 => $job->invalid_rows.' baris ditolak dan tidak diimpor.',
                $job->created_count > 0 => self::NEW_STUDENTS_LOGIN,
                default => null,
            },
            'jobWaiting' => $otherFile ? 'File '.$preview['fileName'].' di bawah ini belum diimpor.' : null,
            'importedResult' => $this->imported !== null
                ? self::resultLabel($this->imported['created'], $this->imported['moved'] ?? 0, $this->imported['corrected'] ?? 0) ?? self::NOTHING_CHANGED
                : null,
            'importedNote' => $this->imported !== null ? self::importedNote($this->imported) : null,
            'emptyText' => match (true) {
                $rows === [] => self::EMPTY_FILE,
                $this->filter === 'notes' => 'Tidak ada baris dengan catatan.',
                default => 'Tidak ada baris yang ditolak.',
            },
            'list' => array_map(function (array $r): array {
                [$status, $tone] = self::KIND_LABELS[StudentImportReport::kindOf($r)];
                $notes = $r['notes'] ?? [];

                return [
                    ...$r,
                    'grade12' => self::monthLabel($r['grade12_start_date']),
                    'archive' => self::monthLabel($r['archive_at']),
                    'angkatan' => $r['grade12_start_date'] !== null ? (string) ((int) substr($r['grade12_start_date'], 0, 4) - 2) : null,
                    'kelas' => ($r['previous_classroom'] ?? null) !== null ? $r['previous_classroom'].' → '.$r['classroom'] : $r['classroom'],
                    'status' => $status,
                    'tone' => $tone,
                    'notes' => $notes,
                    // The chip "Sel dibersihkan" for a refused row; a valid row says it in its note (ImportStudents::NOTE_SANITIZED).
                    'cleaned' => ($r['sanitized'] ?? false) && ! in_array(ImportStudents::NOTE_SANITIZED, $notes, true),
                ];
            }, array_slice($list, 0, self::PREVIEW_ROWS)),
            'listTotal' => count($list),
            'yearOptions' => self::yearOptions(),
        ])->layoutData(['badges' => $this->kesiswaanBadges()]);
    }

    /**
     * What an import wrote, every kind of change the button and the dialog counted: "3 akun siswa dibuat, 12 siswa
     * pindah kelas, tanggal arsip atau No HP 2 siswa diperbarui". A kind with 0 is left out (no "0 akun siswa dibuat"
     * in front of a yearly file of class moves); null when nothing is counted (yet): the caller says it in its own
     * words (SV1 second check: a run of corrections only read "0 akun siswa dibuat" while it wrote them).
     */
    private static function resultLabel(int $created, int $moved, int $corrected = 0): ?string
    {
        $parts = array_filter([
            $created > 0 ? $created.' akun siswa dibuat' : null,
            $moved > 0 ? $moved.' siswa pindah kelas' : null,
            $corrected > 0 ? 'tanggal arsip atau No HP '.$corrected.' siswa diperbarui' : null,
        ]);

        return $parts === [] ? null : ucfirst(implode(', ', $parts));
    }

    /**
     * The title of a finished queued import: what the job counted, or QUEUED_NOTHING_COUNTED. Above a file it did not
     * import it names its own file: "Impor data-siswa.csv selesai: 150 akun siswa dibuat" (SV1 third check).
     */
    private static function doneTitle(StudentImport $job, bool $named): string
    {
        $result = self::resultLabel($job->created_count, $job->updated_count);

        if (! $named) {
            return $result ?? self::QUEUED_NOTHING_COUNTED;
        }

        return 'Impor '.$job->file_name.' selesai'.($result !== null ? ': '.lcfirst($result) : ' tanpa siswa baru atau pindah kelas');
    }

    /**
     * The line under the result of a synchronous import: the rows another import registered meanwhile (taken), then the
     * rejected rows, the login hint for new students, or that the classes match the file.
     *
     * @param  array{created: int, moved?: int, corrected?: int, invalid: int, taken?: int}  $imported
     */
    private static function importedNote(array $imported): string
    {
        $taken = $imported['taken'] ?? 0;

        return implode(' ', array_filter([
            $taken > 0 ? str_replace(':n', (string) $taken, self::TAKEN_BY_OTHER_IMPORT) : null,
            match (true) {
                $imported['invalid'] > 0 => $imported['invalid'].' baris ditolak dan belum diimpor. Perbaiki lalu upload ulang.',
                $imported['created'] > 0 => self::NEW_STUDENTS_LOGIN,
                // The rows another import wrote were not compared with this file: no claim that the classes match it.
                $taken > 0 => null,
                default => 'Data kelas sudah sesuai dengan file.',
            },
        ]));
    }

    /**
     * The line under the title of a queued or running import. ImportStudentsJob saves the row count of the file with
     * its first slice (total_rows is 0 until then), and that slice already writes up to CHUNK rows: it reads "Mulai
     * mengimpor…" (SV1 review; it said "Menyiapkan impor…" while rows were written), not "Menunggu giliran" under
     * "Sedang mengimpor"; and it counts new accounts and class moves only, so a run of corrections names no result
     * (SV1 second check).
     */
    private static function progressLine(StudentImport $job): string
    {
        if ($job->status === StudentImportStatus::Queued) {
            return 'Menunggu giliran.';
        }

        if ($job->total_rows <= 0) {
            return 'Mulai mengimpor…';
        }

        $result = self::resultLabel($job->created_count, $job->updated_count);

        return $job->processed_rows.' dari '.$job->total_rows.' baris diproses'.($result !== null ? ' · '.$result : '').'.';
    }

    /**
     * Forgets the queued import this screen shows once it is over (done or failed), gone, or no longer the user's to
     * see. A queued or running one stays: its banner keeps polling, and no other import starts next to it.
     */
    private function forgetFinishedImport(): void
    {
        if ($this->importId === null) {
            return;
        }

        $job = StudentImport::query()->find($this->importId);

        if ($job !== null && $job->isActive() && $this->actor()->can('view', $job)) {
            return;
        }

        $this->reset(['importId', 'queuedPreview']);
    }

    /**
     * True, with IMPORT_RUNNING on the field `file` (render() drops it again once no import of this person runs,
     * forgetRunningRefusal()), while a queued import of this person is still queued or running.
     * When the screen shows no running import (the import was started in another tab, or after the one shown here
     * ended), it takes that import over, so the refusal comes with its banner and its poll (SV1 review: a refusal with
     * nothing to wait on). The preview on screen counted the database before that import writes: it is read again
     * once the import is over (`previewWhileImporting`).
     */
    private function importRunning(): bool
    {
        $running = StudentImport::query()->activeFor($this->actor())->latest()->value('id');

        if ($running === null) {
            return false;
        }

        if (! $this->showsRunningImport()) {
            $this->importId = $running;
            // The preview on screen did not start that import: it waits for it.
            $this->queuedPreview = null;
        }

        if ($this->previewToken !== null) {
            $this->previewWhileImporting = true;
        }

        $this->addError('file', self::IMPORT_RUNNING);

        return true;
    }

    /**
     * Drops IMPORT_RUNNING (importRunning()) from the field `file` once no import of this person is queued or running.
     * Livewire keeps an error between requests, so after the import this screen waited for ended, the poll drew its
     * result, an enabled "Impor N siswa" and the red refusal side by side (SV1 review). Other messages on `file` stay.
     */
    private function forgetRunningRefusal(): void
    {
        $messages = $this->getErrorBag()->get('file');

        if (! in_array(self::IMPORT_RUNNING, $messages, true) || StudentImport::query()->activeFor($this->actor())->exists()) {
            return;
        }

        $this->resetErrorBag('file');

        foreach (array_diff($messages, [self::IMPORT_RUNNING]) as $message) {
            $this->addError('file', $message);
        }
    }

    /** The import this screen shows (`importId`) is still queued or running. */
    private function showsRunningImport(): bool
    {
        return $this->importId !== null && (StudentImport::query()->find($this->importId)?->isActive() ?? false);
    }

    /**
     * What the import writes, as the sentence of the "Impor data siswa?" dialog (D-128; before, the question of the
     * browser's confirm box). Every kind of change, so the numbers add up to the button's "Impor N siswa": "Buat 312
     * akun siswa, pindahkan 640 siswa ke kelas di file, dan perbarui tanggal arsip atau No HP untuk 4 siswa."
     *
     * @param  array{new: int, moved: int, corrected: int}  $kinds
     */
    private static function confirmText(array $kinds): string
    {
        $parts = array_values(array_filter([
            $kinds['new'] > 0 ? 'buat '.$kinds['new'].' akun siswa' : null,
            $kinds['moved'] > 0 ? 'pindahkan '.$kinds['moved'].' siswa ke kelas di file' : null,
            $kinds['corrected'] > 0 ? 'perbarui tanggal arsip atau No HP untuk '.$kinds['corrected'].' siswa' : null,
        ]));

        return match (count($parts)) {
            0 => 'Tidak ada data yang berubah.',
            1 => ucfirst($parts[0]).'.',
            2 => ucfirst($parts[0]).' dan '.$parts[1].'.',
            default => ucfirst($parts[0]).', '.$parts[1].', dan '.$parts[2].'.',
        };
    }

    /**
     * The preview an import starts from, or null with the reason on the field `file` (no file yet, or the preview
     * ran out). The same check for the question and for the confirm step.
     *
     * @return array{rows: list<array<string, mixed>>, fileName: string, schoolYear: string}|null
     */
    private function previewToImport(): ?array
    {
        if ($this->file !== null && $this->effectiveSchoolYear() === null) {
            // "Tahun lain…" without a valid start year: the file waits for it (no preview was read).
            $this->addError('customYear', (string) $this->customYearProblem());

            return null;
        }

        if ($this->file === null || $this->previewToken === null) {
            $this->addError('file', 'Upload file dulu.');

            return null;
        }

        $preview = $this->preview();

        if ($preview === null) {
            $this->addError('file', 'Preview sudah kedaluwarsa. Upload file lagi.');
        }

        return $preview;
    }

    /** @return array{rows: list<array<string, mixed>>, fileName: string, schoolYear: string}|null */
    private function preview(): ?array
    {
        if ($this->previewToken === null) {
            return null;
        }

        /** @var array{rows: list<array<string, mixed>>, fileName: string, schoolYear: string}|null $data */
        $data = Cache::get($this->cacheKey());

        return $data;
    }

    private function forgetPreview(): void
    {
        if ($this->previewToken !== null) {
            Cache::forget($this->cacheKey());
        }

        $this->previewToken = null;
        // An "Impor data siswa?" question about the preview that is gone is void, and so is its staleness.
        $this->importAsked = null;
        $this->previewWhileImporting = false;
    }

    private function cacheKey(): string
    {
        return 'import-siswa:'.$this->actor()->getKey().':'.$this->previewToken;
    }

    private function extension(): string
    {
        return strtolower((string) $this->file?->getClientOriginalExtension());
    }

    /**
     * Next, current and previous school year, then "Tahun lain…" (OTHER_YEAR) for a start year typed by hand.
     *
     * @return array<string, string>
     */
    private static function yearOptions(): array
    {
        $current = SchoolYear::containing(CarbonImmutable::now());
        $years = [new SchoolYear($current->startYear + 1), $current, new SchoolYear($current->startYear - 1)];

        return collect($years)->mapWithKeys(fn (SchoolYear $y): array => [$y->toString() => $y->toString()])->all()
            + [self::OTHER_YEAR => 'Tahun lain…'];
    }

    /**
     * The school year every step uses (dry run, import, queued import): the listed choice as it is, or the typed start
     * year as "YYYY/YYYY+1"; null while "Tahun lain…" has no valid start year (customYearProblem()).
     */
    private function effectiveSchoolYear(): ?string
    {
        if ($this->schoolYear !== self::OTHER_YEAR) {
            return $this->schoolYear;
        }

        return $this->customYearProblem() === null ? (new SchoolYear((int) trim($this->customYear)))->toString() : null;
    }

    /** Why the typed start year is refused (empty, not 4 digits, outside customYearRange()), or null when it is valid. */
    private function customYearProblem(): ?string
    {
        $value = trim($this->customYear);
        [$min, $max] = self::customYearRange();

        return match (true) {
            $value === '' => 'Isi tahun mulai, misalnya '.self::customYearExample().'.',
            preg_match('/^\d{4}$/', $value) !== 1 => 'Tahun mulai harus 4 angka, misalnya '.self::customYearExample().'.',
            (int) $value < $min || (int) $value > $max => 'Tahun mulai harus antara '.$min.' dan '.$max.'.',
            default => null,
        };
    }

    /**
     * The start years "Tahun mulai" accepts: the start year of the current school year ± OTHER_YEAR_SPAN, inside the
     * years SchoolYear knows.
     *
     * @return array{0: int, 1: int}
     */
    private static function customYearRange(): array
    {
        $current = SchoolYear::containing(CarbonImmutable::now())->startYear;

        return [
            max(SchoolYear::MIN_YEAR, $current - self::OTHER_YEAR_SPAN),
            min(SchoolYear::MAX_YEAR, $current + self::OTHER_YEAR_SPAN),
        ];
    }

    /** The example start year in the placeholder and the messages: two years after the current one (2028 in 2026/2027). */
    private static function customYearExample(): int
    {
        return SchoolYear::containing(CarbonImmutable::now())->startYear + 2;
    }

    private static function monthLabel(?string $date): string
    {
        return $date === null ? '–' : CarbonImmutable::parse($date)->locale('id')->translatedFormat('M Y');
    }

    private static function cell(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private static function tempPath(string $extension): string
    {
        $base = (string) tempnam(sys_get_temp_dir(), 'sth');
        @unlink($base);

        return $base.'.'.$extension;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function returnUrl(): string
    {
        $base = $this->returnTo !== '' ? $this->returnTo : route('kesiswaan.import');
        $query = $this->schoolYear === SchoolYear::containing(CarbonImmutable::now())->toString() ? [] : array_filter([
            self::YEAR_QUERY => $this->schoolYear,
            self::CUSTOM_YEAR_QUERY => $this->schoolYear === self::OTHER_YEAR ? trim($this->customYear) : '',
        ]);

        return $base.($query !== [] ? '?'.http_build_query($query) : '');
    }

    /**
     * SV4: the year chosen before the password page comes back with it (returnUrl() puts it in the address), so the
     * page never silently falls back to the current school year. Anything that is not a listed choice is ignored.
     */
    private function restoreYearChoice(): void
    {
        $asked = request()->query(self::YEAR_QUERY);

        if (! is_string($asked) || ! array_key_exists($asked, self::yearOptions())) {
            return;
        }

        $this->schoolYear = $asked;
        $typed = request()->query(self::CUSTOM_YEAR_QUERY);
        $this->customYear = $asked === self::OTHER_YEAR && is_string($typed) ? substr((string) preg_replace('/\D/', '', $typed), 0, 4) : '';
    }
}
