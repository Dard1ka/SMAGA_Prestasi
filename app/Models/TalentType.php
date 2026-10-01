<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Bidang;
use App\Enums\TalentTypeCode;
use Database\Factories\TalentTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Jenis talenta (OSN, Riset, FLS3N, O2SN, OLPAIN, Debat) → bidang (D-22). */
class TalentType extends Model
{
    /** @use HasFactory<TalentTypeFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'bidang',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'code' => TalentTypeCode::class,
            'bidang' => Bidang::class,
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<TalentBranch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(TalentBranch::class)->orderBy('sort_order');
    }

    /** @return HasMany<TalentApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(TalentApplication::class);
    }

    /** @param Builder<TalentType> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order');
    }

    public function requiresBranch(): bool
    {
        return $this->code->requiresBranch();
    }
}
