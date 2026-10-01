<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Creates a fresh 6-digit OTP for (user, purpose, email) and mails it from noreply (queued + encrypted).
 * Older unused codes of the same purpose are invalidated. Enforces the 60-second resend cooldown: returns
 * false (nothing sent) while the cooldown runs. The plaintext code is never stored or logged.
 */
final class IssueOtpCode
{
    public function handle(User $user, string $purpose, string $email): bool
    {
        if ($this->secondsUntilResend($user, $purpose) > 0) {
            return false;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $email = mb_strtolower(trim($email));
        $ttl = (int) config('fortify.sth.otp.ttl_minutes', 10);

        DB::transaction(function () use ($user, $purpose, $email, $code, $ttl): void {
            OtpCode::query()
                ->where('user_id', $user->getKey())
                ->where('purpose', $purpose)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $otp = new OtpCode;
            $otp->forceFill([
                'user_id' => $user->getKey(),
                'purpose' => $purpose,
                'email' => $email,
                'code_hash' => VerifyOtpCode::hash($user->getKey(), $purpose, $email, $code),
                'expires_at' => now()->addMinutes($ttl),
                'attempts' => 0,
            ])->save();
        });

        Mail::to($email)->queue(new OtpCodeMail(self::firstName($user->name), $code, $purpose, $ttl));

        return true;
    }

    public function secondsUntilResend(User $user, string $purpose): int
    {
        $last = OtpCode::query()
            ->where('user_id', $user->getKey())
            ->where('purpose', $purpose)
            ->latest('created_at')
            ->value('created_at');

        if ($last === null) {
            return 0;
        }

        $cooldown = (int) config('fortify.sth.otp.resend_cooldown_seconds', 60);
        $elapsed = (int) now()->diffInSeconds($last, true);

        return max(0, $cooldown - $elapsed);
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
