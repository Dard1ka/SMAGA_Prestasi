<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AppreciationStatus;
use Database\Factories\AppreciationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An achievement scheduled for (or given) appreciation. Not mass-assignable: status. */
class Appreciation extends Model
{
    /** @use HasFactory<AppreciationFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'achievement_id',
        'appreciation_event_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AppreciationStatus::class,
        ];
    }

    /** @return BelongsTo<Achievement, $this> */
    public function achievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class);
    }

    /** @return BelongsTo<AppreciationEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(AppreciationEvent::class, 'appreciation_event_id');
    }
}
