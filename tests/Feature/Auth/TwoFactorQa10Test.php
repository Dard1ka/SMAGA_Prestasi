<?php

declare(strict_types=1);

use App\Actions\Fortify\LoginIdentifier;
use App\Actions\Fortify\LoginLockout;
use App\Actions\Fortify\TwoFactorChallenge;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| QA10 item 3: the staff TOTP challenge after a correct password. The limiter was keyed on account AND address, so
| every extra address gave five more guesses a minute; wrong codes left no trace; and the half-signed-in session
| (no user_id, so session revocation misses it) still let in a challenge started before a password change, at any
| later time. Now the limiter counts per account, every wrong code is audited, the challenge ends after 10 wrong
| codes of the account, and it is refused when it is older than TwoFactorChallenge::MAX_AGE_SECONDS or the password
| changed since it started. Fictional data only.
*/

uses(RefreshDatabase::class);

const QA10_AUTH_TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

beforeEach(function (): void {
    H::seedRoles();
});

/** Kesiswaan account with confirmed TOTP, after a correct password: the challenge is open. */
function qa10AuthChallenged(): User
{
    $staff = H::staff(RoleName::Kesiswaan);
    $staff->forceFill([
        'two_factor_secret' => encrypt(QA10_AUTH_TOTP_SECRET),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    qa10AuthStartChallenge();

    return $staff;
}

function qa10AuthStartChallenge(): void
{
    test()->withServerVariables(['REMOTE_ADDR' => '10.30.0.1'])
        ->from('/login')
        ->post('/login', ['identifier' => 'staf.kesiswaan@example.test', 'password' => H::PASSWORD])
        ->assertRedirect(route('two-factor.login'));

    test()->assertGuest();
}

function qa10AuthTotp(string $code, string $ip = '10.30.0.1'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->from(route('two-factor.login'))
        ->post(route('two-factor.login.store'), ['code' => $code]);
}

function qa10AuthRightCode(): string
{
    return app(Google2FA::class)->getCurrentOtp(QA10_AUTH_TOTP_SECRET);
}

/** A code that is wrong for every time step the verifier might accept. */
function qa10AuthWrongTotp(): string
{
    $engine = app(Google2FA::class);
    $now = $engine->getTimestamp();
    $near = array_map(fn (int $step): string => $engine->oathTotp(QA10_AUTH_TOTP_SECRET, $now + $step), range(-3, 3));

    foreach (['000000', '111111', '222222', '333333'] as $candidate) {
        if (! in_array($candidate, $near, true)) {
            return $candidate;
        }
    }

    return '444444';
}

function qa10AuthAssertChallengeRefused(TestResponse $response, string $message): void
{
    $response->assertRedirect(route('login'))->assertSessionHasErrors('identifier');
    expect(session('errors')->first('identifier'))->toStartWith($message);
    test()->assertGuest();

    // The challenge is over: its page sends back to the login.
    test()->get(route('two-factor.login'))->assertRedirect(route('login'));
}

it('limits wrong TOTP codes per account, whatever address they come from (QA10)', function (): void {
    qa10AuthChallenged();
    $wrong = qa10AuthWrongTotp();

    foreach (range(1, 5) as $i) {
        qa10AuthTotp($wrong, '10.40.0.1')->assertSessionHasErrors('code');
    }

    qa10AuthTotp($wrong, '10.40.0.2')->assertSessionHasErrors('code');

    expect(session('errors')->first('code'))->toStartWith('Terlalu banyak percobaan');
    $this->assertGuest();
});

it('audits every wrong TOTP code at the challenge, without a causer and without any detail (QA10)', function (): void {
    $staff = qa10AuthChallenged();
    $wrong = qa10AuthWrongTotp();

    qa10AuthTotp($wrong)->assertSessionHasErrors('code');

    $row = Activity::query()->where('event', 'two_factor.challenge_failed')->sole();

    expect($row->log_name)->toBe('auth')
        ->and($row->subject_type)->toBe($staff->getMorphClass())
        ->and((string) $row->subject_id)->toBe((string) $staff->getKey())
        ->and($row->causer_id)->toBeNull()
        ->and($row->properties->isEmpty())->toBeTrue();
});

it('ends the challenge after 10 wrong codes of the account, also for the right code (QA10)', function (): void {
    qa10AuthChallenged();
    $wrong = qa10AuthWrongTotp();

    foreach (range(1, 10) as $i) {
        qa10AuthTotp($wrong)->assertSessionHasErrors('code');

        if ($i === 5) {
            $this->travel(61)->seconds();
        }
    }

    expect(Activity::query()->where('event', 'two_factor.challenge_failed')->count())->toBe(TwoFactorChallenge::MAX_FAILURES);

    // Past the per-minute limiter, so only the end of the challenge answers.
    $this->travel(61)->seconds();

    qa10AuthAssertChallengeRefused(qa10AuthTotp(qa10AuthRightCode()), 'Terlalu banyak percobaan');

    // A new password login does not bring ten new guesses while the window lasts.
    qa10AuthStartChallenge();
    qa10AuthAssertChallengeRefused(qa10AuthTotp(qa10AuthRightCode()), 'Terlalu banyak percobaan');
});

it('refuses a challenge whose password was changed after it started (QA10)', function (): void {
    $staff = qa10AuthChallenged();

    $staff->forceFill(['password' => Hash::make('Kata-sandi-lain-2026')])->save();

    qa10AuthAssertChallengeRefused(qa10AuthTotp(qa10AuthRightCode()), TwoFactorChallenge::ENDED);
});

it('refuses a challenge older than the time allowed for it (QA10)', function (): void {
    qa10AuthChallenged();

    $this->travel(TwoFactorChallenge::MAX_AGE_SECONDS + 1)->seconds();

    qa10AuthAssertChallengeRefused(qa10AuthTotp(qa10AuthRightCode()), TwoFactorChallenge::ENDED);
});

it('still signs staff in with the right code, and a success forgets the earlier wrong ones (QA10)', function (): void {
    $staff = qa10AuthChallenged();
    $wrong = qa10AuthWrongTotp();

    foreach (range(1, 9) as $i) {
        qa10AuthTotp($wrong)->assertSessionHasErrors('code');

        if ($i === 5) {
            $this->travel(61)->seconds();
        }
    }

    $this->travel(61)->seconds();
    qa10AuthTotp(qa10AuthRightCode())->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($staff);

    Auth::guard('web')->logout();
    $this->travel(61)->seconds(); // Fortify refuses a used code until its cache entry is gone

    qa10AuthStartChallenge();
    qa10AuthTotp($wrong)->assertSessionHasErrors('code');
    qa10AuthTotp(qa10AuthRightCode())->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($staff);
});

it('forgets the wrong codes of the account when its locks are cleared, as on an unfreeze (QA10)', function (): void {
    $staff = qa10AuthChallenged();
    $wrong = qa10AuthWrongTotp();

    foreach (range(1, 10) as $i) {
        qa10AuthTotp($wrong)->assertSessionHasErrors('code');

        if ($i === 5) {
            $this->travel(61)->seconds();
        }
    }

    app(LoginLockout::class)->clear(LoginIdentifier::accountBucket($staff));
    $this->travel(61)->seconds();

    qa10AuthStartChallenge();
    qa10AuthTotp(qa10AuthRightCode())->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($staff);
});
