<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Points\PointCalculator;
use App\Domain\Points\WeightTable;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Enums\WeightCategory;
use App\Models\Achievement;
use App\Models\Competition;
use App\Models\Report;
use App\Models\Student;
use App\Models\WeightVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Default = Juara I · Nasional · kategori A = 80 points with the default weights.
 *
 * @extends Factory<Achievement>
 */
class AchievementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report_id' => Report::factory()->verified(),
            'student_id' => Student::factory(),
            'competition_id' => Competition::factory()->finished(),
            'result' => ResultType::Juara1,
            'level' => Level::Nasional,
            'weight_category' => WeightCategory::A,
            'w_level' => '80.00',
            'w_result' => '1.00',
            'w_organizer' => '1.00',
            'points' => '80.00',
            'weight_version_id' => WeightVersion::factory()->withDefaultValues(),
            'verified_at' => now(),
        ];
    }

    /** Snapshot weights + points for the given combination using the default weights. */
    public function scored(Level $level, ResultType $result, WeightCategory $category): static
    {
        $breakdown = (new PointCalculator)->breakdown(WeightTable::defaults(), $level, $result, $category);

        return $this->state(fn (array $attributes) => [
            'result' => $result,
            'level' => $level,
            'weight_category' => $category,
            ...$breakdown->toArray(),
        ]);
    }
}
