<?php

declare(strict_types=1);

use App\Actions\Publications\FlyerData;
use App\Models\Achievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
| FlyerData (M3-E): the facts Humas types into the flyer it makes itself. STH generates no flyer.
| One fact per line, only what is known, never a student without publish consent (D-16, D-71), no points,
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
        ['label' => 'Kelas', 'values' => ['XI-4'], 'list' => false],
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
    HumasFixtures::teammate($achievement, 'Raka Pradipta', 'XII-2');
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
    HumasFixtures::teammate($achievement, 'Salsabila Kirana', 'X-3');
    HumasFixtures::teammate($achievement, 'Dimas Arya Nugroho', 'XII-2');

    $data = FlyerData::of($achievement->fresh());

    expect($data->rows[0])->toBe([
        'label' => 'Anggota tim',
        'values' => ['Nadia Putri Anindya (XI-4)', 'Dimas Arya Nugroho (XII-2)', 'Salsabila Kirana (X-3)'],
        'list' => true,
    ])
        ->and(array_column($data->rows, 'label'))->not->toContain('Nama')->not->toContain('Kelas')
        ->and($data->rows[1])->toBe(['label' => 'Capaian', 'values' => ['Juara II'], 'list' => false])
        ->and($data->notes)->toBe([]);

    // The same list from a teammate's own publication: one team, one set of facts.
    $other = Achievement::query()->where('report_id', $achievement->report_id)->whereKeyNot($achievement->getKey())->firstOrFail();
    expect(FlyerData::of($other)->rows[0])->toBe($data->rows[0]);
});

it('names who does not allow a photo in a team', function (): void {
    $achievement = HumasFixtures::achievement(['photo_consent' => false]);
    HumasFixtures::teammate($achievement, 'Raka Pradipta', 'XII-2');
    HumasFixtures::teammate($achievement, 'Salsabila Kirana', 'X-3', ['photo_consent' => false]);

    expect(FlyerData::of($achievement->fresh())->notes)
        ->toBe(['Tidak mengizinkan fotonya dipakai: Nadia Putri Anindya, Salsabila Kirana.']);
});

it('leaves a team member without publish consent out of the list and only counts them', function (): void {
    $achievement = HumasFixtures::achievement();
    HumasFixtures::teammate($achievement, 'Raka Pradipta', 'XII-2');
    HumasFixtures::teammate($achievement, 'Fajar Ramadhan', 'XII-5', ['publish_consent' => false, 'photo_consent' => false]);
    HumasFixtures::teammate($achievement, 'Bima Satria Wicaksono', 'XII-6', ['publish_consent' => false]);

    $data = FlyerData::of($achievement->fresh());
    $text = (string) json_encode([$data->rows, $data->notes], JSON_UNESCAPED_UNICODE);

    expect($data->rows[0]['values'])->toBe(['Nadia Putri Anindya (XI-4)', 'Raka Pradipta (XII-2)'])
        ->and($data->notes)->toBe(['2 anggota tim tidak mengizinkan namanya dipublikasikan, jadi tidak ditampilkan di sini.'])
        ->and($text)->not->toContain('Fajar Ramadhan')
        ->not->toContain('Bima Satria Wicaksono')
        ->not->toContain('XII-5')
        ->not->toContain('XII-6');
});

it('names nobody when no member of the team gave publish consent', function (): void {
    $achievement = HumasFixtures::achievement(['publish_consent' => false]);
    HumasFixtures::teammate($achievement, 'Fajar Ramadhan', 'XII-5', ['publish_consent' => false]);

    $data = FlyerData::of($achievement->fresh());
    $text = (string) json_encode([$data->rows, $data->notes], JSON_UNESCAPED_UNICODE);

    expect($data->rows[0])->toBe(['label' => 'Nama', 'values' => ['Siswa kelas XI'], 'list' => false])
        ->and(array_column($data->rows, 'label'))->not->toContain('Anggota tim')->not->toContain('Kelas')
        ->and($data->notes[0])->toBe('2 anggota tim tidak mengizinkan namanya dipublikasikan, jadi tidak ditampilkan di sini.')
        ->and($text)->not->toContain('Nadia Putri Anindya')
        ->not->toContain('Fajar Ramadhan')
        ->not->toContain('XI-4')
        ->not->toContain('XII-5');
});

it('says so when the one student does not allow a photo', function (): void {
    $achievement = HumasFixtures::achievement(['photo_consent' => false]);

    expect(FlyerData::of($achievement->fresh())->notes)->toBe(['Siswa ini tidak mengizinkan fotonya dipakai.']);
});
