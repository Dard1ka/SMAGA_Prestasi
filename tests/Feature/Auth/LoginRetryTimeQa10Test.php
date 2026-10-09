<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| QA10 (the lead's report): the "Coba lagi dalam …" time on the login page jumped back and forth. Two clocks answered for
| the same account and address: the route limiter (5 a minute, counting every request, also those during a lock) and the
| progressive lock. During a 15-minute lock the page said "14 menit", then "45 detik", then "13 menit", and after the 45
| seconds the login was still refused. Now one helper answers every refusal with the real time until a correct login
| could succeed (the longest of every block that applies), a refused request counts towards no limit, and an unknown
| identifier behaves the same. Fictional data only.
*/

uses(RefreshDatabase::class);

const QA10_AUTH_RETRY_IP = '10.80.0.1';

beforeEach(function (): void {
    H::seedRoles();
});

function qa10AuthRetryLogin(string $identifier, string $password): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => QA10_AUTH_RETRY_IP])
        ->from('/login')
        ->post('/login', ['identifier' => $identifier, 'password' => $password]);
}

/** The text under the login field after a refused try. */
function qa10AuthRetryShown(TestResponse $response): string
{
    $response->assertSessionHasErrors('identifier');

    return (string) session('errors')->first('identifier');
}

/**
 * Wrong passwords one second apart up to the 5-failure lock, then the right password every few seconds while it holds,
 * then wrong passwords up to the 10-failure lock, then the right password again. Every refusal must name exactly the time
 * left until the lock ends (never a shorter one, never a longer one, never back up except at the new step).
 */
function qa10AuthWalkThroughLocks(string $identifier, string $right): void
{
    $start = now()->getTimestamp();
    $at = fn (int $second) => test()->travelTo(now()->setTimestamp($start + $second));

    foreach (range(0, 4) as $second) {
        $at($second);
        qa10AuthRetryLogin($identifier, 'salah-sekali-123');
    }

    // Failure 5 at second 4: locked until second 64.
    foreach ([10, 20, 30, 40, 50, 55, 60, 63] as $second) {
        $at($second);
        expect(qa10AuthRetryShown(qa10AuthRetryLogin($identifier, $right)))->toBe(AppServiceProvider::retryMessage(64 - $second));
    }

    // Failures 6 to 10 at seconds 65 to 69: the 10th starts the 15-minute step, until second 969.
    foreach (range(65, 69) as $second) {
        $at($second);
        qa10AuthRetryLogin($identifier, 'salah-sekali-123');
    }

    foreach ([75, 80, 90, 200, 500, 968] as $second) {
        $at($second);
        expect(qa10AuthRetryShown(qa10AuthRetryLogin($identifier, $right)))->toBe(AppServiceProvider::retryMessage(969 - $second));
    }

    $at(969);
}

it('shows the real time of the lock on every refusal, counting down and rising only at a new step (QA10)', function (): void {
    $student = H::activeStudent();

    qa10AuthWalkThroughLocks(H::NIS, H::PASSWORD);

    // The lock is over: the right password gets in at once.
    qa10AuthRetryLogin(H::NIS, H::PASSWORD)->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($student->user);
});

it('answers an identifier without an account with the same times (QA10)', function (): void {
    qa10AuthWalkThroughLocks('999888777', H::PASSWORD);

    expect(qa10AuthRetryShown(qa10AuthRetryLogin('999888777', H::PASSWORD)))->toBe('NIS/NISN atau kata sandi salah.');
});

it('still shows the seconds of the per-minute limit when no lock holds (QA10)', function (): void {
    $student = H::activeStudent();
    $start = now()->getTimestamp();

    foreach (range(0, 4) as $second) {
        $this->travelTo(now()->setTimestamp($start + $second));
        qa10AuthRetryLogin(H::NIS, H::PASSWORD)->assertRedirect(route('home'));
        Auth::guard('web')->logout();
    }

    $this->travelTo(now()->setTimestamp($start + 10));
    expect(qa10AuthRetryShown(qa10AuthRetryLogin(H::NIS, H::PASSWORD)))->toBe(AppServiceProvider::retryMessage(50));

    $this->travelTo(now()->setTimestamp($start + 60));
    qa10AuthRetryLogin(H::NIS, H::PASSWORD)->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($student->user);
});
