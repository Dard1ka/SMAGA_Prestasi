<?php

declare(strict_types=1);

use App\Domain\Leaderboard\RankingScope;

it('offers the three groups in display order with active students first', function () {
    expect(RankingScope::options())->toBe([
        'aktif' => 'Siswa aktif',
        'alumni' => 'Alumni',
        'semua' => 'Semua',
    ]);
});

it('decides who belongs to the shown group', function (RankingScope $scope, bool $active, bool $alumni) {
    expect($scope->includes(false))->toBe($active)
        ->and($scope->includes(true))->toBe($alumni);
})->with([
    'siswa aktif' => [RankingScope::Active, true, false],
    'alumni' => [RankingScope::Alumni, false, true],
    'semua' => [RankingScope::All, true, true],
]);

it('has no class ranking for alumni only', function () {
    expect(RankingScope::Active->hasClasses())->toBeTrue()
        ->and(RankingScope::All->hasClasses())->toBeTrue()
        ->and(RankingScope::Alumni->hasClasses())->toBeFalse();
});

it('falls back to active students for unknown values', function (mixed $value) {
    expect(RankingScope::fromValue($value))->toBe(RankingScope::Active);
})->with([null, '', 'lulusan', 'ALUMNI', 1, true, [['alumni']]]);

it('reads the URL values', function () {
    expect(RankingScope::fromValue('alumni'))->toBe(RankingScope::Alumni)
        ->and(RankingScope::fromValue('semua'))->toBe(RankingScope::All)
        ->and(RankingScope::fromValue('aktif'))->toBe(RankingScope::Active);
});
