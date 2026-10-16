<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\TalentType;

/**
 * Tests only. D-144, D-153: a student without a talent choice is held on Talenta saya (EnsureTalentChosen). A test
 * whose kelas X student opens another siswa page gives that student one choice first: the jenis "Lainnya" with the
 * text "Membaca" (the seeded jenis when TalentSeeder ran, else a factory one).
 */
final class TalentGate
{
    public static function pass(Student $student): TalentApplication
    {
        $type = TalentType::query()->where('code', TalentTypeCode::Lainnya)->first()
            ?? TalentType::factory()->code(TalentTypeCode::Lainnya)->create();

        $application = new TalentApplication;
        $application->forceFill([
            'student_id' => $student->getKey(),
            'talent_type_id' => $type->getKey(),
            'talent_branch_id' => null,
            'other_text' => 'Membaca',
            'status' => TalentStatus::Terdaftar,
        ])->save();

        return $application;
    }
}
