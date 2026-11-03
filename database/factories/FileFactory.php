<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FilePurpose;
use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Metadata only — no bytes are written to disk. Use Storage::fake('local') in tests that stream files.
 *
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'disk' => 'local',
            'path' => 'lampiran/2026/10/'.Str::lower((string) Str::ulid()).'.pdf',
            'original_name' => 'sertifikat-'.fake()->slug(2).'.pdf',
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(20_000, 2_000_000),
            'sha256' => hash('sha256', Str::random(40)),
            'purpose' => FilePurpose::ReportEvidence,
            'owner_user_id' => null,
        ];
    }

    public function purpose(FilePurpose $purpose): static
    {
        return $this->state(fn (array $attributes) => ['purpose' => $purpose]);
    }

    public function image(): static
    {
        return $this->state(fn (array $attributes) => [
            'path' => 'lampiran/2026/10/'.Str::lower((string) Str::ulid()).'.jpg',
            'original_name' => 'foto-'.fake()->slug(2).'.jpg',
            'mime' => 'image/jpeg',
        ]);
    }
}
