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

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
