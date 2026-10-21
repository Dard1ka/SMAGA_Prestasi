<?php

declare(strict_types=1);

use App\Actions\Publications\FlyerData;
use App\Models\Achievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
| FlyerData (M3-E): the facts Humas types into the flyer it makes itself. STH generates no flyer.
| One fact per line, only what is known, every student named except a disabled or deleted account (D-162: no consent), no points,
| no NIS/NISN. Fictional data only (DESIGN-SPEC-v2 §5).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
});

/** @return array<string, list<string>> label => values */
function m3eRows(Achievement $achievement): array
{
    return collect(FlyerData::of($achievement->fresh())->rows)
        ->mapWithKeys(fn (array $row): array => [$row['label'] => $row['values']])
        ->all();
}

it('gives every known fact of one student, in the order of the screen', function (): void {
    $achievement = HumasFixtures::achievement();
    $achievement->competition->forceFill([
        'organizer_name' => 'Pusat Prestasi Nasional',
        'starts_on' => '2026-11-05',
        'final_on' => '2026-11-07',
    ])->save();
    $achievement->report->forceFill(['research_title' => "Biofilter Limbah Batik\n  dari Sabut Kelapa"])->save();
    $achievement->report->registration->forceFill(['branch' => 'Karya Ilmiah Terapan'])->save();

    $data = FlyerData::of($achievement->fresh());

    expect($data->rows)->toBe([
        ['label' => 'Nama', 'values' => ['Nadia Putri Anindya'], 'list' => false],
        ['label' => 'Kelas', 'values' => ['XI-D'], 'list' => false],
        ['label' => 'Capaian', 'values' => ['Juara II'], 'list' => false],
        ['label' => 'Lomba', 'values' => ['OPSI 2026'], 'list' => false],
        ['label' => 'Cabang', 'values' => ['Karya Ilmiah Terapan'], 'list' => false],
        ['label' => 'Tingkat', 'values' => ['Nasional'], 'list' => false],
        ['label' => 'Penyelenggara', 'values' => ['Pusat Prestasi Nasional'], 'list' => false],
        ['label' => 'Bidang', 'values' => ['Riset'], 'list' => false],
        ['label' => 'Tanggal lomba', 'values' => ['5–7 November 2026'], 'list' => false],
        ['label' => 'Guru pembimbing', 'values' => ['Bu Retno Wulandari, S.Pd.'], 'list' => false],
        // One line: a line break typed into the title must not split the fact.
        ['label' => 'Judul penelitian', 'values' => ['Biofilter Limbah Batik dari Sabut Kelapa'], 'list' => false],
    ])->and($data->notes)->toBe([]);
});

it('leaves out what is not known instead of printing a dash', function (): void {
    $achievement = HumasFixtures::achievement();
    $achievement->competition->forceFill(['organizer_name' => null, 'starts_on' => null, 'final_on' => null])->save();
    $achievement->report->registration->forceFill(['mentor_id' => null])->save();

    expect(array_keys(m3eRows($achievement)))->toBe(['Nama', 'Kelas', 'Capaian', 'Lomba', 'Tingkat', 'Bidang']);
});

it('writes the lomba dates in full', function (?string $start, ?string $final, string $expected): void {
    $achievement = HumasFixtures::achievement();
    $achievement->competition->forceFill(['starts_on' => $start, 'final_on' => $final])->save();

    expect(m3eRows($achievement)['Tanggal lomba'])->toBe([$expected]);
})->with([
    'one day' => ['2026-11-07', '2026-11-07', '7 November 2026'],
    'same month' => ['2026-11-05', '2026-11-07', '5–7 November 2026'],
    'two months' => ['2026-10-30', '2026-11-02', '30 Oktober – 2 November 2026'],
    'two years' => ['2026-12-28', '2027-01-03', '28 Desember 2026 – 3 Januari 2027'],
    'only the final date' => [null, '2026-11-07', '7 November 2026'],
    'only the start date' => ['2026-11-05', null, '5 November 2026'],
    'final before start (bad catalog data)' => ['2026-11-09', '2026-11-07', '7 November 2026'],
]);

it('never carries points, NIS/NISN, birth date, phone or email', function (): void {
    $achievement = HumasFixtures::achievement();
    HumasFixtures::teammate($achievement, 'Raka Pradipta', 'XII-B');
    $data = FlyerData::of($achievement->fresh());
    $text = (string) json_encode([$data->rows, $data->notes], JSON_UNESCAPED_UNICODE);

    expect(array_column($data->rows, 'label'))->not->toContain('Poin');

    $members = $achievement->report->achievements()->with('student.user')->get();
    expect($members)->toHaveCount(2);

    foreach ($members as $member) {
        $private = array_filter([
            (string) $member->student->nis,
            (string) $member->student->nisn,
            $member->student->birth_date->format('Y-m-d'),
            (string) $member->student->user->email,
            (string) $member->student->phone,
            (string) $member->points,
        ]);

        foreach ($private as $value) {
            expect($text)->not->toContain($value);
        }
    }
});

