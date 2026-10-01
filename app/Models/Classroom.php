<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ClassroomFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Kelas: X-1…X-10, X-TAHFIDZ, X-OLIMP, XI-1…XI-12, XII-1…XII-12 (ClassroomSeeder). */
class Classroom extends Model
{
    /** @use HasFactory<ClassroomFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'level',
        'active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return HasMany<Student, $this> */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /** @param Builder<Classroom> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** Level order, then natural name order (X-2 before X-10, numbered before X-TAHFIDZ). @param Builder<Classroom> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('level')->orderByRaw('LENGTH(name)')->orderBy('name');
    }

    /** "XI-4" → 11, "X-TAHFIDZ" → 10; null when the name has no valid prefix. */
    public static function levelFromName(string $name): ?int
    {
        return match (strtoupper(strtok(trim($name), '-') ?: '')) {
            'X' => 10,
            'XI' => 11,
            'XII' => 12,
            default => null,
        };
    }
}
