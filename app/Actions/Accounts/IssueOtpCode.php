<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Actions\Fortify\LoginIdentifier;
use App\Actions\Fortify\LoginLockout;
use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Creates a fresh 6-digit OTP for (user, purpose, email) and mails it from noreply (queued + encrypted).
 * Enforces the 60-second resend cooldown and the limits of SECURITY §3b: at most 5 codes an hour and 10 a day
 * (QA10). Returns false (nothing sent) while any of them holds. The plaintext code is never stored or logged.
 *
 * - Without a requester (the account's own signed-in flows: activation, email change) the limits count the codes of the
 *   account and purpose, and a new code cancels the older unused ones of that purpose.
 * - With a requester (the browser that asked for a "Lupa kata sandi" code, OtpRequester; QA10 review) the code belongs
 *   to that browser: the cooldown and the 5/10 limits count that browser's codes, a new code cancels only that
 *   browser's older ones, and the account as a whole gets at most ACCOUNT_RESET_CODES_PER_DAY a day. So a stranger who
 *   knows a NIS cannot cancel or use up the owner's codes (ResetCodeLimits also limits the asking per account and
 *   address); only the looser account cap remains as a lever.
 * The limits look back 24 h (CleanupOtpCodes keeps the rows that long), but never before the account's locks were last
 * cleared (LoginLockout::clear(): Admin reset, unfreeze, "Buka ulang", reset by code), so a hijacker's codes of the day
 * cannot block the owner's new activation.
 */
final class IssueOtpCode
{
    public const MAX_PER_HOUR = 5;

    public const MAX_PER_DAY = 10;

    /** Reset codes of one account a day, over every browser that asks: with 5 tries each at most 100 guesses (QA10). */
    public const ACCOUNT_RESET_CODES_PER_DAY = 20;

    public function __construct(
        private readonly OtpRequester $requesters,
        private readonly LoginLockout $lockout,
    ) {}

    public function handle(User $user, string $purpose, string $email, ?string $requester = null): bool
    {
        if ($this->secondsUntilResend($user, $purpose, $requester) > 0) {
            return false;
        }

        if ($requester !== null && $this->recent($user, $purpose)->count() >= self::ACCOUNT_RESET_CODES_PER_DAY) {
            return false;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $email = mb_strtolower(trim($email));
        $ttl = (int) config('fortify.sth.otp.ttl_minutes', 10);
        $older = $requester !== null ? $this->requesters->codes($requester, $user, $purpose) : null;

        $otp = DB::transaction(function () use ($user, $purpose, $email, $code, $ttl, $older): OtpCode {
            $previous = OtpCode::query()
                ->where('user_id', $user->getKey())
                ->where('purpose', $purpose)
                ->whereNull('used_at');

            if ($older !== null) {
                $previous->whereKey($older);
            }

            $previous->update(['used_at' => now()]);

            $otp = new OtpCode;
            $otp->forceFill([
                'user_id' => $user->getKey(),
                'purpose' => $purpose,
                'email' => $email,
                'code_hash' => VerifyOtpCode::hash($user->getKey(), $purpose, $email, $code),
                'expires_at' => now()->addMinutes($ttl),
                'attempts' => 0,
            ])->save();

            return $otp;
        });

        if ($requester !== null) {
            $this->requesters->remember($requester, $user, $purpose, (string) $otp->getKey());
        }

        Mail::to($email)->queue(new OtpCodeMail(self::firstName($user->name), $code, $purpose, $ttl));

        return true;
    }

    /** Seconds until the next code may go out: the cooldown, or the hourly / daily limit, whichever ends last. */
    public function secondsUntilResend(User $user, string $purpose, ?string $requester = null): int
    {
        $query = $this->recent($user, $purpose);

        if ($requester !== null) {
            $query->whereKey($this->requesters->codes($requester, $user, $purpose));
        }

        /** @var list<CarbonInterface> $sent newest first */
        $sent = $query->orderByDesc('created_at')->limit(self::MAX_PER_DAY)->pluck('created_at')->all();

        if ($sent === []) {
            return 0;
        }

        $wait = $this->waitAfter($sent[0], (int) config('fortify.sth.otp.resend_cooldown_seconds', 60));

        // The oldest code of a full hour (or day) has to leave that window before another one may go out.
        if (count($sent) >= self::MAX_PER_HOUR) {
            $wait = max($wait, $this->waitAfter($sent[self::MAX_PER_HOUR - 1], 3600));
        }

        if (count($sent) >= self::MAX_PER_DAY) {
            $wait = max($wait, $this->waitAfter($sent[self::MAX_PER_DAY - 1], 86400));
        }

        return $wait;
    }

    /**
     * Codes of the account and purpose the limits count: the last 24 h, and none from before the account's locks were
     * last cleared.
     *
     * @return Builder<OtpCode>
     */
    public function recent(User $user, string $purpose): Builder
    {
        $since = now()->subDay();
        $cleared = $this->lockout->clearedAt(LoginIdentifier::accountBucket($user));

        if ($cleared !== null && $cleared > $since->getTimestamp()) {
            $since = now()->setTimestamp($cleared);
        }

        return OtpCode::query()
            ->where('user_id', $user->getKey())
            ->where('purpose', $purpose)
            ->where('created_at', '>', $since);
    }

    private function waitAfter(CarbonInterface $sentAt, int $seconds): int
    {
        return max(0, $seconds - (int) now()->diffInSeconds($sentAt, true));
    }

    /** The active (unused, unexpired) code's target email, if any — used to resume the verification step. */
    public function pendingEmail(User $user, string $purpose): ?string
    {
        return OtpCode::query()
            ->where('user_id', $user->getKey())
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->value('email');
    }

    private static function firstName(string $name): string
    {
        return Str::of($name)->trim()->explode(' ')->first() ?: 'Siswa';
    }
}
