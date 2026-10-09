<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\Accounts\SecurityAlerts;
use App\Actions\Users\AdminRecipients;
use App\Enums\RoleName;
use App\Models\User;
use App\Notifications\Accounts\AccountAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Alerts of the account controls (QA10 review), same channel as SecurityAlerts (AccountAlert: queued mail + bell),
 * to every Admin, and to Kesiswaan too for a student account (as for the K3 freeze):
 * - handle(): an account reached LoginLockout::ACCOUNT_LOCK_FAILURES failed logins within 24 h and is now closed to
 *   unknown devices. Sent once per 24 h window: the lock refuses unknown devices before their password is checked, so no
 *   further failure is counted.
 * - resetCodes(): the wrong "Lupa kata sandi" codes of an account reached VerifyOtpCode::RESET_ALERT_FAILURES in a day,
 *   over every browser (no block; VerifyOtpCode sends it once a day per account).
 * At most MAX_PER_HOUR of these alerts go out an hour, both kinds together (QA10, second review: a few addresses can
 * push many accounts over the limit within minutes); the rest are only logged (`security.login_attack_alerts_capped`).
 */
final class LoginAttackAlert
{
    public const KIND = 'account.login_attack';

    public const KIND_RESET_CODES = 'account.reset_code_attack';

    public const MAX_PER_HOUR = 5;

    private const SENT_KEY = 'sth:attack-alerts';

    private const HELD_KEY = 'sth:attack-alerts-held';

    public function __construct(private readonly AdminRecipients $recipients) {}

    public function handle(User $account, int $failures): void
    {
        $this->send($account, self::KIND, 'Banyak percobaan masuk yang gagal', [
            SecurityAlerts::describe($account).' gagal masuk '.$failures.' kali dalam 24 jam.',
            'Sampai 24 jam itu berakhir, akun ini hanya bisa masuk dari perangkat yang pernah dipakai pemiliknya. '
                .'Pemiliknya tetap bisa memakai "Lupa kata sandi", atau meminta reset kata sandi ke Admin.',
        ]);
    }

    public function resetCodes(User $account, int $failures): void
    {
        $this->send($account, self::KIND_RESET_CODES, 'Banyak kode "Lupa kata sandi" yang salah', [
            SecurityAlerts::describe($account).': kode "Lupa kata sandi" salah dimasukkan '.$failures.' kali dalam 24 jam.',
            'Akun ini tidak dikunci karenanya. Bila pemiliknya tidak sedang mencoba, mungkin ada yang menebak kodenya: '
                .'temui pemiliknya dan, bila perlu, reset kata sandinya.',
        ]);
    }

    /** @param list<string> $lines */
    private function send(User $account, string $kind, string $title, array $lines): void
    {
        $student = LoginIdentifier::isStudentAccount($account);
        $alert = new AccountAlert($kind, $title, $lines, $student ? AccountAlert::TARGET_ACTIVATION : AccountAlert::TARGET_SYSTEM);

        $sent = RateLimiter::attempt(
            self::SENT_KEY,
            self::MAX_PER_HOUR,
            fn () => Notification::send($this->recipients($student), $alert),
            3600,
        );

        if ($sent === false) {
            Log::warning('security.login_attack_alerts_capped', [
                'kind' => $kind,
                'user_id' => $account->getKey(),
                'held_back' => RateLimiter::hit(self::HELD_KEY, 3600),
            ]);
        }
    }

    /** @return iterable<User> */
    private function recipients(bool $student): iterable
    {
        $recipients = $this->recipients->admins();

        if (! $student) {
            return $recipients;
        }

        return $recipients
            ->merge(User::query()->role(RoleName::Kesiswaan->value)->canLogIn()->get())
            ->unique(fn (User $user) => $user->getKey())
            ->values();
    }
}
