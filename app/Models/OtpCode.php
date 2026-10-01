<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OtpPurpose;
use Database\Factories\OtpCodeFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hashed 6-digit email code (10-minute TTL, 5 attempts). The plain code is never stored.
 * Not mass-assignable: attempts, used_at.
 */
class OtpCode extends Model
{
    /** @use HasFactory<OtpCodeFactory> */
    use HasFactory, HasUlids;

    public const MAX_ATTEMPTS = 5;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'purpose',
        'email',
        'code_hash',
        'expires_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'code_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Not used, not expired, attempts left. @param Builder<OtpCode> $query */
    public function scopeUsable(Builder $query, ?DateTimeInterface $now = null): void
    {
        $query->whereNull('used_at')
            ->where('expires_at', '>', $now ?? now())
            ->where('attempts', '<', self::MAX_ATTEMPTS);
    }
}
