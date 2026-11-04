<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ClassroomFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kelas: X-1…X-12, XI-1…XI-12, XII-1…XII-12 (ClassroomSeeder; numbers again since D-175). The Admin adds, renames and deletes
 * classes on Admin › Kelas (App\Actions\Classrooms, rules in App\Actions\Classrooms\ClassroomName).
 *
 * Soft-deleted = in the trash ("Sampah", the lead's decision of 5 Nov 2026): App\Actions\Trash\TrashClassroom puts a class
 * and all its students there, hidden from every list, select and the student import, until RestoreFromTrash brings it
 * back or the daily purge force-deletes it. Its name stays taken meanwhile (ClassroomName::assertUnique()). A delete that
 * must really remove the row (NumberClassrooms, DeleteClassroom, the purge) calls forceDelete().
 */
class Classroom extends Model
{
    /** @use HasFactory<ClassroomFactory> */
    use HasFactory, HasUlids, SoftDeletes;

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

    /** Level order, then shorter names first, then by name (X-1 … X-10 before a longer name such as X-IPA 1; an old numbered name X-2 before X-10). @param Builder<Classroom> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('level')->orderByRaw('LENGTH(name)')->orderBy('name');
    }

    /** "XI-4" → 11, "X-IPA 1" → 10; null when the name has no valid prefix. */
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
