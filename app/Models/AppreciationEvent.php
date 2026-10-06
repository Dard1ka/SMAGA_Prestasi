<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AppreciationEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An appreciation moment, e.g. "Upacara Senin, 5 Oktober 2026". Not mass-assignable: `completed_at` and
 * `completed_by` ("Tandai sudah dilaksanakan", set only by CompleteAppreciationEvent). A completed event is
 * the record: nothing is added to it and it cannot be deleted (M1-E).
 */
class AppreciationEvent extends Model
{
    /** @use HasFactory<AppreciationEventFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'held_on',
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
