<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PublicationStatus;
use Database\Factories\PublicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Humas publication of one achievement (unique achievement_id). channels = list of PublicationChannel values.
 * Not mass-assignable: status, published_at, created_by.
 */
class Publication extends Model
{
    /** @use HasFactory<PublicationFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'achievement_id',
        'channels',
        'scheduled_at',
        'caption',
        'flyer_file_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PublicationStatus::class,
            'channels' => 'array',
            'scheduled_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Achievement, $this> */
    public function achievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class);
    }

    /** @return BelongsTo<File, $this> */
    public function flyerFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'flyer_file_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<Publication> $query */
    public function scopeInStatus(Builder $query, PublicationStatus $status): void
    {
        $query->where('status', $status);
    }
}