it('lists a team with the ketua first, then by name, each with class', function (): void {
    $achievement = HumasFixtures::achievement(studentName: 'Nadia Putri Anindya');
    HumasFixtures::teammate($achievement, 'Salsabila Kirana', 'X-C');
    HumasFixtures::teammate($achievement, 'Dimas Arya Nugroho', 'XII-B');

    $data = FlyerData::of($achievement->fresh());

    expect($data->rows[0])->toBe([
        'label' => 'Anggota tim',
        'values' => ['Nadia Putri Anindya (XI-D)', 'Dimas Arya Nugroho (XII-B)', 'Salsabila Kirana (X-C)'],
        'list' => true,
    ])
        ->and(array_column($data->rows, 'label'))->not->toContain('Nama')->not->toContain('Kelas')
        ->and($data->rows[1])->toBe(['label' => 'Capaian', 'values' => ['Juara II'], 'list' => false])
        ->and($data->notes)->toBe([]);

    // The same list from a teammate's own publication: one team, one set of facts.
    $other = Achievement::query()->where('report_id', $achievement->report_id)->whereKeyNot($achievement->getKey())->firstOrFail();
    expect(FlyerData::of($other)->rows[0])->toBe($data->rows[0]);
});

it('names every member and adds no note, whatever the old consent columns say (D-162)', function (): void {
    $achievement = HumasFixtures::achievement(['photo_consent' => false, 'publish_consent' => false]);
    HumasFixtures::teammate($achievement, 'Raka Pradipta', 'XII-B', ['publish_consent' => false]);
    HumasFixtures::teammate($achievement, 'Salsabila Kirana', 'X-C', ['photo_consent' => false]);

    $data = FlyerData::of($achievement->fresh());
    $text = (string) json_encode([$data->rows, $data->notes], JSON_UNESCAPED_UNICODE);

    expect($data->rows[0]['values'])->toBe(['Nadia Putri Anindya (XI-D)', 'Raka Pradipta (XII-B)', 'Salsabila Kirana (X-C)'])
        ->and($data->notes)->toBe([])
        ->and($text)->not->toContain('mengizinkan');

    $single = HumasFixtures::achievement(['photo_consent' => false, 'publish_consent' => false], studentName: 'Hana Safira', competitionName: 'FLS3N 2026');
    $one = FlyerData::of($single->fresh());

    expect($one->rows[0])->toBe(['label' => 'Nama', 'values' => ['Hana Safira'], 'list' => false])
        ->and($one->rows[1])->toBe(['label' => 'Kelas', 'values' => ['XI-D'], 'list' => false])
        ->and($one->notes)->toBe([]);
});

it('leaves a team member with a disabled or deleted account out of the list and only counts them', function (): void {
    $achievement = HumasFixtures::achievement();
    HumasFixtures::teammate($achievement, 'Raka Pradipta', 'XII-B');
    HumasFixtures::teammate($achievement, 'Fajar Ramadhan', 'XII-E', disabled: true);
    HumasFixtures::teammate($achievement, 'Bima Satria Wicaksono', 'XII-F')->student->delete();

    $data = FlyerData::of($achievement->fresh());
    $text = (string) json_encode([$data->rows, $data->notes], JSON_UNESCAPED_UNICODE);

    expect($data->rows[0]['values'])->toBe(['Nadia Putri Anindya (XI-D)', 'Raka Pradipta (XII-B)'])
        ->and($data->notes)->toBe(['2 anggota tim tidak ditampilkan karena akunnya nonaktif.'])
        ->and($text)->not->toContain('Fajar Ramadhan')
        ->not->toContain('Bima Satria Wicaksono')
        ->not->toContain('XII-E')
        ->not->toContain('XII-F');
});

it('names nobody when every member of the team has a disabled account', function (): void {
    $achievement = HumasFixtures::achievement(disabled: true);
    HumasFixtures::teammate($achievement, 'Fajar Ramadhan', 'XII-E', disabled: true);

    $data = FlyerData::of($achievement->fresh());
    $text = (string) json_encode([$data->rows, $data->notes], JSON_UNESCAPED_UNICODE);

    expect($data->rows[0])->toBe(['label' => 'Nama', 'values' => ['Siswa kelas XI'], 'list' => false])
        ->and(array_column($data->rows, 'label'))->not->toContain('Anggota tim')->not->toContain('Kelas')
        ->and($data->notes)->toBe(['2 anggota tim tidak ditampilkan karena akunnya nonaktif.'])
        ->and($text)->not->toContain('Nadia Putri Anindya')
        ->not->toContain('Fajar Ramadhan')
        ->not->toContain('XI-D')
        ->not->toContain('XII-E');
});

it('says so when the one student has a disabled account', function (): void {
    $achievement = HumasFixtures::achievement(disabled: true);

    $data = FlyerData::of($achievement->fresh());

    expect($data->rows[0])->toBe(['label' => 'Nama', 'values' => ['Siswa kelas XI'], 'list' => false])
        ->and($data->notes)->toBe(['Akun siswa ini nonaktif, jadi namanya tidak ditampilkan.']);
});
