<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Bidang;
use App\Enums\CompetitionStatus;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\Level;
use App\Enums\OrganizerType;
use App\Enums\Participation;
use App\Enums\Permission;
use App\Enums\RegistrationType;
use App\Enums\WeightCategory;
use Database\Factories\CompetitionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Lomba (catalog entry or student proposal). Every create starts at menunggu_verifikasi (D-27).
 * Not mass-assignable: status, submitted_by, verified_by, verified_at, verification_note (set in Actions).
 */
class Competition extends Model
{
    /** @use HasFactory<CompetitionFactory> */
    use HasFactory, HasUlids, LogsActivity, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'description',
        'bidang',
        'organizer_name',
        'organizer_type',
        'weight_category',
        'event_status',
        'level',
        'mode',
        'registration_opens_at',
        'registration_closes_at',
        'starts_on',
        'final_on',
        'location',
        'participation',
        'max_team_size',
        'default_mentor_id',
        'website_url',
        'registration_url',
        'juknis_file_id',
        'cover_file_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'menunggu_verifikasi',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bidang' => Bidang::class,
            'organizer_type' => OrganizerType::class,
            'weight_category' => WeightCategory::class,
            'event_status' => EventStatus::class,
            'level' => Level::class,
            'mode' => EventMode::class,
            'participation' => Participation::class,
            'status' => CompetitionStatus::class,
            'registration_opens_at' => 'immutable_datetime',
            'registration_closes_at' => 'immutable_datetime',
            'starts_on' => 'immutable_date',
            'final_on' => 'immutable_date',
            'max_team_size' => 'integer',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('competition')
            ->logAll()
            ->logExcept(['created_at', 'updated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    // Relations ------------------------------------------------------------------------------

    /** @return BelongsTo<User, $this> */
    public function defaultMentor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_mentor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return BelongsTo<File, $this> */
    public function juknisFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'juknis_file_id');
    }

    /** @return BelongsTo<File, $this> */
    public function coverFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'cover_file_id');
    }

    /** @return HasMany<Registration, $this> */
    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    /** @return HasMany<Achievement, $this> */
    public function achievements(): HasMany
    {
        return $this->hasMany(Achievement::class);
    }

    // Scopes ---------------------------------------------------------------------------------

    /** Visible in the student catalog (D-27). @param Builder<Competition> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', CompetitionStatus::Dipublikasikan);
    }

    /** Kesiswaan verification queue (catalog uploads and student proposals). @param Builder<Competition> $query */
    public function scopePendingVerification(Builder $query): void
    {
        $query->where('status', CompetitionStatus::MenungguVerifikasi);
    }

    /** Published and currently accepting registrations. @param Builder<Competition> $query */
    public function scopeOpenForRegistration(Builder $query, ?DateTimeInterface $now = null): void
    {
        $now ??= now();

        $query->published()
            ->where(fn (Builder $q) => $q->whereNull('registration_opens_at')->orWhere('registration_opens_at', '<=', $now))
            ->where('registration_closes_at', '>=', $now);
    }

    /** @param Builder<Competition> $query */
    public function scopeForBidang(Builder $query, Bidang $bidang): void
    {
        $query->where('bidang', $bidang);
    }

    /**
     * W2-A1: rows CompetitionPolicy::view allows — published ones, the user's own uploads, and
     * everything for verifiers (competition.verify). @param Builder<Competition> $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can(Permission::CompetitionVerify->value)) {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->where('status', CompetitionStatus::Dipublikasikan)
            ->orWhere('submitted_by', $user->getKey()));
    }

    // Helpers --------------------------------------------------------------------------------

    public function isPublished(): bool
    {
        return $this->status === CompetitionStatus::Dipublikasikan;
    }

    public function isOpenForRegistration(?DateTimeInterface $now = null): bool
    {
        $now ??= now();

        return $this->isPublished()
            && ($this->registration_opens_at === null || $this->registration_opens_at <= $now)
            && $this->registration_closes_at !== null
            && $this->registration_closes_at >= $now;
    }

    public function allowsRegistrationType(RegistrationType $type): bool
    {
        return $this->participation instanceof Participation && $this->participation->allows($type);
    }
}
