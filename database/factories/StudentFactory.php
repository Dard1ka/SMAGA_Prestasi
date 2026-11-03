<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Archive\GraduationCalculator;
use App\Domain\Archive\GraduationDates;
use App\Enums\RoleName;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Spatie\Permission\Models\Role;

/**
 * Fictional student (NIS 9 digits, NISN 10 digits, random). The user gets the `siswa` role.
 * The password is the factory default ("password"), not the initial-password pattern.
 *
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->pendingActivation(),
            'nis' => fake()->unique()->numerify('2262#####'),
            'nisn' => fake()->unique()->numerify('01########'),
            'birth_date' => fake()->dateTimeBetween('2010-01-01', '2011-06-30')->format('Y-m-d'),
            'classroom_id' => Classroom::factory(),
            'import_school_year' => '2026/2027',
            'grade12_start_date' => fn (array $attributes) => $this->graduation($attributes)->grade12StartDate->format('Y-m-d'),
            'archive_at' => fn (array $attributes) => $this->graduation($attributes)->archiveAt->format('Y-m-d'),
            'phone' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Student $student): void {
            $student->user()->first()?->assignRole(Role::findOrCreate(RoleName::Siswa->value, 'web'));
        });
    }

    /** Student who finished the first-login activation (active, email bound, `activated_at` set for the K9 report). */
    public function activated(): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => User::factory()->state(['activated_at' => now()])]);
    }

    /** D-152: the student already confirmed birth date + No HP in the activation step "Data diri" (fictional number). */
    public function profileConfirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'phone' => '081234567890',
            'profile_confirmed_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => User::factory()->archived()]);
    }

    /** @param array<string, mixed> $attributes */
    private function graduation(array $attributes): GraduationDates
    {
        $level = Classroom::query()->whereKey($attributes['classroom_id'])->value('level') ?? 10;

        return (new GraduationCalculator)->calculate((int) $level, (string) $attributes['import_school_year']);
    }
}
