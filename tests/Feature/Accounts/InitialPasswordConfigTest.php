<?php

declare(strict_types=1);

use App\Domain\Accounts\InitialPassword;

it('builds the initial password from config sth.initial_student_password (D-138)', function (): void {
    expect(config('sth.initial_student_password'))->toBe('12345')
        ->and(app(InitialPassword::class)->make())->toBe('12345');

    config(['sth.initial_student_password' => 'Awal-Uji-7319']);

    expect(app(InitialPassword::class)->make())->toBe('Awal-Uji-7319');
});
