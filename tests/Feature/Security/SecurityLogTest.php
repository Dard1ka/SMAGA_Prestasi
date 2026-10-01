<?php

declare(strict_types=1);

use App\Actions\Accounts\VerifyOtpCode;
use App\Enums\OtpPurpose;
use App\Enums\RoleName;
use App\Jobs\CleanupOtpCodes;
use App\Models\OtpCode;
use App\Support\Audit\SecurityEventSubscriber;
use App\Support\Audit\SecurityLog;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

/*
 | Backlog #16: `security` log channel (daily, 90 days) fed by login failures/lockouts, OTP abuse, Admin
 | resets/archive/consent changes (activity rows) and every `security.*` message; OTP cleanup job.
 */

beforeEach(function (): void {
    H::seedRoles();
    config(['logging.channels.security' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    Log::forgetChannel('security');
});

/** @return list<array{message: string, context: array<string, mixed>, level: string}> */
function b2SecurityRecords(): array
{
    $handler = collect(Log::channel('security')->getLogger()->getHandlers())->first(fn ($h) => $h instanceof TestHandler);

    return array_map(fn (LogRecord $r): array => [
        'message' => $r->message,
        'context' => $r->context,
        'level' => strtolower($r->level->getName()),
    ], $handler?->getRecords() ?? []);
}

/** @return list<array{message: string, context: array<string, mixed>, level: string}> */
function b2SecurityRecordsNamed(string $message): array
{
    return array_values(array_filter(b2SecurityRecords(), fn (array $r): bool => $r['message'] === $message));
}

it('defines a daily security channel kept for 90 days (null while the test suite runs)', function (): void {
    $config = require base_path('config/logging.php');

    expect($config['channels']['security_daily'])
        ->toMatchArray(['driver' => 'daily', 'max_files' => 90, 'level' => 'info'])
        ->and($config['channels']['security_daily']['path'])->toEndWith('logs/security.log')
        ->and($config['channels']['security'])->toMatchArray(['driver' => 'stack', 'channels' => ['null']]);
});

it('logs a failed login with the NIS, IP and never the password', function (): void {
    H::activeStudent();

    $this->post(route('login.store'), ['identifier' => H::NIS, 'password' => 'salah-sekali-123'])->assertSessionHasErrors();

    $records = b2SecurityRecordsNamed('security.login_failed');

    expect($records)->toHaveCount(1)
        ->and($records[0]['level'])->toBe('warning')
        ->and($records[0]['context']['identifier'])->toBe(H::NIS)
        ->and($records[0]['context']['ip'])->toBe('127.0.0.1')
        ->and(json_encode(b2SecurityRecords()))->not->toContain('salah-sekali-123');
});

it('records a password typed into the identifier field only as a fingerprint', function (): void {
    $typed = 'sm4g4_020310';

    $this->post(route('login.store'), ['identifier' => $typed, 'password' => 'x'])->assertSessionHasErrors();

    $record = b2SecurityRecordsNamed('security.login_failed')[0];

    expect($record['context']['identifier'])->toStartWith('fp:')
        ->and(json_encode(b2SecurityRecords()))->not->toContain($typed)
        ->and(SecurityEventSubscriber::identifier('Staf.TU@Example.test'))->toBe('staf.tu@example.test')
        ->and(SecurityEventSubscriber::identifier('0098765432'))->toBe('0098765432')
        ->and(SecurityEventSubscriber::identifier('kesiswaan01'))->toStartWith('fp:');
});

it('logs a successful login', function (): void {
    $student = H::activeStudent();

    $this->post(route('login.store'), ['identifier' => H::NIS, 'password' => H::PASSWORD]);

    $records = b2SecurityRecordsNamed('security.login');

    expect($records)->toHaveCount(1)
        ->and($records[0]['context']['user_id'])->toBe($student->user_id);
});

it('copies security.* messages of the default log (lockout, OTP test mode, …) into the security channel', function (): void {
    Log::warning('security.login_lockout', ['bucket' => 'abc123', 'failures' => 5, 'seconds' => 60]);
    Log::info('registration.created', ['id' => 1]);

    $records = b2SecurityRecordsNamed('security.login_lockout');

    expect($records)->toHaveCount(1)
        ->and($records[0]['context'])->toMatchArray(['bucket' => 'abc123', 'failures' => 5, 'seconds' => 60])
        ->and(b2SecurityRecordsNamed('registration.created'))->toBe([]);
});

it('mirrors audited Admin actions (resets, archive, consent) with ids only, never the reason', function (): void {
    $admin = H::staff(RoleName::Admin);
    $student = H::activeStudent();

    activity('account')->causedBy($admin)->performedOn($student->user)->event('account.reset')
        ->withProperties(['reason' => 'HP siswa hilang'])->log('account.reset');
    activity('student')->causedBy($admin)->performedOn($student)->event('student.consent_updated')
        ->withProperties(['note' => 'Surat orang tua'])->log('student.consent_updated');
    activity('registration')->causedBy($admin)->event('registration.created')->log('registration.created');

    $reset = b2SecurityRecordsNamed('security.audit.account.reset');
    $consent = b2SecurityRecordsNamed('security.audit.student.consent_updated');

    expect($reset)->toHaveCount(1)
        ->and($reset[0]['context'])->toMatchArray(['log' => 'account', 'causer_id' => $admin->getKey(), 'subject_type' => 'User', 'subject_id' => (string) $student->user_id])
        ->and($consent)->toHaveCount(1)
        ->and(b2SecurityRecordsNamed('security.audit.registration.created'))->toBe([])
        ->and(json_encode(b2SecurityRecords()))->not->toContain('HP siswa hilang')->not->toContain('Surat orang tua');
});

it('does not log audited actions that were rolled back', function (): void {
    try {
        DB::transaction(function (): void {
            activity('account')->event('account.archived')->log('account.archived');

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(b2SecurityRecordsNamed('security.audit.account.archived'))->toBe([]);
});

it('flags repeated wrong OTP codes and an exhausted code', function (): void {
    $student = H::activeStudent();
    OtpCode::factory()->create(['user_id' => $student->user_id, 'purpose' => OtpPurpose::VerifyEmail, 'email' => 'nadia.anindya@example.test']);
    $verify = app(VerifyOtpCode::class);

    for ($i = 0; $i < 2; $i++) {
        $verify->handle($student->user, OtpPurpose::VerifyEmail->value, '000000');
    }

    expect(b2SecurityRecordsNamed('security.otp_failed'))->toBe([]);

    for ($i = 0; $i < 3; $i++) {
        $verify->handle($student->user, OtpPurpose::VerifyEmail->value, '000000');
    }

    expect(b2SecurityRecordsNamed('security.otp_failed'))->toHaveCount(2)
        ->and(b2SecurityRecordsNamed('security.otp_exhausted'))->toHaveCount(1)
        ->and(b2SecurityRecordsNamed('security.otp_exhausted')[0]['context'])->toMatchArray(['user_id' => $student->user_id, 'attempts' => 5, 'purpose' => 'verify_email'])
        ->and(json_encode(b2SecurityRecords()))->not->toContain('000000');
});

it('drops secrets from the context and never breaks the caller', function (): void {
    SecurityLog::warning('security.test', [
        'user_id' => 7,
        'password' => 'rahasia',
        'Code' => '123456',
        'nested' => ['token' => 'abc', 'kept' => 'ya'],
        'purpose' => OtpPurpose::ResetPassword,
    ]);

    $context = b2SecurityRecordsNamed('security.test')[0]['context'];

    expect($context)->toMatchArray(['user_id' => 7, 'nested' => ['kept' => 'ya'], 'purpose' => 'reset_password'])
        ->and($context)->not->toHaveKeys(['password', 'Code']);

    config(['logging.channels.security' => ['driver' => 'daily', 'path' => '/proc/forbidden/security.log']]);
    Log::forgetChannel('security');

    expect(fn () => SecurityLog::warning('security.still_fine'))->not->toThrow(Throwable::class);
});

it('deletes OTP rows older than 24 hours and keeps newer ones', function (): void {
    $student = H::activeStudent();
    $old = OtpCode::factory()->create(['user_id' => $student->user_id]);
    $old->forceFill(['created_at' => now()->subHours(25)])->save();
    $recent = OtpCode::factory()->create(['user_id' => $student->user_id]);
    $recent->forceFill(['created_at' => now()->subHours(23)])->save();

    $deleted = (new CleanupOtpCodes)->handle();

    expect($deleted)->toBe(1)
        ->and(OtpCode::query()->whereKey($old->getKey())->exists())->toBeFalse()
        ->and(OtpCode::query()->whereKey($recent->getKey())->exists())->toBeTrue()
        ->and(b2SecurityRecordsNamed('security.otp_cleanup')[0]['context']['deleted'])->toBe(1);
});

it('schedules the OTP cleanup hourly and the audit seal + verify commands', function (): void {
    $events = collect(app(Schedule::class)->events());

    $cleanup = $events->first(fn ($e) => $e instanceof CallbackEvent && str_contains((string) $e->description, CleanupOtpCodes::class));
    $verify = $events->first(fn ($e) => str_contains((string) $e->command, 'sth:audit:verify'));
    $seal = $events->first(fn ($e) => str_contains((string) $e->command, 'sth:audit:seal'));

    expect($cleanup)->not->toBeNull()
        ->and($cleanup->expression)->toBe('20 * * * *')
        ->and($verify)->not->toBeNull()
        ->and($verify->expression)->toBe('30 2 * * *')
        ->and($verify->timezone)->toBe('Asia/Jakarta')
        ->and($seal)->not->toBeNull();
});

it('treats account, role, archive, consent, settings and export events as security relevant', function (string $event, bool $audited): void {
    expect(SecurityEventSubscriber::isAuditedEvent($event))->toBe($audited);
})->with([
    ['account.reset', true],
    ['account.unarchived', true],
    ['role.change_requested', true],
    ['student.archive_date_changed', true],
    ['student.consent_updated', true],
    ['signature.replaced', true],
    ['audit.exported', true],
    ['talent.exported', true],
    ['registration.created', false],
    ['report.verified', false],
    ['', false],
]);
