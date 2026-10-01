<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use Database\Factories\RegistrationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Pendaftaran (individu/tim). Status changes only in app/Actions/Registrations (ARCHITECTURE §6).
 * Not mass-assignable: status, principal_decision_by, principal_decided_at, principal_note, cancelled_at.
 */
class Registration extends Model
{
    /** @use HasFactory<RegistrationFactory> */
    use HasFactory, HasUlids, LogsActivity, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'competition_id',
        'leader_student_id',
        'type',
        'branch',
        'mentor_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => RegistrationType::class,
            'status' => RegistrationStatus::class,
            'principal_decided_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('registration')
            ->logAll()
            ->logExcept(['created_at', 'updated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    // Relations ------------------------------------------------------------------------------

    /** @return BelongsTo<Competition, $this> */
    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class)->withTrashed();
    }

    /** @return BelongsTo<Student, $this> */
    public function leader(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'leader_student_id');
    }

    /** @return BelongsTo<User, $this> */
    public function mentor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mentor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function principalDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'principal_decision_by');
    }

    /** Member rows incl. the ketua. Create members here: $registration->members()->create([...]). @return HasMany<RegistrationMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(RegistrationMember::class);
    }

    /** Read-only convenience (do not attach(): pivot rows need a ULID, use members()). */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'registration_members')
            ->withPivot(['id', 'role', 'invited_at', 'confirmed_at', 'declined_at'])
            ->withTimestamps();
    }

    /** @return HasMany<Letter, $this> */
    public function letters(): HasMany
    {
        return $this->hasMany(Letter::class);
    }

    /** @return HasOne<Letter, $this> */
    public function studentLetter(): HasOne
    {
        return $this->hasOne(Letter::class)->where('type', LetterType::Siswa);
    }

    /** @return HasOne<Letter, $this> */
    public function guruLetter(): HasOne
    {
        return $this->hasOne(Letter::class)->where('type', LetterType::Guru);
    }

    /** @return HasOne<Report, $this> */
    public function report(): HasOne
    {
        return $this->hasOne(Report::class);
    }

    // Scopes ---------------------------------------------------------------------------------

    /** @param Builder<Registration> $query */
    public function scopeStatus(Builder $query, RegistrationStatus ...$statuses): void
    {
        $query->whereIn('status', $statuses);
    }

    /** Kepala Sekolah queue. @param Builder<Registration> $query */
    public function scopeAwaitingPrincipal(Builder $query): void
    {
        $query->where('status', RegistrationStatus::MenungguKepsek);
    }

    /** TU queue (approved, letters not issued yet). @param Builder<Registration> $query */
    public function scopeAwaitingLetters(Builder $query): void
    {
        $query->where('status', RegistrationStatus::Disetujui);
    }

    /** Not ditolak / selesai / dibatalkan. @param Builder<Registration> $query */
    public function scopeNonFinal(Builder $query): void
    {
        $query->whereIn('status', RegistrationStatus::nonFinal());
    }

    /** Registrations where the student is a member (ketua or anggota). @param Builder<Registration> $query */
    public function scopeForStudent(Builder $query, Student|string $student): void
    {
        $studentId = $student instanceof Student ? $student->getKey() : $student;

        $query->whereHas('members', fn (Builder $m) => $m->where('student_id', $studentId));
    }

    // Helpers --------------------------------------------------------------------------------

    public function phase(): int
    {
        return $this->status->phase();
    }

    public function isTeam(): bool
    {
        return $this->type === RegistrationType::Tim;
    }
}
