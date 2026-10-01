<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReportFileKind;
use App\Models\File;
use App\Models\Report;
use App\Models\ReportFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportFile>
 */
class ReportFileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report_id' => Report::factory(),
            'kind' => ReportFileKind::Sertifikat,
            'file_id' => File::factory(),
        ];
    }
}
