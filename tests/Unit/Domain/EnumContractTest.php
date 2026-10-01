<?php

declare(strict_types=1);

use App\Domain\Leaderboard\Ranker;
use App\Domain\Points\PointCalculator;
use App\Domain\Points\WeightTable;
use App\Domain\Registrations\RegistrationStateMachine;
use App\Enums\Level;
use App\Enums\RegistrationStatus;
use App\Enums\ResultType;
use App\Enums\WeightCategory;
use App\Enums\WeightGroup;

// Keeps the pure domain services and App\Enums (W1-A) from drifting apart.

it('has a default weight for every enum case and nothing else', function () {
    $table = WeightTable::defaults();

    expect($table->levels())->toBe(WeightGroup::Level->defaults())
        ->and($table->results())->toEqualCanonicalizing(WeightGroup::Result->defaults())
        ->and($table->organizers())->toBe(WeightGroup::Organizer->defaults())
        ->and(array_map(fn (WeightGroup $g) => $g->value, WeightGroup::cases()))
        ->toBe([WeightTable::GROUP_LEVEL, WeightTable::GROUP_RESULT, WeightTable::GROUP_ORGANIZER]);
});

it('computes points straight from enum cases', function () {
    expect((new PointCalculator)->breakdown(WeightTable::defaults(), Level::Nasional, ResultType::Juara2, WeightCategory::A)->points)
        ->toBe('68.00');
});

it('uses the same result tiers as ResultType::tier()', function () {
    foreach (Ranker::DEFAULT_RESULT_TIERS as $index => $keys) {
        foreach ($keys as $key) {
            expect(ResultType::from($key)->tier())->toBe($index + 1, $key);
        }
    }

    expect(array_merge(...Ranker::DEFAULT_RESULT_TIERS))
        ->toEqualCanonicalizing(array_map(fn (ResultType $r) => $r->value, ResultType::cases()));
});

it('matches RegistrationStatus values, phases and final flags', function () {
    $sm = new RegistrationStateMachine;

    expect(array_map(fn (RegistrationStatus $s) => $s->value, RegistrationStatus::cases()))
        ->toEqualCanonicalizing($sm->statuses());

    foreach (RegistrationStatus::cases() as $status) {
        expect($sm->phaseFor($status))->toBe($status->phase(), $status->value)
            ->and($sm->isFinal($status))->toBe($status->isFinal(), $status->value);
    }
});
