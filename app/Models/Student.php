<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RegistrationStatus;
use App\Enums\UserStatus;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Student record (the name lives on `user`). Public output must never include nis, nisn, phone or
 * birth_date (D-16); phone and birth_date are hidden from array/JSON serialisation as a safety net.
 * grade12_start_date / archive_at are computed by App\Domain\Archive\GraduationCalculator.
 */
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'nis',
        'nisn',
        'birth_date',
        'classroom_id',
        'import_school_year',
        'grade12_start_date',
        'archive_at',
        'phone',
        'photo_consent',
        'publish_consent',
    ];

    /** @var list<string> */
    protected $hidden = [
        'birth_date',
        'phone',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date',
            'grade12_start_date' => 'immutable_date',
            'archive_at' => 'immutable_date',
            'phone' => 'encrypted',
            'photo_consent' => 'boolean',
            'publish_consent' => 'boolean',
        ];
    }

    // Relations ------------------------------------------------------------------------------

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<Classroom, $this> */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /** Membership rows (ketua/anggota, confirmation state). @return HasMany<RegistrationMember, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(RegistrationMember::class);
    }

    /** Registrations this student is a member of (incl. as ketua). Read-only: create members via Registration::members(). */
    public function registrations(): BelongsToMany
    {
        return $this->belongsToMany(Registration::class, 'registration_members')
            ->withPivot(['id', 'role', 'invited_at', 'confirmed_at', 'declined_at'])
            ->withTimestamps();
    }

    /** @return HasMany<Registration, $this> */
    public function ledRegistrations(): HasMany
    {
        return $this->hasMany(Registration::class, 'leader_student_id');
    }

    /** @return HasMany<Achievement, $this> */
    public function achievements(): HasMany
    {
        return $this->hasMany(Achievement::class);
    }

    /** @return HasMany<TalentApplication, $this> */
    public function talentApplications(): HasMany
    {
        return $this->hasMany(TalentApplication::class);
    }

    /** @return HasOne<TalentProfile, $this> */
    public function talentProfile(): HasOne
    {
        return $this->hasOne(TalentProfile::class);
    }

    // Scopes ---------------------------------------------------------------------------------

    /** Current (not archived/disabled) students — "siswa aktif". @param Builder<Student> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereHas('user', fn (Builder $q) => $q->whereIn('status', [UserStatus::PendingActivation, UserStatus::Active]));
    }

    /** Archived students (chip "Alumni"). @param Builder<Student> $query */
    public function scopeAlumni(Builder $query): void
    {
        $query->whereHas('user', fn (Builder $q) => $q->where('status', UserStatus::Archived));
    }

    /** Students whose archive date has arrived (daily archive job). @param Builder<Student> $query */
    public function scopeDueForArchive(Builder $query, \DateTimeInterface $today): void
    {
        $query->whereDate('archive_at', '<=', $today)
            ->whereHas('user', fn (Builder $q) => $q->where('status', '!=', UserStatus::Archived));
    }

    /** Consent flag for public pages (D-16). @param Builder<Student> $query */
    public function scopePublishable(Builder $query): void
    {
        $query->where('publish_consent', true);
    }

    /**
     * Member search by name, NIS or NISN (D-03). Callers must only expose name + class.
     *
     * @param  Builder<Student>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $q) use ($term) {
            if (ctype_digit($term)) {
                $q->where('nis', $term)->orWhere('nisn', $term);
            }

            $q->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'));
        });
    }

    /**
     * Students already in a non-final registration of the competition (they cannot join another one).
     *
     * @param  Builder<Student>  $query
     */
    public function scopeBusyIn(Builder $query, Competition|string $competition): void
    {
        $competitionId = $competition instanceof Competition ? $competition->getKey() : $competition;

        $query->whereHas('memberships', fn (Builder $m) => $m
            ->whereNull('declined_at')
            ->whereHas('registration', fn (Builder $r) => $r
                ->where('competition_id', $competitionId)
                ->whereIn('status', RegistrationStatus::nonFinal())));
    }
}
