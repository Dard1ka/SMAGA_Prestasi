<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LetterType;
use Database\Factories\LetterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Surat tugas. Unique (type, full_number) and (registration_id, type, version); immutable after issue.
 * M1-A (lead, 2026-10-04): `full_number` is the complete number typed by Tata Usaha; `sequence` is NULL on new
 * rows (old rows and the demo data keep theirs). Each letter stores its own data: issued_on, basis_from /
 * basis_number / basis_date / basis_subject and schedule_day / schedule_date / schedule_time / place.
 * verification_code is filled with 32 random hex chars on create when empty (public /verifikasi-surat/{code}).
 * Correction (B3, backlog #11): the old row gets revoked_at/revoked_by/revoke_reason, the new row has
 * version + 1, a NEW number and replaces_letter_id → the old row. "Current" = revoked_at IS NULL.
 * Not mass-assignable: verification_code, generated_by, generated_at, sent_at, version, replaces_letter_id,
 * signature_file_id, revoked_at, revoked_by, revoke_reason.
 */
class Letter extends Model
{
    /** @use HasFactory<LetterFactory> */
    use HasFactory, HasUlids, LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'registration_id',
        'type',
        'sequence',
        'full_number',
        'year',
        'month',
        'issued_on',
        'basis_from',
        'basis_number',
        'basis_date',
        'basis_subject',
        'file_id',
        'schedule_day',
        'schedule_date',
        'schedule_time',
        'place',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => LetterType::class,
            'sequence' => 'integer',
            'year' => 'integer',
            'month' => 'integer',
            'issued_on' => 'immutable_date',
            'basis_date' => 'immutable_date',
            'generated_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'version' => 'integer',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Letter $letter): void {
            if (blank($letter->verification_code)) {
                $letter->verification_code = self::newVerificationCode();
            }
        });
    }

    public static function newVerificationCode(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('letter')
            ->logAll()
            ->logExcept(['created_at', 'updated_at', 'verification_code'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Registration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return BelongsTo<User, $this> */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** @param Builder<Letter> $query */
    public function scopeOfType(Builder $query, LetterType $type): void
    {
        $query->where('type', $type);
    }

    // --- B3: versions (backlog #11) ---

    /** Not revoked (the version that counts). @param Builder<Letter> $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /** @return BelongsTo<Letter, $this> */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(Letter::class, 'replaces_letter_id');
    }

    /** The version issued to correct this one. @return HasOne<Letter, $this> */
    public function replacement(): HasOne
    {
        return $this->hasOne(Letter::class, 'replaces_letter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** @return BelongsTo<File, $this> */
    public function signatureFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'signature_file_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    // --- end B3 ---

    /** Highest sequence of the old numbering for a type/year (rows issued before M1-A and the demo data). */
    public static function lastSequence(LetterType $type, int $year): ?int
    {
        $max = static::query()->where('type', $type)->where('year', $year)->max('sequence');

        return $max === null ? null : (int) $max;
    }

    /** The number of the newest letter of a type, revoked ones included ("Terakhir dipakai" hint for TU). */
    public static function lastNumber(LetterType $type): ?string
    {
        $number = static::query()
            ->where('type', $type)
            ->orderByDesc('generated_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('full_number');

        return is_string($number) && $number !== '' ? $number : null;
    }

    /**
     * Is this number already used by a letter of the type (any version, revoked too)? Compared without spaces
     * and without case (LetterNumber::key()). UI hint + early check; the unique index (type, full_number) decides.
     */
    public static function numberTaken(LetterType $type, string $key): bool
    {
        return static::query()
            ->where('type', $type)
            ->whereRaw("LOWER(REPLACE(full_number, ' ', '')) = ?", [$key])
            ->exists();
    }

    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }
}
