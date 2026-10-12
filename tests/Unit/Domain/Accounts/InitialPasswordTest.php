<?php

declare(strict_types=1);

use App\Domain\Accounts\InitialPassword;

// Only the fictional example from D-24 is used for the old pattern here (2 March 2007).

it('returns the configured value, whatever the birth date (D-138)', function (): void {
    expect((new InitialPassword('12345'))->make())->toBe('12345');
});

it('refuses an empty value', function (): void {
    new InitialPassword('  ');
})->throws(InvalidArgumentException::class);

it('recognises the configured value and the old D-24 pattern for K7, and nothing else', function (): void {
    $initial = new InitialPassword('12345');

    expect($initial->matches('12345'))->toBeTrue()
        ->and($initial->matches('12345', '2007-03-02'))->toBeTrue()
        ->and($initial->matches('sm4g4_020307', '2007-03-02'))->toBeTrue()
        ->and($initial->matches('sm4g4_020307'))->toBeFalse()
        ->and($initial->matches('sm4g4_02032007', '2007-03-02'))->toBeFalse()
        ->and($initial->matches('SM4G4_020307', '2007-03-02'))->toBeFalse()
        ->and($initial->matches(' 12345'))->toBeFalse();
});

it('rejects a malformed birth date', function (): void {
    (new InitialPassword('12345'))->matches('x', '02-03-2007');
})->throws(InvalidArgumentException::class);
