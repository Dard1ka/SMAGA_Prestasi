<?php

declare(strict_types=1);

use App\Domain\Points\WeightTable;

it('has the default weights from ARCHITECTURE §7', function () {
    $table = WeightTable::defaults();

    expect($table->levels())->toBe([
        'sekolah' => '20.00', 'kota' => '40.00', 'provinsi' => '60.00', 'nasional' => '80.00', 'internasional' => '100.00',
    ])->and($table->results())->toBe([
        'juara_1' => '1.00', 'medali_emas' => '1.00',
        'juara_2' => '0.85', 'medali_perak' => '0.85',
        'juara_3' => '0.70', 'medali_perunggu' => '0.70',
        'finalis' => '0.50', 'semifinalis' => '0.30', 'penghargaan_khusus' => '0.30', 'peserta' => '0.10',
    ])->and($table->organizers())->toBe([
        'A' => '1.00', 'B' => '0.80', 'C' => '0.60', 'D' => '0.40', 'E' => '0.20',
    ]);
});

it('looks up single weights', function () {
    $table = WeightTable::defaults();

    expect($table->level('kota'))->toBe('40.00')
        ->and($table->result('medali_perak'))->toBe('0.85')
        ->and($table->organizer('E'))->toBe('0.20');
});

it('throws on an unknown key', function () {
    WeightTable::defaults()->level('kecamatan');
})->throws(InvalidArgumentException::class, "Bobot level untuk 'kecamatan' belum diatur.");

it('round-trips through weight_values rows', function () {
    $rows = WeightTable::defaults()->toRows();

    expect($rows)->toHaveCount(20)
        ->and($rows[0])->toBe(['group' => 'level', 'key' => 'sekolah', 'value' => '20.00'])
        ->and(WeightTable::fromRows($rows))->toEqual(WeightTable::defaults());
});

it('reads rows given as objects with custom values', function () {
    $table = WeightTable::fromRows([
        (object) ['group' => 'level', 'key' => 'nasional', 'value' => '90.00'],
        (object) ['group' => 'result', 'key' => 'juara_1', 'value' => 1],
        new ArrayObject(['group' => 'organizer', 'key' => 'A', 'value' => 0.9]),
    ]);

    expect($table->level('nasional'))->toBe('90.00')
        ->and($table->result('juara_1'))->toBe('1.00')
        ->and($table->organizer('A'))->toBe('0.90');
});

it('rejects unknown groups and negative values', function (array $row) {
    WeightTable::fromRows([$row]);
})->with([
    'bad group' => [['group' => 'bonus', 'key' => 'x', 'value' => '1']],
    'negative' => [['group' => 'level', 'key' => 'kota', 'value' => '-40']],
    'missing value' => [['group' => 'level', 'key' => 'kota']],
])->throws(InvalidArgumentException::class);
