<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Publications\NamedStudent;
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
 * The columns `photo_consent` / `publish_consent` stay in the table but are unused since D-162 (20 Okt 2026):
 * not fillable, not cast, never read (production migrations are forward-only).
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
            // D-152: set by CompleteActivation::confirmProfile() only, never mass-assigned.
            'profile_confirmed_at' => 'immutable_datetime',
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

    /**
     * Students whose archive date has arrived (daily archive job): only accounts that can log in. A disabled account
     * stays disabled (QA9): archiving it would bring its name back on the public pages as an alumnus.
     *
     * @param  Builder<Student>  $query
     */
    public function scopeDueForArchive(Builder $query, \DateTimeInterface $today): void
    {
        $query->whereDate('archive_at', '<=', $today)
            ->whereHas('user', fn (Builder $q) => $q->whereIn('status', [UserStatus::PendingActivation, UserStatus::Active]));
    }

    /**
     * Students who may be named on the public pages and in the Humas screens: NamedStudent (not deleted, account not
     * disabled; alumni stay). No consent condition since D-162. @param Builder<Student> $query
     */
    public function scopePublishable(Builder $query): void
    {
        NamedStudent::constrain($query);
    }

    /**
     * Member search by name or exact NISN (D-03, D-137: the NIS is stored only). Callers must only expose name + class.
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
                $q->where('nisn', $term);
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
