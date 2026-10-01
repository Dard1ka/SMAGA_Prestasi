<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * Account for every role. users.id stays BIGINT (ARCHITECTURE §4).
 * Students sign in with NIS/NISN (via `student`), staff with username or email.
 *
 * Not mass-assignable on purpose (CLAUDE.md §3.6): status, must_change_password, password_changed_at,
 * last_login_at, email_verified_at and the B1 account-control columns (initial_password_expires_at,
 * failed_activation_attempts, frozen_at, frozen_reason, activated_at). Set them with forceFill() inside Actions.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            // B1 account controls (K1/K3/K8/K9); App\Actions\Accounts\AccountControls reads them either way.
            'initial_password_expires_at' => 'immutable_datetime',
            'failed_activation_attempts' => 'integer',
            'frozen_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
        ];
    }

    /** Audit account changes (never the password, 2FA secrets or tokens). */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('user')
            ->logOnly(['name', 'username', 'email', 'status', 'must_change_password'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    // Relations ------------------------------------------------------------------------------

    /** @return HasOne<Student, $this> */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    /** @return HasOne<StaffProfile, $this> */
    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    /** Registrations this user guides as guru pembimbing. @return HasMany<Registration, $this> */
    public function mentoredRegistrations(): HasMany
    {
        return $this->hasMany(Registration::class, 'mentor_id');
    }

    /** Talent applications this user guides as guru pembina. @return HasMany<TalentApplication, $this> */
    public function mentoredTalentApplications(): HasMany
    {
        return $this->hasMany(TalentApplication::class, 'mentor_id');
    }

    /** @return HasMany<Competition, $this> */
    public function submittedCompetitions(): HasMany
    {
        return $this->hasMany(Competition::class, 'submitted_by');
    }

    /** @return HasMany<OtpCode, $this> */
    public function otpCodes(): HasMany
    {
        return $this->hasMany(OtpCode::class);
    }

    /** @return HasMany<File, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(File::class, 'owner_user_id');
    }

    // Scopes ---------------------------------------------------------------------------------

    /** Accounts that may sign in (pending_activation or active). @param Builder<User> $query */
    public function scopeCanLogIn(Builder $query): void
    {
        $query->whereIn('status', [UserStatus::PendingActivation, UserStatus::Active]);
    }

    /** @param Builder<User> $query */
    public function scopeStaff(Builder $query): void
    {
        $query->whereHas('roles', fn (Builder $q) => $q->where('name', '!=', RoleName::Siswa->value));
    }

    /** Guru pembimbing/pembina (pick lists). @param Builder<User> $query */
    public function scopeGurus(Builder $query): void
    {
        $query->role(RoleName::Guru->value);
    }

    // Helpers --------------------------------------------------------------------------------

    public function canLogIn(): bool
    {
        return $this->status instanceof UserStatus && $this->status->canLogIn();
    }

    public function isArchived(): bool
    {
        return $this->status === UserStatus::Archived;
    }

    public function isStaff(): bool
    {
        return $this->hasAnyRole(array_map(fn (RoleName $r) => $r->value, RoleName::staff()));
    }

    public function hasRoleName(RoleName $role): bool
    {
        return $this->hasRole($role->value);
    }
}
