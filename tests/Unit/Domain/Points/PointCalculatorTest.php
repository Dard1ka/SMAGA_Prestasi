<?php

declare(strict_types=1);

use App\Domain\Points\PointBreakdown;
use App\Domain\Points\PointCalculator;
use App\Domain\Points\WeightTable;

it('computes the spec examples', function (string $level, string $result, string $organizer, string $expected) {
    expect((new PointCalculator)->compute($level, $result, $organizer))->toBe($expected);
})->with([
    'OPSI nasional juara II A' => ['80', '0.85', '1.00', '68.00'],
    'KRENOVA kota juara I B' => ['40', '1.00', '0.80', '32.00'],
    'O2SN kota peserta A' => ['40', '0.10', '1.00', '4.00'],
    'WICE internasional emas D' => ['100', '1.00', '0.40', '40.00'],
    'nasional juara III C' => ['80', '0.70', '0.60', '33.60'],
    'kota juara II B' => ['40', '0.85', '0.80', '27.20'],
]);

it('accepts ints, floats and DB decimal strings', function () {
    $calculator = new PointCalculator;

    expect($calculator->compute(80, 0.85, 1))->toBe('68.00')
        ->and($calculator->compute('80.00', '0.85', '1.00'))->toBe('68.00')
        ->and($calculator->compute(60, 0.3, 0.2))->toBe('3.60');
});

it('rounds half up to 2 decimals', function () {
    $calculator = new PointCalculator;

    expect($calculator->compute('1', '1', '0.005'))->toBe('0.01')
        ->and($calculator->compute('1', '1', '0.0049'))->toBe('0.00')
        ->and($calculator->compute('33.335', '1', '1'))->toBe('33.34')
        ->and($calculator->compute('10.5', '0.33', '1'))->toBe('3.47'); // 3.465
});

it('rejects negative or non-numeric weights', function (mixed $level) {
    (new PointCalculator)->compute($level, '1', '1');
})->with([['-1'], ['abc'], ['1e3'], ['']])->throws(InvalidArgumentException::class);

it('builds a snapshot breakdown from a weight table', function () {
    $breakdown = (new PointCalculator)->breakdown(WeightTable::defaults(), 'provinsi', 'juara_3', 'C');

    expect($breakdown)->toBeInstanceOf(PointBreakdown::class)
        ->and($breakdown->toArray())->toBe([
            'w_level' => '60.00',
            'w_result' => '0.70',
            'w_organizer' => '0.60',
            'points' => '25.20',
        ]);
});

it('accepts backed enums as keys', function () {
    $level = TestLevel::Nasional;

    expect((new PointCalculator)->breakdown(WeightTable::defaults(), $level, 'juara_2', 'A')->points)->toBe('68.00');
});

enum TestLevel: string
{
    case Nasional = 'nasional';
}
