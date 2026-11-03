<?php

declare(strict_types=1);

use App\Domain\Leaderboard\RankedStudent;
use App\Domain\Leaderboard\Ranker;

it('sums points per student across achievement rows (cumulative)', function () {
    $ranked = (new Ranker)->rank([
        ['student_id' => 'nadia', 'name' => 'Nadia Putri Anindya', 'points' => '68.00', 'results' => ['juara_2']],
        ['student_id' => 'raka', 'name' => 'Raka Pradipta', 'points' => '80.00', 'results' => ['juara_1']],
        ['student_id' => 'nadia', 'name' => 'Nadia Putri Anindya', 'points' => '32.00', 'results' => ['juara_1']],
        ['student_id' => 'nadia', 'name' => 'Nadia Putri Anindya', 'points' => 4, 'results' => ['peserta']],
    ]);

    expect($ranked)->toHaveCount(2)
        ->and($ranked[0])->toBeInstanceOf(RankedStudent::class)
        ->and($ranked[0]->studentId)->toBe('nadia')
        ->and($ranked[0]->points)->toBe('104.00')
        ->and($ranked[0]->achievements)->toBe(3)
        ->and($ranked[0]->tierCounts)->toBe([1, 1, 0, 0, 0, 1])
        ->and($ranked[1]->studentId)->toBe('raka')
        ->and($ranked[1]->rank)->toBe(2);
});

it('breaks point ties by higher results first', function () {
    $ranked = (new Ranker)->rank([
        ['student_id' => 1, 'name' => 'Aurelia Maharani', 'points' => '40.00', 'results' => ['finalis', 'finalis']],
        ['student_id' => 2, 'name' => 'Bima Satria Wicaksono', 'points' => '40.00', 'results' => ['medali_emas']],
        ['student_id' => 3, 'name' => 'Citra Ayu Lestari', 'points' => '40.00', 'results' => ['juara_2', 'peserta']],
    ]);

    expect(array_map(fn (RankedStudent $r) => $r->studentId, $ranked))->toBe([2, 3, 1])
        ->and(array_map(fn (RankedStudent $r) => $r->rank, $ranked))->toBe([1, 2, 3]);
});

it('treats juara and medal results of equal weight as the same tier', function () {
    $ranked = (new Ranker)->rank([
        ['student_id' => 'b', 'name' => 'Bima', 'points' => '40', 'results' => ['medali_emas']],
        ['student_id' => 'a', 'name' => 'Aurelia', 'points' => '40', 'results' => ['juara_1']],
    ]);

    expect($ranked[0]->name)->toBe('Aurelia')
        ->and($ranked[0]->rank)->toBe(1)
        ->and($ranked[1]->rank)->toBe(1)
        ->and($ranked[1]->position)->toBe(2);
});

it('orders full ties by name and shares the rank (1, 2, 2, 4)', function () {
    $ranked = (new Ranker)->rank([
        ['student_id' => 'y', 'name' => 'yoga Pratama', 'points' => '150', 'results' => ['juara_1']],
        ['student_id' => 'r', 'name' => 'Raka Pradipta', 'points' => '312', 'results' => ['juara_1']],
        ['student_id' => 'h', 'name' => 'Hana Safira', 'points' => '150', 'results' => ['juara_1']],
        ['student_id' => 'f', 'name' => 'Fajar Ramadhan', 'points' => '100', 'results' => ['juara_1']],
    ]);

    expect(array_map(fn (RankedStudent $r) => [$r->name, $r->rank, $r->position], $ranked))->toBe([
        ['Raka Pradipta', 1, 1],
        ['Hana Safira', 2, 2],
        ['yoga Pratama', 2, 3],
        ['Fajar Ramadhan', 4, 4],
    ]);
});

it('keeps alumni in the ranking with their flag', function () {
    $ranked = (new Ranker)->rank([
        ['student_id' => 'a', 'name' => 'Alumni Satu', 'points' => '200', 'results' => ['juara_1'], 'alumni' => true],
        ['student_id' => 'b', 'name' => 'Siswa Aktif', 'points' => '100', 'results' => ['juara_1']],
    ]);

    expect($ranked[0]->alumni)->toBeTrue()
        ->and($ranked[1]->alumni)->toBeFalse();
});

it('adds decimal points exactly', function () {
    $ranked = (new Ranker)->rank([
        ['student_id' => 'x', 'name' => 'X', 'points' => '33.60', 'results' => ['juara_3']],
        ['student_id' => 'x', 'name' => 'X', 'points' => '27.20', 'results' => ['juara_2']],
        ['student_id' => 'x', 'name' => 'X', 'points' => 0.1, 'results' => []],
    ]);

    expect($ranked[0]->points)->toBe('60.90')
        ->and($ranked[0]->achievements)->toBe(2);
});

it('returns an empty list for no rows', function () {
    expect((new Ranker)->rank([]))->toBe([]);
});

it('rejects unknown results, missing ids and bad tier setups', function (Closure $call) {
    $call();
})->with([
    'unknown result' => [fn () => (new Ranker)->rank([['student_id' => 1, 'name' => 'A', 'points' => 1, 'results' => ['juara_9']]])],
    'missing id' => [fn () => (new Ranker)->rank([['name' => 'A', 'points' => 1]])],
    'negative points' => [fn () => (new Ranker)->rank([['student_id' => 1, 'name' => 'A', 'points' => '-1']])],
    'duplicate tier key' => [fn () => new Ranker([['juara_1'], ['juara_1']])],
    'empty tiers' => [fn () => new Ranker([])],
])->throws(InvalidArgumentException::class);
