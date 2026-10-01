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
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Surat tugas. Unique (type, year, sequence) and (registration_id, type); immutable after issue.
 * verification_code is filled with 32 random hex chars on create when empty (public /verifikasi-surat/{code}).
 * Not mass-assignable: verification_code, generated_by, generated_at, sent_at.
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
        'basis_number',
        'basis_date',
        'file_id',
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

    /** Highest sequence used for a type/year ("Terakhir dipakai" hint for TU). */
    public static function lastSequence(LetterType $type, int $year): ?int
    {
        $max = static::query()->where('type', $type)->where('year', $year)->max('sequence');

        return $max === null ? null : (int) $max;
    }

    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }
}
