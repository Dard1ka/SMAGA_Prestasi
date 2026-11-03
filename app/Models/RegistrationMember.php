<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemberRole;
use Database\Factories\RegistrationMemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One student in a registration. Unique (registration_id, student_id).
 * Not mass-assignable: role, confirmed_at, declined_at (the invite Action sets role; only the invitee confirms).
 */
class RegistrationMember extends Model
{
    /** @use HasFactory<RegistrationMemberFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'registration_id',
        'student_id',
        'invited_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => MemberRole::class,
            'invited_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Registration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @param Builder<RegistrationMember> $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('confirmed_at')->whereNull('declined_at');
    }

    public function isLeader(): bool
    {
        return $this->role === MemberRole::Ketua;
    }

    /**
     * The one order of a team list, on every screen that shows one (Pendaftaran lomba panel, Lomba saya, Undangan tim,
     * Guru › detail binaan; FS1): the ketua first, then by invitation time with "not invited yet" last (D-148: the
     * members of a team that waits for its own lomba have no `invited_at`), then by name, then by id. Sorted in PHP on
     * the loaded rows, never in SQL: MySQL puts NULL first in an ascending ORDER BY and leaves ties in no fixed order,
     * so the same team read differently on two screens. The name needs `student.user` loaded.
     *
     * @template TCollection of Collection<array-key, RegistrationMember>
     *
     * @param  TCollection  $members
     * @return TCollection
     */
    public static function inTeamOrder(Collection $members): Collection
    {
        return $members->sort(static fn (self $a, self $b): int => self::compareInTeam($a, $b))->values();
    }

    /** The comparison behind inTeamOrder(): negative when $a stands before $b. */
    public static function compareInTeam(self $a, self $b): int
    {
        return [$a->isLeader() ? 0 : 1, $a->invited_at === null ? 1 : 0, $a->invited_at?->getTimestamp() ?? 0]
            <=> [$b->isLeader() ? 0 : 1, $b->invited_at === null ? 1 : 0, $b->invited_at?->getTimestamp() ?? 0]
            ?: strcasecmp((string) $a->student?->user?->name, (string) $b->student?->user?->name)
            ?: strcmp((string) $a->getKey(), (string) $b->getKey());
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
