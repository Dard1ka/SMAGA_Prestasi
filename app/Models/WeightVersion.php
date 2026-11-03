<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Points\WeightTable;
use Database\Factories\WeightVersionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A set of point weights. Versions are append-only: changing weights = a new version (+ audited recalculation).
 * The active version is the latest with effective_at <= now.
 */
class WeightVersion extends Model
{
    /** @use HasFactory<WeightVersionFactory> */
    use HasFactory, HasUlids, LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'effective_at',
        'note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('weight')
            ->logAll()
            ->logExcept(['created_at', 'updated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return HasMany<WeightValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(WeightValue::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Achievement, $this> */
    public function achievements(): HasMany
    {
        return $this->hasMany(Achievement::class);
    }

    /** @param Builder<WeightVersion> $query */
    public function scopeEffective(Builder $query, ?DateTimeInterface $at = null): void
    {
        $query->where('effective_at', '<=', $at ?? now())->orderByDesc('effective_at')->orderByDesc('id');
    }

    /** The version in force now (or at $at); null only before WeightSeeder ran. */
    public static function active(?DateTimeInterface $at = null): ?self
    {
        return static::query()->effective($at)->first();
    }

    /** Domain weight table built from this version's values (App\Domain\Points). */
    public function toWeightTable(): WeightTable
    {
        return WeightTable::fromRows($this->values()->get(['group', 'key', 'value']));
    }
}
