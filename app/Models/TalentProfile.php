<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TalentProfileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** "Riwayat talenta" (Profil talenta): interests (≤ 4), skills, organizations, junior-high achievements. */
class TalentProfile extends Model
{
    /** @use HasFactory<TalentProfileFactory> */
    use HasFactory, HasUlids;

    public const MAX_INTERESTS = 4;

    public const MAX_CERTIFICATES = 2;

    /** @var list<string> */
    protected $fillable = [
        'student_id',
        'interests',
        'skills',
        'organizations',
        'junior_high_achievements',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'interests' => 'array',
        ];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * "Sertifikat pendukung" (W2-A2, max MAX_CERTIFICATES). Pivot rows need a ULID:
     * attach($fileId, ['id' => (string) Str::ulid()]) — done in App\Actions\Talent\SaveTalentProfile only.
     *
     * @return BelongsToMany<File, $this>
     */
    public function certificates(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'talent_profile_files')
            ->withPivot('id')
            ->withTimestamps()
            ->orderBy('talent_profile_files.created_at');
    }
}
