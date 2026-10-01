<?php

declare(strict_types=1);

namespace Tests\Feature\Publik;

use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Enums\WeightCategory;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Achievement;
use App\Models\Classroom;
use App\Models\Competition;
use App\Models\Report;
use App\Models\Student;
use App\Models\User;
use App\Models\WeightVersion;

/**
 * Fictional data builder for the public page tests (names from DESIGN-SPEC-v2 §5 or invented).
 * One instance per test: it reuses one weight version and one verified report per lomba.
 */
final class PublikFixtures
{
    private ?WeightVersion $weights = null;

    /** @var array<string, Report> competition id => report */
    private array $reports = [];

    /** @var array<string, Classroom> */
    private array $classrooms = [];

    public function classroom(string $name): Classroom
    {
        $level = Classroom::levelFromName($name) ?? 10;

        return $this->classrooms[$name] ??= Classroom::factory()->create(['name' => $name, 'level' => $level]);
    }

    /**
     * @param  array<string, mixed>  $attributes  extra students columns (nis, nisn, grade12_start_date, …)
     */
    public function student(string $name, string $kelas = 'XI-4', bool $consent = true, bool $archived = false, array $attributes = []): Student
    {
        $user = ($archived ? User::factory()->archived() : User::factory())->create(['name' => $name]);

        return Student::factory()
            ->state(['publish_consent' => $consent, 'photo_consent' => $consent])
            ->create(['user_id' => $user->id, 'classroom_id' => $this->classroom($kelas)->id, ...$attributes]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function lomba(string $name, Bidang $bidang = Bidang::Riset, Level $level = Level::Nasional, string $finalOn = '2025-09-10', WeightCategory $category = WeightCategory::A, array $attributes = []): Competition
    {
        return Competition::factory()->finished()->create([
            'name' => $name,
            'bidang' => $bidang,
            'level' => $level,
            'weight_category' => $category,
            'organizer_name' => 'Puspresnas',
            'starts_on' => $finalOn,
            'final_on' => $finalOn,
            ...$attributes,
        ]);
    }

    /** The random public slug (assigned lazily by PublicAchievements for consenting students). */
    public function slug(Student $student): ?string
    {
        PublicAchievements::flush();
        app(PublicAchievements::class)->overview();

        return Student::query()->whereKey($student->id)->value('public_slug');
    }

    public function prestasi(Student $student, Competition $competition, ResultType $result = ResultType::Juara1, ?Level $level = null, ?WeightCategory $category = null, ?string $verifiedAt = null): Achievement
    {
        $this->weights ??= WeightVersion::factory()->withDefaultValues()->create();
        $report = $this->reports[$competition->id] ??= Report::factory()->verified()->create();

        return Achievement::factory()
            ->scored($level ?? $competition->level, $result, $category ?? $competition->weight_category)
            ->create([
                'report_id' => $report->id,
                'student_id' => $student->id,
                'competition_id' => $competition->id,
                'weight_version_id' => $this->weights->id,
                'verified_at' => $verifiedAt ?? now()->toDateTimeString(),
            ]);
    }
}
