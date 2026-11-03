<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Transport\AllowlistTransport;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;

/**
 * Local development (D-121): from APP_ENV=local a real mail provider is only reachable through the allowlist mailer.
 *
 * Two places use the rule of deliversEverything():
 * - the boot guard (AppServiceProvider::guardUnsafeConfiguration()) refuses a DEFAULT mailer that would deliver
 *   every mail;
 * - handle(), a MessageSending listener (QA9), cancels a mail sent through such a mailer BY NAME. In allowlist mode the
 *   `smtp` mailer holds the provider's host and login (it is the allowlist's `deliver`), and a mailable queued while
 *   MAIL_MAILER was smtp (Mailpit) remembers "smtp" (Mailer::queue()): retried after the switch, or sent with
 *   Mail::mailer('smtp') from tinker, it would go straight to the provider, usually to a fictional @sth.test address.
 *
 * Not affected: mail through the allowlist mailer (its `deliver` transport is called by AllowlistTransport, not sent
 * through a second Mailer, so no second event), Mailpit (smtp without a login), log, array, and every environment
 * other than local.
 */
final class LocalMailGuard
{
    public function __construct(private readonly Application $app) {}

    /**
     * True when a mailer with these settings (an entry of config('mail.mailers')) would hand every mail to a real
     * mail service. Safe ones: the allowlist, mailers that send nothing (log, array) and an SMTP server without a
     * login, which is a mail catcher such as Mailpit. An unknown mailer name (no settings) is left to Laravel, which
     * names it when the first mail is sent.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function deliversEverything(array $settings): bool
    {
        $transport = $settings['transport'] ?? null;

        if ($settings === [] || in_array($transport, [AllowlistTransport::DRIVER, 'log', 'array'], true)) {
            return false;
        }

        return $transport !== 'smtp' || filled($settings['username'] ?? null) || filled($settings['url'] ?? null);
    }

    /** The settings of a mailer by name; [] when there is no such mailer. @return array<string, mixed> */
    public static function settingsOf(string $mailer): array
    {
        return (array) config('mail.mailers.'.$mailer, []);
    }

    /**
     * MessageSending listener (QA9). Returning false cancels the mail (Mailer::shouldSendMessage()); null lets it
     * through. The log names the mailer only, never a recipient.
     */
    public function handle(MessageSending $event): ?bool
    {
        if (! $this->app->environment('local')) {
            return null;
        }

        $mailer = (string) ($event->data['mailer'] ?? config('mail.default'));

        if (! self::deliversEverything(self::settingsOf($mailer))) {
            return null;
        }

        Log::warning('mail.local_mailer_blocked', [
            'mailer' => $mailer,
            'reason' => 'APP_ENV=local: real mail only through MAIL_MAILER=allowlist (D-121); the mail was not sent',
        ]);

        return false;
    }
}
