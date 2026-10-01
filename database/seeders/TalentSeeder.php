<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TalentTypeCode;
use App\Models\TalentBranch;
use App\Models\TalentType;
use Illuminate\Database\Seeder;

/**
 * 6 talent types + branches from docs/TALENT-FORM.md; bidang mapping per D-22.
 * Idempotent (keyed by code / type+name): safe in production; Admin-added branches are kept.
 */
class TalentSeeder extends Seeder
{
    /** @var array<string, list<array{0: string, 1?: int}>> code => [name, team size?] in form order */
    private const BRANCHES = [
        'osn' => [
            ['Fisika'], ['Kimia'], ['Biologi'], ['Matematika'], ['Komputer'], ['Koding dan AI'],
            ['Astronomi'], ['Kebumian'], ['Geografi'], ['Ekonomi'],
        ],
        'riset' => [
            ['OPSI'], ['FIKSI'],
        ],
        'fls3n' => [
            ['Baca Puisi'], ['Cipta Lagu'], ['Cipta Puisi'], ['Desain Poster'], ['Fotografi'],
            ['Instrumen Solo Gitar'], ['Menyanyi Solo Putra'], ['Menyanyi Solo Putri'], ['Jurnalistik'],
            ['Kriya'], ['Komik Digital'], ['Menulis Cerita Pendek'], ['Monolog'],
            ['Tari Kreasi', 2], ['Film Pendek', 3], ['Kreativitas Musik Tradisional', 5],
        ],
        'o2sn' => [
            ['Atletik'], ['Renang'], ['Bulu Tangkis'], ['Pencak Silat'], ['Karate'],
        ],
        'olpain' => [],
        'debat' => [
            ['Debat Bahasa Indonesia (LDBI)'], ['Debat Bahasa Inggris (NSDC)'],
        ],
    ];

    public function run(): void
    {
        $typeOrder = 0;

        foreach (self::BRANCHES as $code => $branches) {
            $enum = TalentTypeCode::from($code);

            $type = TalentType::query()->updateOrCreate(
                ['code' => $enum->value],
                ['name' => $enum->label(), 'bidang' => $enum->bidang(), 'sort_order' => ++$typeOrder],
            );

            foreach ($branches as $order => $branch) {
                TalentBranch::query()->updateOrCreate(
                    ['talent_type_id' => $type->getKey(), 'name' => $branch[0]],
                    ['team_size' => $branch[1] ?? null, 'active' => true, 'sort_order' => $order + 1],
                );
            }
        }
    }
}
