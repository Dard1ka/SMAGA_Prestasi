<?php

declare(strict_types=1);

namespace App\Mail\Transport;

use Illuminate\Mail\MailManager;
use InvalidArgumentException;
use RuntimeException;
use Stringable;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;

/**
 * Mail transport "allowlist" for local development (MAIL_MAILER=allowlist, D-121).
 *
 * A mail whose recipients (To, Cc, Bcc) are ALL on STH_MAIL_ALLOWLIST goes out through the real mailer
 * (`deliver`, by default `smtp` = the MAIL_* settings); every other mail is handed to the catcher (`catch`, by
 * default `mailpit`). The demo data, the scheduler and the factories write to fictional addresses: those would be
 * hard bounces at the mail provider and use up its daily quota. With an empty list nothing leaves the machine.
 *
 * Never outside APP_ENV=local: there every recipient is real, and a mail that silently lands in a catcher is a
 * lost OTP. The app refuses to boot with this mailer elsewhere (AppServiceProvider::guardUnsafeConfiguration()).
 */
final class AllowlistTransport implements Stringable, TransportInterface
{
    public const DRIVER = 'allowlist';

    public const DEFAULT_DELIVER = 'smtp';

    public const DEFAULT_CATCH = 'mailpit';

    public function __construct(
        private readonly TransportInterface $deliver,
        private readonly TransportInterface $catch,
        private readonly RecipientAllowlist $allowlist,
    ) {}

    /**
     * Built by the mail manager from the mailer entry in config/mail.php.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(MailManager $manager, array $config): self
    {
        if (! $manager->getApplication()->environment('local', 'testing')) {
            throw new RuntimeException('Mailer allowlist hanya untuk APP_ENV=local.');
        }

        return new self(
            $manager->mailer(self::innerMailer($manager, $config, 'deliver'))->getSymfonyTransport(),
            $manager->mailer(self::innerMailer($manager, $config, 'catch'))->getSymfonyTransport(),
            RecipientAllowlist::fromString((string) ($config['recipients'] ?? '')),
        );
    }

    /**
     * Name of the mailer a mail goes to: the real one when every recipient is listed, else the catcher.
     *
     * @param  array<string, mixed>  $config  the mailer entry of config/mail.php
     */
    public static function mailerFor(array $config, bool $listed): string
    {
        return $listed
            ? (string) ($config['deliver'] ?? self::DEFAULT_DELIVER)
            : (string) ($config['catch'] ?? self::DEFAULT_CATCH);
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $envelope ??= Envelope::create($message);

        return $this->delivers($envelope->getRecipients())
            ? $this->deliver->send($message, $envelope)
            : $this->catch->send($message, $envelope);
    }

    public function __toString(): string
    {
        return sprintf('%s(%s, %s)', self::DRIVER, $this->deliver, $this->catch);
    }

    /** @param  array<int, Address>  $recipients */
    private function delivers(array $recipients): bool
    {
        if ($recipients === []) {
            return false;
        }

        foreach ($recipients as $recipient) {
            if (! $this->allowlist->allows($recipient->getAddress())) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $config */
    private static function innerMailer(MailManager $manager, array $config, string $key): string
    {
        $name = self::mailerFor($config, $key === 'deliver');
        $transport = $manager->getApplication()->make('config')->get("mail.mailers.{$name}.transport");

        if ($transport === self::DRIVER) {
            throw new InvalidArgumentException("Mailer allowlist: \"{$key}\" tidak boleh menunjuk mailer allowlist lagi.");
        }

        return $name;
    }
}
