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
 * Not mass-assignable: status, published_at, created_by, flyer_file_id.
 * `flyer_file_id` is legacy (M3-E): Humas makes its flyers outside STH, so nothing sets the column any more;
 * it only still points at the PNG of a publication whose flyer STH generated before 2026-10-06.
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

    /** The flyer STH generated before M3-E, if any (FilePolicy still guards its download). @return BelongsTo<File, $this> */
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
