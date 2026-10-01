<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AppreciationEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An appreciation moment, e.g. "Upacara Senin, 5 Oktober 2026". */
class AppreciationEvent extends Model
{
    /** @use HasFactory<AppreciationEventFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'held_on',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'held_on' => 'immutable_date',
        ];
    }

    /** @return HasMany<Appreciation, $this> */
    public function appreciations(): HasMany
    {
        return $this->hasMany(Appreciation::class);
    }
}
