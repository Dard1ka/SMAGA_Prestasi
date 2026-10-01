<?php

declare(strict_types=1);

use App\Domain\Points\PointFormatter;

it('formats points with a decimal comma and at most one decimal', function (string|int|float $points, string $expected) {
    expect((new PointFormatter)->format($points))->toBe($expected);
})->with([
    ['33.60', '33,6'],
    ['80.00', '80'],
    ['68', '68'],
    ['27.20', '27,2'],
    ['4.00', '4'],
    ['0.00', '0'],
    ['3.65', '3,7'],
    ['3.64', '3,6'],
    ['1234.50', '1.234,5'],
    ['1000000', '1.000.000'],
    [104, '104'],
    [33.6, '33,6'],
]);

it('can format with more or fewer decimals', function () {
    $formatter = new PointFormatter;

    expect($formatter->format('33.65', 2))->toBe('33,65')
        ->and($formatter->format('33.5', 0))->toBe('34');
});

it('formats weights with fixed decimals', function () {
    $formatter = new PointFormatter;

    expect($formatter->formatWeight('1'))->toBe('1,00')
        ->and($formatter->formatWeight('0.85'))->toBe('0,85')
        ->and($formatter->formatWeight('80', 0))->toBe('80');
});
