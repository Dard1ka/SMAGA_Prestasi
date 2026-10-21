<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Publications\NamedStudent;
use App\Domain\Archive\SchoolYear;
use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Enums\WeightCategory;
use Database\Factories\AchievementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Prestasi: one row per team member of a verified report (every member gets full points, D-08).
 * Weights are snapshots; decimals are cast to strings ("68.00") — never compare them as floats.
 * Nothing here is mass-assignable except the foreign keys: points/weights come from
 * App\Domain\Points\PointCalculator::breakdown()->toArray() via forceFill() in the verify Action.
 */
class Achievement extends Model
{
    /** @use HasFactory<AchievementFactory> */
    use HasFactory, HasUlids, LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'report_id',
        'student_id',
        'competition_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'result' => ResultType::class,
            'level' => Level::class,
            'weight_category' => WeightCategory::class,
            'w_level' => 'decimal:2',
            'w_result' => 'decimal:2',
            'w_organizer' => 'decimal:2',
            'points' => 'decimal:2',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('achievement')
            ->logAll()
            ->logExcept(['created_at', 'updated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    // Relations ------------------------------------------------------------------------------

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    /** @return BelongsTo<Competition, $this> */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class)->withTrashed();
    }

    /** @return BelongsTo<WeightVersion, $this> */
    public function weightVersion(): BelongsTo
    {
        return $this->belongsTo(WeightVersion::class);
    }

    /** @return HasOne<Publication, $this> */
    public function publication(): HasOne
    {
        return $this->hasOne(Publication::class);
    }

    /** @return HasMany<Appreciation, $this> */
    public function appreciations(): HasMany
    {
        return $this->hasMany(Appreciation::class);
    }

    // Scopes ---------------------------------------------------------------------------------

    /** @param Builder<Achievement> $query */
    public function scopeForBidang(Builder $query, Bidang $bidang): void
    {
        $query->whereHas('competition', fn (Builder $c) => $c->withTrashed()->where('bidang', $bidang));
    }

    /**
     * Tahun ajaran of the competition's final date (1 July – 30 June), e.g. '2025/2026'.
     *
     * @param  Builder<Achievement>  $query
     */
    public function scopeInSchoolYear(Builder $query, SchoolYear|string $schoolYear): void
    {
        $year = $schoolYear instanceof SchoolYear ? $schoolYear : SchoolYear::fromString($schoolYear);

        $query->whereHas('competition', fn (Builder $c) => $c->withTrashed()->whereBetween('final_on', [
            sprintf('%04d-07-01', $year->startYear),
            sprintf('%04d-06-30', $year->startYear + 1),
        ]));
    }

    /**
     * Only students who may be named (public pages, D-16): NamedStudent, no consent condition since D-162.
     *
     * @param  Builder<Achievement>  $query
     */
    public function scopePublicVisible(Builder $query): void
    {
        $query->whereHas('student', fn (Builder $s) => NamedStudent::constrain($s));
    }
}
