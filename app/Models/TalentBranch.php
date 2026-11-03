<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TalentBranchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cabang talenta (e.g. OSN › Kimia, FLS3N › Tari Kreasi with team_size 2). Admin/Kesiswaan may add more. Every jenis
 * with cabang ends with the cabang "Lainnya" (D-143): choosing it asks the student to name the talent ("Sebutkan").
 */
class TalentBranch extends Model
{
    /** @use HasFactory<TalentBranchFactory> */
    use HasFactory, HasUlids;

    /** Name of the cabang whose choice needs the student's own text (D-143, `TalentSeeder`). */
    public const OTHER = 'Lainnya';

    /** @var list<string> */
    protected $fillable = [
        'talent_type_id',
        'name',
        'team_size',
        'active',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'team_size' => 'integer',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<TalentType, $this> */
    public function talentType(): BelongsTo
    {
        return $this->belongsTo(TalentType::class);
    }

    /** @return HasMany<TalentApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(TalentApplication::class);
    }

    /** @param Builder<TalentBranch> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** The cabang "Lainnya" of its jenis (D-143). */
    public function isOther(): bool
    {
        return $this->name === self::OTHER;
    }

    public function isTeam(): bool
    {
        return $this->team_size !== null && $this->team_size > 1;
    }
}
