<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Actions\Fortify\LoginAttackAlert;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks a 6-digit OTP (docs/SECURITY.md §3b): hashed + purpose/email-bound, 10-minute TTL, max 5 attempts
 * per code, single use. Returns the matching OtpCode (its `email` is the verified target) or null.
 * QA10: at most MAX_FAILED_PER_DAY wrong codes within 24 h (IssueOtpCode::recent()); after that even the right code is
 * refused until the codes they were typed for are a day old (the same answer as a wrong code; `security.otp_daily_limit`
 * is logged once). With a requester (the browser that asked for a "Lupa kata sandi" code, OtpRequester; QA10 review)
 * only that browser's codes are checked and only its wrong codes count, so a stranger's guesses never block the owner;
 * without one (activation, email change: the signed-in account itself) the account's codes of the purpose count.
 *
 * OTP test mode (D-15): any 6 digits are accepted ONLY when APP_ENV=local AND STH_OTP_TEST_MODE=true, and
 * still only for a code that was actually issued (so the target email comes from the server, not the client).
 */
final class VerifyOtpCode
{
    public const MAX_FAILED_PER_DAY = 10;

    /** Wrong reset codes of one account in a day, over every browser, that alert the Admins (no block). */
    public const RESET_ALERT_FAILURES = 20;

    public function __construct(
        private readonly OtpRequester $requesters,
        private readonly IssueOtpCode $issue,
    ) {}

    public static function testModeActive(): bool
    {
        return app()->environment('local') && (bool) config('fortify.sth.otp_test_mode', false);
    }

    /**
     * HMAC of the code bound to user, purpose and target email; the key is derived from APP_KEY.
     */
    public static function hash(int|string $userId, string $purpose, string $email, string $code): string
    {
        $key = hash_hmac('sha256', 'sth-otp-v1', (string) config('app.key'));

        return hash_hmac('sha256', implode('|', [$userId, $purpose, mb_strtolower($email), $code]), $key);
    }

    /**
     * @param  bool  $consume  false = check only (a wrong code still costs an attempt); used when more
     *                         input must be validated before the code is spent.
     * @param  string|null  $requester  OtpRequester token: only codes issued to that browser are checked.
     */
    public function handle(User $user, string $purpose, string $code, bool $consume = true, ?string $requester = null): ?OtpCode
    {
        $code = trim($code);

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }

        $scope = $requester !== null ? $this->requesters->codes($requester, $user, $purpose) : null;

        if ($scope === []) {
            return null;
        }

        $wrong = false;

        $otp = DB::transaction(function () use ($user, $purpose, $code, $consume, $scope, &$wrong): ?OtpCode {
            $query = OtpCode::query()
                ->where('user_id', $user->getKey())
                ->where('purpose', $purpose)
                ->whereNull('used_at');

            if ($scope !== null) {
                $query->whereKey($scope);
            }

            /** @var OtpCode|null $otp */
            $otp = $query->latest('created_at')->lockForUpdate()->first();

            if ($otp === null || $otp->expires_at->isPast() || $otp->attempts >= self::maxAttempts()) {
                return null;
            }

            // QA10: a new code every few minutes no longer means five new guesses without end.
            if ($this->failedToday($user, $purpose, $scope) >= self::MAX_FAILED_PER_DAY) {
                return null;
            }

            if (self::testModeActive()) {
                Log::warning('security.otp_test_mode_used', ['user_id' => $user->getKey(), 'purpose' => $purpose]);
            } elseif (! hash_equals($otp->code_hash, self::hash($user->getKey(), $purpose, $otp->email, $code))) {
                $otp->forceFill(['attempts' => $otp->attempts + 1])->save();
                $wrong = true;

                if (($failed = $this->failedToday($user, $purpose, $scope)) === self::MAX_FAILED_PER_DAY) {
                    Log::warning('security.otp_daily_limit', [
                        'user_id' => $user->getKey(),
                        'purpose' => $purpose,
                        'failures' => $failed,
                        'per_browser' => $scope !== null,
                    ]);
                }

                return null;
            }

            if ($consume) {
                $otp->forceFill(['used_at' => now()])->save();
            }

            return $otp;
        });

        if ($wrong && $purpose === RequestPasswordResetCode::PURPOSE) {
            $this->watchResetCodes($user);
        }

        return $otp;
    }

    /**
     * QA10, second review: no block (that would hand a stranger a way to stop the owner's reset), but the Admins hear
     * once a day when the wrong reset codes of an account reach RESET_ALERT_FAILURES over every browser. Only accounts
     * that get codes come here, so an unknown identifier never does.
     */
    private function watchResetCodes(User $user): void
    {
        $failures = $this->failedToday($user, RequestPasswordResetCode::PURPOSE);

        if ($failures < self::RESET_ALERT_FAILURES || ! Cache::add('sth:reset-code-alert:'.$user->getKey(), true, 86400)) {
            return;
        }

        Log::warning('security.reset_code_attack', ['user_id' => $user->getKey(), 'failures' => $failures]);

        try {
            app(LoginAttackAlert::class)->resetCodes($user, $failures);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function maxAttempts(): int
    {
        return (int) config('fortify.sth.otp.max_attempts', 5);
    }

    /**
     * Wrong codes within the window the limits look at: of the given codes (one browser), or of every code of the
     * account and purpose.
     *
     * @param  list<string>|null  $codes
     */
    public function failedToday(User $user, string $purpose, ?array $codes = null): int
    {
        $query = $this->issue->recent($user, $purpose);

        if ($codes !== null) {
            $query->whereKey($codes);
        }

        return (int) $query->sum('attempts');
    }
}
