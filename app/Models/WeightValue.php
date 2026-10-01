<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WeightGroup;
use Database\Factories\WeightValueFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One weight of a version. `group` is cast to WeightGroup; `key` is a Level / ResultType /
 * WeightCategory value; `value` is a 2-decimal string. Values are written once with their version.
 */
class WeightValue extends Model
{
    /** @use HasFactory<WeightValueFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'weight_version_id',
        'group',
        'key',
        'value',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'group' => WeightGroup::class,
            'value' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<WeightVersion, $this> */
    public function weightVersion(): BelongsTo
    {
        return $this->belongsTo(WeightVersion::class);
    }
}
