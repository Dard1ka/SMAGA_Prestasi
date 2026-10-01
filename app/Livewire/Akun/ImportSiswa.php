<?php

declare(strict_types=1);

namespace App\Livewire\Akun;

use App\Actions\Accounts\ImportStudents;
use App\Domain\Archive\SchoolYear;
use App\Domain\Files\InvalidUpload;
use App\Domain\Files\StoreUpload;
use App\Enums\Permission;
use App\Enums\StudentImportStatus;
use App\Jobs\ImportStudentsJob;
use App\Livewire\Kesiswaan\Concerns\WithKesiswaanBadges;
use App\Models\StudentImport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
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
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Import siswa (UI-MAP #23; `kesiswaan.import` and `admin.import`, D-26). Three steps: download the
 * template (generated on the fly), upload + choose the tahun ajaran → dry run with ImportStudents,
 * review valid/invalid rows, then import. The preview rows stay server-side in the cache (keyed per user),
 * never in the Livewire payload. The initial password pattern (D-24) is never shown or described.
 * B2 (backlog #19): more than ImportStudentsJob::SYNC_MAX_ROWS valid rows → the commit is queued (StudentImport row,
 * progress polled with wire:poll); rows whose cells lost a leading = + - @ show "Sel dibersihkan".
 */
#[Title('Import data siswa')]
#[Layout('layouts::app', ['subtitle' => 'Akun siswa dibuat otomatis dari file Excel.'])]
class ImportSiswa extends Component
{
    use WithFileUploads;
    use WithKesiswaanBadges;

    public const PREVIEW_ROWS = 50;

    private const CACHE_MINUTES = 30;

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public string $schoolYear = '';

    /** all | bad */
    public string $filter = 'all';

    #[Locked]
    public ?string $previewToken = null;

    /** Summary after the real import: created, invalid. @var array{created: int, invalid: int}|null */
    #[Locked]
    public ?array $imported = null;

    /** B2 (#19): the queued import (StudentImport id) whose progress is polled. */
    #[Locked]
    public ?string $importId = null;

    public function mount(): void
    {
        $this->authorize(Permission::StudentImport->value);
        $this->schoolYear = SchoolYear::containing(CarbonImmutable::now())->toString();
        $this->importId = StudentImport::query()->activeFor($this->actor())->latest()->value('id');
    }

    public function downloadTemplate(): BinaryFileResponse
    {
        $this->authorize(Permission::StudentImport->value);

        $path = self::tempPath('xlsx');
        SimpleExcelWriter::create($path)
            ->addHeader(['Nama lengkap', 'NIS', 'NISN', 'Tanggal lahir', 'Kelas saat pendaftaran', 'No HP'])
            ->close();

        return response()->download($path, 'template-import-siswa.xlsx')->deleteFileAfterSend();
    }

    /** Runs as soon as a file is chosen: dry run → preview. */
    public function updatedFile(ImportStudents $import): void
    {
        $this->imported = null;
        $this->check($import);
    }

    public function updatedSchoolYear(ImportStudents $import): void
    {
        if ($this->file !== null) {
            $this->check($import);
        }
    }

    public function check(ImportStudents $import): void
    {
        $this->resetErrorBag();
        $this->forgetPreview();

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

            $report = $import->handle($this->actor(), (string) $this->file->getRealPath(), $this->extension(), $this->schoolYear, dryRun: true);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
            $this->reset('file');

            return;
        }

        $this->previewToken = (string) Str::ulid();
        Cache::put($this->cacheKey(), [
            'rows' => $report->rows,
            'fileName' => $this->file->getClientOriginalName(),
            'schoolYear' => $report->schoolYear,
        ], now()->addMinutes(self::CACHE_MINUTES));
        $this->filter = 'all';
    }

    public function import(ImportStudents $import): void
    {
        if ($this->file === null || $this->previewToken === null) {
            $this->addError('file', 'Unggah file dulu.');

            return;
        }

        $preview = $this->preview();

        if ($preview === null) {
            $this->addError('file', 'Pratinjau sudah kedaluwarsa. Unggah file lagi.');

            return;
        }

        // B2 (#19): many rows → queued job with progress (password hashing would outlast the request timeout).
        if (count(array_filter($preview['rows'], fn (array $r): bool => $r['errors'] === [])) > ImportStudentsJob::SYNC_MAX_ROWS) {
            $this->queueImport(app(StoreUpload::class));

            return;
        }

        try {
            $report = $import->handle($this->actor(), (string) $this->file->getRealPath(), $this->extension(), $this->schoolYear, dryRun: false);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->imported = ['created' => $report->created, 'invalid' => $report->invalidCount()];
        $this->dispatch('sth-toast', message: $report->created.' akun siswa dibuat.', tone: 'success');
    }

    public function startOver(): void
    {
        $this->forgetPreview();
        $this->reset(['file', 'imported', 'filter', 'importId']);
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

        if (StudentImport::query()->activeFor($actor)->exists()) {
            $this->addError('file', 'Masih ada impor yang berjalan. Tunggu sampai selesai.');

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
            'school_year' => $this->preview()['schoolYear'] ?? $this->schoolYear,
            'file_name' => $file->original_name,
            'file_sha256' => $file->sha256,
        ]);
        $import->forceFill(['status' => StudentImportStatus::Queued, 'file_id' => $file->getKey()])->save();

        $this->importId = $import->getKey();
        ImportStudentsJob::dispatch($import->getKey());

        $this->dispatch('sth-toast', message: 'Impor berjalan di latar belakang. Kemajuannya tampil di sini.', tone: 'info');
    }

    /** "Unduh baris bermasalah": row number, name, NIS, class and the errors (formula-safe). */
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
            if ($row['errors'] === []) {
                continue;
            }

            $writer->addRow([
                'Baris' => $row['row'],
                'Nama lengkap' => self::cell($row['name']),
                'NIS' => self::cell($row['nis']),
                'Kelas' => self::cell($row['classroom']),
                'Masalah' => self::cell(implode(' ', $row['errors'])),
            ]);
        }

        $writer->close();

        return response()->download($path, 'baris-bermasalah.xlsx')->deleteFileAfterSend();
    }

    public function render(): View
    {
        $preview = $this->preview();
        $rows = $preview['rows'] ?? [];
        $invalid = array_values(array_filter($rows, fn (array $r): bool => $r['errors'] !== []));
        $duplicates = count(array_filter($invalid, fn (array $r): bool => collect($r['errors'])->contains(fn (string $e): bool => str_contains($e, 'dobel di file'))));
        $list = $this->filter === 'bad' ? $invalid : $rows;
        $job = $this->importId !== null ? StudentImport::query()->find($this->importId) : null;
        $job = $job !== null && $this->actor()->can('view', $job) ? $job : null;
        $step = match (true) {
            $preview !== null || $this->imported !== null || $job !== null => 3,
            default => 2,
        };

        return view('livewire.akun.import-siswa', [
            'step' => $step,
            'preview' => $preview,
            'total' => count($rows),
            'validCount' => count($rows) - count($invalid),
            'invalidCount' => count($invalid),
            'duplicateCount' => $duplicates,
            'sanitizedCount' => count(array_filter($rows, fn (array $r): bool => $r['sanitized'] ?? false)),
            'job' => $job,
            'list' => array_map(fn (array $r): array => [
                ...$r,
                'grade12' => self::monthLabel($r['grade12_start_date']),
                'archive' => self::monthLabel($r['archive_at']),
                'angkatan' => $r['grade12_start_date'] !== null ? (string) ((int) substr($r['grade12_start_date'], 0, 4) - 2) : null,
            ], array_slice($list, 0, self::PREVIEW_ROWS)),
            'listTotal' => count($list),
            'yearOptions' => self::yearOptions(),
        ])->layoutData(['badges' => $this->kesiswaanBadges()]);
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
    }

    private function cacheKey(): string
    {
        return 'import-siswa:'.$this->actor()->getKey().':'.$this->previewToken;
    }

    private function extension(): string
    {
        return strtolower((string) $this->file?->getClientOriginalExtension());
    }

    /** @return array<string, string> */
    private static function yearOptions(): array
    {
        $current = SchoolYear::containing(CarbonImmutable::now());
        $years = [new SchoolYear($current->startYear + 1), $current, new SchoolYear($current->startYear - 1)];

        return collect($years)->mapWithKeys(fn (SchoolYear $y): array => [$y->toString() => $y->toString()])->all();
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
}
