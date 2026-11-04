<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One class or student in the trash ("Sampah", Admin › Kelas; the lead's decision of 5 Nov 2026, smaga_server only).
 *
 * A class entry (`kind` classroom) has the students trashed with it as children (`parent_id`); a student trashed alone
 * has no parent. While an entry is open (neither `restored_at` nor `purged_at`) its class / student rows are
 * soft-deleted: hidden everywhere, the students cannot log in. Before `purge_after` the Admin may restore it
 * (App\Actions\Trash\RestoreFromTrash); from `purge_after` on the daily `sth:sampah:hapus-permanen` deletes the rows for
 * good and stamps `purged_at` (App\Actions\Trash\PurgeTrash). The row itself stays as history; it names ids only, never
 * a name or NISN, and has no foreign keys (the rows it names may be gone).
 *
 * Written only by the trash Actions (forceFill / forceCreate): nothing is mass-assignable.
 */
class TrashEntry extends Model
{
    use HasUlids;

    public const KIND_CLASSROOM = 'classroom';

    public const KIND_STUDENT = 'student';

    /** Days an entry stays restorable (the lead: "7 hari"). */
    public const KEEP_DAYS = 7;

    /** The daily purge run (routes/console.php) starts at this time, Asia/Jakarta. */
    public const PURGE_TIME = '02:00';

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'deleted_by' => 'integer',
            'restored_by' => 'integer',
            'deleted_at' => 'immutable_datetime',
            'purge_after' => 'immutable_datetime',
            'restored_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
        ];
    }

    /**
     * The moment an entry trashed at $deletedAt is purged: the first daily run (PURGE_TIME) at or after KEEP_DAYS full
     * days. Restorable until then, so the date the dialog promises is the day the rows really go.
     */
    public static function purgeAfterFor(DateTimeInterface $deletedAt): CarbonImmutable
    {
        $earliest = CarbonImmutable::instance($deletedAt)->setTimezone((string) config('app.timezone'))->addDays(self::KEEP_DAYS);
        [$hour, $minute] = array_map(intval(...), explode(':', self::PURGE_TIME));
        $run = $earliest->setTime($hour, $minute);

        return $run->lessThan($earliest) ? $run->addDay() : $run;
    }

    // Relations ------------------------------------------------------------------------------

    /** @return BelongsTo<Classroom, $this> the class, also while it is in the trash */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> the student's account, also while it is in the trash */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }

    /** @return BelongsTo<TrashEntry, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<TrashEntry, $this> students trashed with this class */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    // Scopes ---------------------------------------------------------------------------------

    /** Neither restored nor purged: its rows are in the trash now. @param Builder<TrashEntry> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('restored_at')->whereNull('purged_at');
    }

    /** Open entries whose time has come. @param Builder<TrashEntry> $query */
    public function scopeDue(Builder $query, DateTimeInterface $now): void
    {
        $query->open()->where('purge_after', '<=', $now);
    }

    // State ----------------------------------------------------------------------------------

    public function isClassroom(): bool
    {
        return $this->kind === self::KIND_CLASSROOM;
    }

    public function isOpen(): bool
    {
        return $this->restored_at === null && $this->purged_at === null;
    }

    /** Open and before `purge_after`: "Pulihkan" may still bring it back. */
    public function isRestorable(?DateTimeInterface $now = null): bool
    {
        return $this->isOpen() && $this->purge_after->greaterThan($now ?? CarbonImmutable::now());
    }
}
