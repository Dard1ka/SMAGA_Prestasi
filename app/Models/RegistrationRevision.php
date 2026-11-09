<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RevisionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SV8, D-199: one revision of an approved registration, asked for by its ketua (RequestRevision) and decided by Tata
 * Usaha or Kesiswaan (ApproveRevision / RejectRevision). While it is pending the approved team stays as it is: nothing
 * of the payload touches `registration_members` or `mentor_id` before the approval.
 *
 * Not mass-assignable: status, payload, ready_at, decided_by, decided_at, note (the Actions write them with
 * forceFill()). The payload holds ids only; read it through the helpers below.
 */
class RegistrationRevision extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'registration_id',
        'requested_by',
        'reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RevisionStatus::class,
            'payload' => 'array',
            'ready_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    // Relations ------------------------------------------------------------------------------

    /** @return BelongsTo<Registration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    // Scopes ---------------------------------------------------------------------------------

    /** @param Builder<RegistrationRevision> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', RevisionStatus::Pending->value);
    }

    /**
     * What Tata Usaha and Kesiswaan can decide now (their badge): pending, every new member confirmed (`ready_at`), and
     * a registration whose ketua is not in the trash (Registration::scopeWithListedLeader(), the rule of every queue).
     *
     * @param  Builder<RegistrationRevision>  $query
     */
    public function scopeAwaitingDecision(Builder $query): void
    {
        $query->pending()->whereNotNull('ready_at')->whereHas('registration', fn (Builder $r) => $r->withListedLeader());
    }

    /**
     * Pending revisions that invite this student (`payload.add`). JSON containment works on MySQL and SQLite alike.
     *
     * @param  Builder<RegistrationRevision>  $query
     */
    public function scopeInviting(Builder $query, string $studentId): void
    {
        $query->pending()->whereJsonContains('payload->add', $studentId);
    }

    // Payload --------------------------------------------------------------------------------

    /** @return list<string> student ULIDs the revision brings into the team */
    public function additions(): array
    {
        return self::ids($this->payload['add'] ?? []);
    }

    /** @return list<string> student ULIDs of the confirmed anggota the revision takes out of the team */
    public function removals(): array
    {
        return self::ids($this->payload['remove'] ?? []);
    }

    /** The guru pembimbing the revision puts in (a user id), or null when the guru stays. */
    public function mentorTo(): ?int
    {
        $to = $this->payload['mentor_to'] ?? null;

        return $to === null ? null : (int) $to;
    }

    /** The guru the registration had when the revision was asked for (null = none). */
    public function mentorFrom(): ?int
    {
        $from = $this->payload['mentor_from'] ?? null;

        return $from === null ? null : (int) $from;
    }

    /**
     * The answer of an invited student: 'confirmed', 'declined' or null (not answered yet, or not invited).
     *
     * @return 'confirmed'|'declined'|null
     */
    public function answerOf(string $studentId): ?string
    {
        $answer = (array) (($this->payload['answers'] ?? [])[$studentId] ?? []);

        return match (true) {
            isset($answer['declined_at']) => 'declined',
            isset($answer['confirmed_at']) => 'confirmed',
            default => null,
        };
    }

    /** When the invited student confirmed (ISO 8601 as stored), or null. */
    public function confirmedAt(string $studentId): ?string
    {
        $at = (($this->payload['answers'] ?? [])[$studentId] ?? [])['confirmed_at'] ?? null;

        return is_string($at) ? $at : null;
    }

    /** Every invited student confirmed (true at once when nobody is invited). */
    public function everyoneConfirmed(): bool
    {
        foreach ($this->additions() as $id) {
            if ($this->answerOf($id) !== 'confirmed') {
                return false;
            }
        }

        return true;
    }

    public function isPending(): bool
    {
        return $this->status === RevisionStatus::Pending;
    }

    /** @return list<string> */
    private static function ids(mixed $value): array
    {
        return array_values(array_map('strval', array_filter((array) $value, fn (mixed $id): bool => is_string($id) && $id !== '')));
    }
}
