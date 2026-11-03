<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportStatus;
use App\Enums\ResultType;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Laporan hasil (one per registration). Verification creates one Achievement per member.
 * Not mass-assignable: status, final_result, verified_by, verified_at, submitted_by, submitted_at.
 */
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory, HasUlids, LogsActivity, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'registration_id',
        'claimed_result',
        'note',
        'research_title',
        'research_abstract',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'claimed_result' => ResultType::class,
            'final_result' => ResultType::class,
            'status' => ReportStatus::class,
            'submitted_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'late' => 'boolean', // W2-A2 (D-25); since D-151 no deadline: SubmitReport always writes false, old rows keep theirs
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('report')
            ->logAll()
            ->logExcept(['created_at', 'updated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Registration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return HasMany<ReportFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(ReportFile::class);
    }

    /** @return HasMany<Achievement, $this> */
    public function achievements(): HasMany
    {
        return $this->hasMany(Achievement::class);
    }

    /** Kesiswaan verification queue. @param Builder<Report> $query */
    public function scopeAwaitingVerification(Builder $query): void
    {
        $query->where('status', ReportStatus::Dikirim);
    }
}
