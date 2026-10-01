<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pending/applied/cancelled "Ganti akun Kepala Sekolah" request (D-01, SECURITY §3m-9). Written only by
 * App\Actions\Users\{RequestPrincipalReplacement,CancelPrincipalReplacement,ApplyPrincipalReplacement}:
 * nothing is mass-assignable, the Actions use forceFill().
 */
class PrincipalReplacement extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    /** Not applied and not cancelled. @param Builder<PrincipalReplacement> $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('applied_at')->whereNull('cancelled_at');
    }

    /** Pending and past the 24 h delay. @param Builder<PrincipalReplacement> $query */
    public function scopeDue(Builder $query, ?\DateTimeInterface $now = null): void
    {
        $query->pending()->where('effective_at', '<=', $now ?? now());
    }

    public function isPending(): bool
    {
        return $this->applied_at === null && $this->cancelled_at === null;
    }
}
