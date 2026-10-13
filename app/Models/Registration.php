<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use Carbon\CarbonImmutable;
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
 * Not mass-assignable: status, principal_decision_by, principal_decided_at, principal_note, cancelled_at, answer_until.
 */
class Registration extends Model
{
    /** D-148: a team resumed after (or less than this many days before) "Pendaftaran ditutup" may answer this long. */
    public const ANSWER_GRACE_DAYS = 3;

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
            'answer_until' => 'immutable_datetime',
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

    /**
     * Current letters only (not revoked; B3 letter correction, backlog #11). Every version, incl. the
     * revoked ones, is in letterHistory(). @return HasMany<Letter, $this>
     */
    public function letters(): HasMany
    {
        return $this->hasMany(Letter::class)->whereNull('revoked_at');
    }

    /** All versions (revoked included), for TU/audit screens. @return HasMany<Letter, $this> */
    public function letterHistory(): HasMany
    {
        return $this->hasMany(Letter::class);
    }

    /** @return HasOne<Letter, $this> */
    public function studentLetter(): HasOne
    {
        return $this->hasOne(Letter::class)->where('type', LetterType::Siswa)->whereNull('revoked_at');
    }

    /** @return HasOne<Letter, $this> */
    public function guruLetter(): HasOne
    {
        return $this->hasOne(Letter::class)->where('type', LetterType::Guru)->whereNull('revoked_at');
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

    /**
     * D-148: registrations the guru pembimbing was ever told about (Siswa binaan, Guru › Lomba, detail binaan). One that
     * still waits for its lomba (`menunggu_lomba`), or was cancelled while it waited (by its leader, or with the
     * rejected lomba: audit `registration.cancelled` from `menunggu_lomba`), only its submitter ever heard of.
     *
     * @param  Builder<Registration>  $query
     */
    public function scopeKnownToMentor(Builder $query): void
    {
        $query->where('status', '!=', RegistrationStatus::MenungguLomba->value)
            ->where(fn (Builder $q) => $q
                ->where('status', '!=', RegistrationStatus::Dibatalkan->value)
                ->orWhereDoesntHave('activitiesAsSubject', fn (Builder $audit) => $audit
                    ->where('event', 'registration.cancelled')
                    ->where('properties->from', RegistrationStatus::MenungguLomba->value)));
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

    /**
     * Until when the team's invitations may be answered, and the ketua may invite or withdraw (QA10 window): the later
     * of the lomba's "Pendaftaran ditutup" and `answer_until` (the grace of a team resumed late, D-148); null when
     * neither is set (no deadline: the invitations never expire). RegistrationPolicy and ExpirePendingInvitations read
     * only this.
     */
    public function answersCloseAt(): ?CarbonImmutable
    {
        $competition = $this->relationLoaded('competition') && $this->competition !== null
            ? $this->competition
            : Competition::withTrashed()->find($this->competition_id);
        $closes = $competition?->registration_closes_at;
        $until = $this->answer_until;

        if ($closes === null || $until === null) {
            return $closes ?? $until;
        }

        return $until->greaterThan($closes) ? $until : $closes;
    }

    /**
     * "15 Okt 2026, 09.00": until when the anggota of a team resumed late may answer (the grace, D-148), for the screens
     * and the invitation; null for every other registration, whose answers close with "Pendaftaran ditutup".
     */
    public function graceText(): ?string
    {
        if ($this->answer_until === null) {
            return null;
        }

        return $this->answersCloseAt()?->locale('id')->translatedFormat('j M Y, H.i');
    }

    /** D-148: cancelled because Kesiswaan rejected its lomba (ResumeWaitingRegistrations), not by its leader. */
    public function cancelledWithLomba(): bool
    {
        return $this->status === RegistrationStatus::Dibatalkan
            && $this->activitiesAsSubject()
                ->where('event', 'registration.cancelled')
                ->where('properties->reason', 'competition_rejected')
                ->exists();
    }

    /** One registration of scopeKnownToMentor() (D-148). */
    public function isKnownToMentor(): bool
    {
        if ($this->status === RegistrationStatus::MenungguLomba) {
            return false;
        }

        return $this->status !== RegistrationStatus::Dibatalkan
            || ! $this->activitiesAsSubject()
                ->where('event', 'registration.cancelled')
                ->where('properties->from', RegistrationStatus::MenungguLomba->value)
                ->exists();
    }
}
