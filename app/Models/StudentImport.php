<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StudentImportStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A queued student import (backlog #19): created by App\Livewire\Akun\ImportSiswa for files with more than
 * App\Jobs\ImportStudentsJob::SYNC_MAX_ROWS valid rows, advanced by ImportStudentsJob slice by slice, polled by
 * the import screen. Counts only (no student data). Status, counters and file_id are not mass-assignable.
 */
class StudentImport extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'extension',
        'school_year',
        'file_name',
        'file_sha256',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => StudentImportStatus::class,
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'invalid_rows' => 'integer',
            'sanitized_rows' => 'integer',
            'processed_rows' => 'integer',
            'created_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** Queued or running imports of this user. @param Builder<StudentImport> $query */
    public function scopeActiveFor(Builder $query, User $user): void
    {
        $query->where('user_id', $user->getKey())
            ->whereIn('status', [StudentImportStatus::Queued->value, StudentImportStatus::Running->value]);
    }

    public function isActive(): bool
    {
        return $this->status instanceof StudentImportStatus && $this->status->isActive();
    }

    /** 0–100, for the progress bar (w-pct-*). */
    public function progressPercent(): int
    {
        if ($this->status === StudentImportStatus::Done) {
            return 100;
        }

        if ($this->total_rows <= 0) {
            return 0;
        }

        return (int) min(100, floor($this->processed_rows * 100 / $this->total_rows));
    }
}
