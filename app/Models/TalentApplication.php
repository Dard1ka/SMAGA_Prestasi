<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TalentStatus;
use Database\Factories\TalentApplicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Pilihan talenta of a student (a student may have several, D-23). Unique (student, type, branch);
 * for OLPAIN (branch NULL) the Action must check duplicates itself.
 * Not mass-assignable: status, mentor_id, assessment_on, note (Kesiswaan/pembina Actions).
 */
class TalentApplication extends Model
{
    /** @use HasFactory<TalentApplicationFactory> */
    use HasFactory, HasUlids, LogsActivity, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'student_id',
        'talent_type_id',
        'talent_branch_id',
        'evidence_file_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'terdaftar',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TalentStatus::class,
            'assessment_on' => 'immutable_date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('talent')
            ->logAll()
            ->logExcept(['created_at', 'updated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<TalentType, $this> */
    public function talentType(): BelongsTo
    {
        return $this->belongsTo(TalentType::class);
    }

    /** @return BelongsTo<TalentBranch, $this> */
    public function talentBranch(): BelongsTo
    {
        return $this->belongsTo(TalentBranch::class);
    }

    /** @return BelongsTo<File, $this> */
    public function evidenceFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'evidence_file_id');
    }

    /** @return BelongsTo<User, $this> */
    public function mentor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentor_id');
    }

    /** @param Builder<TalentApplication> $query */
    public function scopeInStatus(Builder $query, TalentStatus ...$statuses): void
    {
        $query->whereIn('status', $statuses);
    }
}
