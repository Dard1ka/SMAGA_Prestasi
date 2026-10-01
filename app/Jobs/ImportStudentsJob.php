<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Accounts\ImportStudents;
use App\Domain\Files\StoreUpload;
use App\Enums\StudentImportStatus;
use App\Models\StudentImport;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Queued student import (backlog #19, SECURITY §3f "jalankan di queue"). The import screen (App\Livewire\Akun\ImportSiswa)
 * keeps the dry run synchronous and queues the commit when the file has more than SYNC_MAX_ROWS valid rows: hashing the
 * initial passwords costs ~0,2 s per row (bcrypt 12), so a synchronous commit of a few hundred rows would hit the
 * 30 s PHP / 60 s proxy limits of production.
 *
 * One job = one slice of CHUNK file rows (~20 s), then it queues the next slice: the single queue worker can send OTP
 * mail between slices, every slice stays far below the worker timeout and `retry_after`, and each slice is its own
 * short transaction. ImportStudents::importSlice() re-validates the whole file each time, so a retried slice never
 * creates a student twice. The student_imports row carries status + counters for the screen's polling. After the last
 * slice: one audit entry (ImportStudents::recordImport) and the stored spreadsheet is deleted.
 */
final class ImportStudentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Up to this many valid rows the screen imports synchronously; above, it queues this job. */
    public const SYNC_MAX_ROWS = 50;

    /** File rows per job. */
    public const CHUNK = 100;

    public int $tries = 2;

    public int $timeout = 80;

    public int $backoff = 15;

    public function __construct(public readonly string $importId, public readonly int $offset = 0) {}

    public function handle(ImportStudents $importer, StoreUpload $store): void
    {
        $import = StudentImport::query()->with(['user', 'file'])->find($this->importId);

        if ($import === null || ! $import->isActive()) {
            return; // finished, failed or gone: nothing to do (idempotent)
        }

        $actor = $import->user;

        if (! $actor instanceof User || $import->file === null) {
            $this->finishFailed($import, $store, 'File impor atau akun pengimpor tidak ditemukan.');

            return;
        }

        $path = $import->file->storage()->path($import->file->path);

        if (! is_file($path)) {
            $this->finishFailed($import, $store, 'File impor tidak ditemukan. Unggah ulang file.');

            return;
        }

        if ($import->status === StudentImportStatus::Queued) {
            $import->forceFill(['status' => StudentImportStatus::Running, 'started_at' => now()])->save();
        }

        try {
            $result = $importer->importSlice(
                $actor,
                $path,
                $import->extension,
                $import->school_year,
                $this->offset,
                self::CHUNK,
            );
        } catch (ValidationException $e) {
            $this->finishFailed($import, $store, (string) collect($e->errors())->flatten()->first());

            return;
        } catch (AuthorizationException) {
            $this->finishFailed($import, $store, 'Akun pengimpor tidak lagi berhak mengimpor data siswa.');

            return;
        }

        $processed = min($result['rows'], $this->offset + self::CHUNK);
        $changes = [
            'processed_rows' => $processed,
            'created_count' => $import->created_count + $result['created'],
        ];

        if ($this->offset === 0) {
            // Counts of the file as validated before anything was created (later slices see their own rows as taken).
            $changes += [
                'total_rows' => $result['rows'],
                'valid_rows' => $result['valid'],
                'invalid_rows' => $result['invalid'],
                'sanitized_rows' => $result['sanitized'],
            ];
        }

        $import->forceFill($changes)->save();

        if ($processed < $result['rows']) {
            self::dispatch($import->getKey(), $this->offset + self::CHUNK);

            return;
        }

        $importer->recordImport(
            $actor,
            $import->school_year,
            $import->total_rows,
            $import->created_count,
            max(0, $import->total_rows - $import->created_count),
            $import->file_sha256,
        );

        $import->forceFill(['status' => StudentImportStatus::Done, 'finished_at' => now()])->save();
        $this->discardFile($import, $store);
    }

    public function failed(?Throwable $exception): void
    {
        $import = StudentImport::query()->with('file')->find($this->importId);

        if ($import !== null && $import->isActive()) {
            $this->finishFailed($import, app(StoreUpload::class), 'Impor terhenti karena gangguan sistem. Siswa yang sudah dibuat tetap tersimpan; unggah ulang file untuk melanjutkan.');
        }
    }

    private function finishFailed(StudentImport $import, StoreUpload $store, string $message): void
    {
        $import->forceFill([
            'status' => StudentImportStatus::Failed,
            'error' => mb_substr($message !== '' ? $message : 'Impor gagal.', 0, 255),
            'finished_at' => now(),
        ])->save();

        $this->discardFile($import, $store);
    }

    /** The spreadsheet holds birth dates: delete it as soon as the import is over (the SHA-256 stays). */
    private function discardFile(StudentImport $import, StoreUpload $store): void
    {
        $file = $import->file;

        if ($file !== null) {
            $import->forceFill(['file_id' => null])->save();
            $store->discard($file);
        }
    }
}
