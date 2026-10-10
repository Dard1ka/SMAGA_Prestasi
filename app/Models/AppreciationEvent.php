<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AppreciationEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An appreciation moment, e.g. "Upacara Senin". Not mass-assignable: `completed_at` and
 * `completed_by` ("Tandai sudah dilaksanakan", set only by CompleteAppreciationEvent). A completed event is
 * the record: nothing is added to it and it cannot be deleted (M1-E).
 *
 * `held_on` is the day and `held_time` the start time (D-123, lead 2026-10-10). The rules stay day-level and read
 * `held_on` only; `held_time` is what people are told. It is null for an event made before the column existed.
 * Print it through App\Actions\Publications\EventWhen, never from the raw column.
 */
class AppreciationEvent extends Model
{
    /** @use HasFactory<AppreciationEventFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'held_on',
        'held_time',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'held_on' => 'immutable_date',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<Appreciation, $this> */
    public function appreciations(): HasMany
    {
        return $this->hasMany(Appreciation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Confirmed as held. Reads the raw attribute so a model that was just created in memory (the column is
     * not loaded yet, Model::shouldBeStrict()) counts as open.
     */
    public function isCompleted(): bool
    {
        return ($this->getAttributes()['completed_at'] ?? null) !== null;
    }

    /**
     * Start time as "07:00", or null for an event without one (made before D-123). MySQL TIME and SQLite both
     * hand back 'HH:MM:SS'. Reads the raw attribute, like isCompleted(): a model that only exists in memory or was
     * loaded without the column must not throw under Model::shouldBeStrict().
     */
    public function heldTime(): ?string
    {
        $raw = $this->getAttributes()['held_time'] ?? null;

        if (! is_string($raw) || preg_match('/^(\d{2}):(\d{2})/', $raw, $m) !== 1) {
            return null;
        }

        return $m[1].':'.$m[2];
    }

    /** The moment the event starts, in the app timezone; the start of its day when it has no time. */
    public function startsAt(): CarbonImmutable
    {
        $day = CarbonImmutable::parse($this->held_on->toDateString(), (string) config('app.timezone'));
        $time = $this->heldTime();

        return $time !== null ? $day->setTimeFromTimeString($time) : $day;
    }

    /**
     * Events not yet confirmed as held: the ones Humas can still fill, confirm or delete.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('completed_at'));
    }

    /** @param Builder<self> $query */
    public function scopeCompleted(Builder $query): void
    {
        $query->whereNotNull($this->qualifyColumn('completed_at'));
    }
}
