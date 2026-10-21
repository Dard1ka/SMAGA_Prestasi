<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoleName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pending/applied/cancelled change of a staff account to a privileged role (backlog #13, SECURITY §3m-9).
 * Two ways to apply: a second Admin approves it (`approved_at`), or — created while the requester was the
 * only Admin — it applies by itself at `effective_at` (24 h) unless an Admin cancels it.
 *
 * Since 21 Okt 2026 (the lead) no new rows are written: every role change applies at once (ChangeStaffRole). Rows made
 * before that day are still approved, cancelled or applied.
 *
 * Written only by App\Actions\Roles\{ApproveRoleChange,CancelRoleChange,ApplyRoleChange} (RequestRoleChange before):
 * nothing is mass-assignable, the Actions use forceFill().
 */
class RoleChangeRequest extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_roles' => 'array',
            'to_role' => RoleName::class,
            'effective_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    /** Not applied and not cancelled. @param Builder<RoleChangeRequest> $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('applied_at')->whereNull('cancelled_at');
    }

    /** Delay-mode requests whose 24 h have passed (scheduler). @param Builder<RoleChangeRequest> $query */
    public function scopeDue(Builder $query, ?\DateTimeInterface $now = null): void
    {
        $query->pending()->whereNotNull('effective_at')->where('effective_at', '<=', $now ?? now());
    }

    public function isPending(): bool
    {
        return $this->applied_at === null && $this->cancelled_at === null;
    }

    /** Created while the requester was the only Admin: applies by itself at effective_at. */
    public function usesDelay(): bool
    {
        return $this->effective_at !== null;
    }

    public function isDue(): bool
    {
        return $this->isPending() && ($this->approved_at !== null || ($this->effective_at !== null && ! $this->effective_at->isFuture()));
    }
}
