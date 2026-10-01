<?php

declare(strict_types=1);

use App\Domain\Accounts\InitialPassword;
use Carbon\CarbonImmutable;

// Only the fictional example from D-24 is used here (2 March 2007).

it('builds the initial password from the birth date as ddmmyy', function () {
    expect((new InitialPassword)->make('2007-03-02'))->toBe('sm4g4_020307')
        ->and((new InitialPassword)->make(CarbonImmutable::parse('2007-03-02 13:45', 'Asia/Jakarta')))->toBe('sm4g4_020307');
});

it('detects the initial password for K7 without building it for login', function () {
    $initial = new InitialPassword;

    expect($initial->matches('sm4g4_020307', '2007-03-02'))->toBeTrue()
        ->and($initial->matches('sm4g4_02032007', '2007-03-02'))->toBeFalse()
        ->and($initial->matches('SM4G4_020307', '2007-03-02'))->toBeFalse();
});

it('rejects a malformed birth date', function () {
    (new InitialPassword)->make('02-03-2007');
})->throws(InvalidArgumentException::class);
