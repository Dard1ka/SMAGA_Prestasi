<?php

declare(strict_types=1);

use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

/*
 | POST /user/confirm-password gates every sensitive Admin action and the TOTP setup (SECURITY §3m-9). A stolen
 | session must not be able to guess the password there: 5 wrong passwords per user, then a one-minute pause.
 */

beforeEach(function (): void {
    H::seedRoles();
    config(['logging.channels.security' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    Log::forgetChannel('security');
});

function m3Confirm(string $password): TestResponse
{
    return test()->from(route('password.confirm'))->post(route('password.confirm.store'), ['password' => $password]);
}

/** @return list<array{message: string, context: array<string, mixed>, level: string}> */
function m3SecurityRecords(string $message): array
{
    $handler = collect(Log::channel('security')->getLogger()->getHandlers())->first(fn ($h) => $h instanceof TestHandler);

    return array_values(array_filter(array_map(fn (LogRecord $r): array => [
        'message' => $r->message,
        'context' => $r->context,
        'level' => strtolower($r->level->getName()),
    ], $handler?->getRecords() ?? []), fn (array $r): bool => $r['message'] === $message));
}

it('blocks the confirmation after five wrong passwords, even with the right one', function (): void {
    $this->freezeTime();
    $this->actingAs(H::staff(RoleName::Admin));

    foreach (range(1, 5) as $attempt) {
        m3Confirm('salah-sekali-'.$attempt)->assertSessionHasErrors('password');
        expect(session('errors')->first('password'))->not->toStartWith('Terlalu banyak percobaan');
    }

    m3Confirm(H::PASSWORD)->assertSessionHasErrors('password')->assertSessionMissing('auth.password_confirmed_at');

    expect(session('errors')->first('password'))->toBe('Terlalu banyak percobaan. Coba lagi dalam 1 menit.');
});

it('answers a blocked JSON request with 422 and the same message', function (): void {
    $this->actingAs(H::staff(RoleName::Admin));

    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('password.confirm.store'), ['password' => 'salah-sekali-'.$attempt])->assertUnprocessable();
    }

    $this->postJson(route('password.confirm.store'), ['password' => H::PASSWORD])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password' => 'Terlalu banyak percobaan']);
});

it('lets the user confirm again after the pause', function (): void {
    $this->actingAs(H::staff(RoleName::Admin));

    foreach (range(1, 5) as $attempt) {
        m3Confirm('salah-sekali-'.$attempt);
    }

    m3Confirm(H::PASSWORD)->assertSessionHasErrors('password');

    $this->travel(61)->seconds();

    m3Confirm(H::PASSWORD)->assertSessionHasNoErrors()->assertSessionHas('auth.password_confirmed_at');
});

it('forgets earlier wrong passwords after a correct one', function (): void {
    $this->actingAs(H::staff(RoleName::Admin));

    foreach (range(1, 4) as $attempt) {
        m3Confirm('salah-sekali-'.$attempt)->assertSessionHasErrors('password');
    }

    m3Confirm(H::PASSWORD)->assertSessionHasNoErrors();

    foreach (range(1, 4) as $attempt) {
        m3Confirm('salah-lagi-'.$attempt)->assertSessionHasErrors('password');
    }

    m3Confirm(H::PASSWORD)->assertSessionHasNoErrors()->assertSessionHas('auth.password_confirmed_at');
});

it('counts per user, not per session or IP', function (): void {
    $admin = H::staff(RoleName::Admin);
    $guru = H::staff(RoleName::Guru);

    $this->actingAs($admin);

    foreach (range(1, 5) as $attempt) {
        m3Confirm('salah-sekali-'.$attempt);
    }

    // Another session of the same account from another address is blocked too…
    $this->flushSession();
    $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => '10.20.30.40']);
    m3Confirm(H::PASSWORD)->assertSessionHasErrors('password');

    // …another account is not.
    $this->actingAs($guru);
    m3Confirm(H::PASSWORD)->assertSessionHasNoErrors()->assertSessionHas('auth.password_confirmed_at');
});

it('counts a request without a password as a wrong one', function (): void {
    $this->actingAs(H::staff(RoleName::Admin));

    foreach (range(1, 5) as $attempt) {
        $this->from(route('password.confirm'))->post(route('password.confirm.store'))->assertSessionHasErrors('password');
    }

    m3Confirm(H::PASSWORD)->assertSessionHasErrors('password');
});

it('writes one security log line when the limit is hit, without the password', function (): void {
    $admin = H::staff(RoleName::Admin);
    $this->actingAs($admin);

    foreach (range(1, 4) as $attempt) {
        m3Confirm('salah-sekali-'.$attempt);
    }

    expect(m3SecurityRecords('security.password_confirm_locked'))->toBe([]);

    m3Confirm('salah-sekali-5');
    m3Confirm('salah-sekali-6');
    m3Confirm(H::PASSWORD);

    $records = m3SecurityRecords('security.password_confirm_locked');

    expect($records)->toHaveCount(1)
        ->and($records[0]['level'])->toBe('warning')
        ->and($records[0]['context'])->toMatchArray(['user_id' => $admin->getKey(), 'attempts' => 5, 'seconds' => 60, 'ip' => '127.0.0.1'])
        ->and(json_encode($records))->not->toContain('salah-sekali');
});
