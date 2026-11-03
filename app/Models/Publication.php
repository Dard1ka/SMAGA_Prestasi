<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PublicationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PublicationFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Humas publication of one achievement (unique achievement_id). channels = list of PublicationChannel values.
 * Not mass-assignable: status, published_at, created_by, flyer_file_id, reminded_at.
 * `scheduled_at` is only a reminder for Humas (D-122): when it arrives the scheduler mails Humas once and stamps
 * `reminded_at` (RemindDuePublication); the row stays `terjadwal` and counts as overdue ("Lewat jadwal", isOverdue())
 * until Humas presses "Tandai dipublikasikan". Nothing becomes `dipublikasikan` by itself.
 * `flyer_file_id` is legacy (M3-E): Humas makes its flyers outside STH, so nothing sets the column any more;
 * it only still points at the PNG of a publication whose flyer STH generated before 2026-10-06.
 */
class Publication extends Model
{
    /** @use HasFactory<PublicationFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'achievement_id',
        'channels',
        'scheduled_at',
        'caption',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PublicationStatus::class,
            'channels' => 'array',
            'scheduled_at' => 'immutable_datetime',
            'reminded_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Achievement, $this> */
    public function achievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class);
    }

    /** The flyer STH generated before M3-E, if any (FilePolicy still guards its download). @return BelongsTo<File, $this> */
    public function flyerFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'flyer_file_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<Publication> $query */
    public function scopeInStatus(Builder $query, PublicationStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * "Lewat jadwal" (D-122): still `terjadwal` although its time has arrived (or it has no time at all), so Humas
     * has not marked it as published yet. Derived, not a status of its own. Same rule as scopeOverdue().
     */
    public function isOverdue(?DateTimeInterface $now = null): bool
    {
        if ($this->status !== PublicationStatus::Terjadwal) {
            return false;
        }

        return $this->scheduled_at === null
            || ! $this->scheduled_at->greaterThan($now ?? CarbonImmutable::now());
    }

    /**
     * The rows isOverdue() is true for.
     *
     * @param  Builder<Publication>  $query
     */
    public function scopeOverdue(Builder $query, ?DateTimeInterface $now = null): void
    {
        $query
            ->where($this->qualifyColumn('status'), PublicationStatus::Terjadwal)
            ->where(fn (Builder $w) => $w
                ->whereNull($this->qualifyColumn('scheduled_at'))
                ->orWhere($this->qualifyColumn('scheduled_at'), '<=', $now ?? CarbonImmutable::now()));
    }
}
