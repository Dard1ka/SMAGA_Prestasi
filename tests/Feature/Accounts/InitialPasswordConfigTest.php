<?php

declare(strict_types=1);

use App\Domain\Accounts\InitialPassword;
use App\Support\Audit\SecurityEventSubscriber;

it('builds the initial password from config sth.initial_student_password (D-138)', function (): void {
    expect(config('sth.initial_student_password'))->toBe('12345')
        ->and(app(InitialPassword::class)->make())->toBe('12345');

    config(['sth.initial_student_password' => 'Awal-Uji-7319']);

    expect(app(InitialPassword::class)->make())->toBe('Awal-Uji-7319');
});

// FS1 fix wave (D2 ledger): a value of spaces only must never break a login, and spaces around a value are not part of
// it. The assertions compare with hash_equals(), so a failure never prints the value (CLAUDE.md rule 3).

it('falls back to the default for a value of spaces only and trims spaces around a value', function (): void {
    config(['sth.initial_student_password' => "  \t "]);
    expect(hash_equals(InitialPassword::DEFAULT, app(InitialPassword::class)->make()))->toBeTrue();

    config(['sth.initial_student_password' => '  Awal-Uji-7319  ']);
    expect(hash_equals('Awal-Uji-7319', app(InitialPassword::class)->make()))->toBeTrue();
});

it('reads STH_INITIAL_STUDENT_PASSWORD trimmed, with the default for an empty or blank value', function (): void {
    $read = function (?string $value): string {
        $before = [$_SERVER['STH_INITIAL_STUDENT_PASSWORD'] ?? null, $_ENV['STH_INITIAL_STUDENT_PASSWORD'] ?? null, getenv('STH_INITIAL_STUDENT_PASSWORD')];

        try {
            if ($value === null) {
                unset($_SERVER['STH_INITIAL_STUDENT_PASSWORD'], $_ENV['STH_INITIAL_STUDENT_PASSWORD']);
                putenv('STH_INITIAL_STUDENT_PASSWORD');
            } else {
                $_SERVER['STH_INITIAL_STUDENT_PASSWORD'] = $_ENV['STH_INITIAL_STUDENT_PASSWORD'] = $value;
                putenv('STH_INITIAL_STUDENT_PASSWORD='.$value);
            }

            return (string) (require config_path('sth.php'))['initial_student_password'];
        } finally {
            [$server, $env, $process] = $before;
            unset($_SERVER['STH_INITIAL_STUDENT_PASSWORD'], $_ENV['STH_INITIAL_STUDENT_PASSWORD']);

            if ($server !== null) {
                $_SERVER['STH_INITIAL_STUDENT_PASSWORD'] = $server;
            }

            if ($env !== null) {
                $_ENV['STH_INITIAL_STUDENT_PASSWORD'] = $env;
            }

            putenv($process === false ? 'STH_INITIAL_STUDENT_PASSWORD' : 'STH_INITIAL_STUDENT_PASSWORD='.$process);
        }
    };

    expect(hash_equals(InitialPassword::DEFAULT, $read(null)))->toBeTrue()
        ->and(hash_equals(InitialPassword::DEFAULT, $read('')))->toBeTrue()
        ->and(hash_equals(InitialPassword::DEFAULT, $read('   ')))->toBeTrue()
        ->and(hash_equals('Awal-Uji-7319', $read('  Awal-Uji-7319 ')))->toBeTrue();
});

it('keeps a padded numeric initial password out of the security log as typed', function (): void {
    config(['sth.initial_student_password' => ' 0123456789 ']);

    // Typed without the spaces it is still the initial password, so only its fingerprint may be logged.
    expect(str_starts_with((string) SecurityEventSubscriber::identifier('0123456789'), 'fp:'))->toBeTrue()
        ->and(SecurityEventSubscriber::identifier('0123456788'))->toBe('0123456788');
});
