<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Checks a 6-digit OTP (docs/SECURITY.md §3b): hashed + purpose/email-bound, 10-minute TTL, max 5 attempts
 * per code, single use. Returns the matching OtpCode (its `email` is the verified target) or null.
 *
 * OTP test mode (D-15): any 6 digits are accepted ONLY when APP_ENV=local AND STH_OTP_TEST_MODE=true, and
 * still only for a code that was actually issued (so the target email comes from the server, not the client).
 */
final class VerifyOtpCode
{
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
     */
    public function handle(User $user, string $purpose, string $code, bool $consume = true): ?OtpCode
    {
        $code = trim($code);

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }

        return DB::transaction(function () use ($user, $purpose, $code, $consume): ?OtpCode {
            /** @var OtpCode|null $otp */
            $otp = OtpCode::query()
                ->where('user_id', $user->getKey())
                ->where('purpose', $purpose)
                ->whereNull('used_at')
                ->latest('created_at')
                ->lockForUpdate()
                ->first();

            if ($otp === null || $otp->expires_at->isPast() || $otp->attempts >= self::maxAttempts()) {
                return null;
            }

            if (self::testModeActive()) {
                Log::warning('security.otp_test_mode_used', ['user_id' => $user->getKey(), 'purpose' => $purpose]);
            } elseif (! hash_equals($otp->code_hash, self::hash($user->getKey(), $purpose, $otp->email, $code))) {
                $otp->forceFill(['attempts' => $otp->attempts + 1])->save();

                return null;
            }

            if ($consume) {
                $otp->forceFill(['used_at' => now()])->save();
            }

            return $otp;
        });
    }

    public static function maxAttempts(): int
    {
        return (int) config('fortify.sth.otp.max_attempts', 5);
    }
}
