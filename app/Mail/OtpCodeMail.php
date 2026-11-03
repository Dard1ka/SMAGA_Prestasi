<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\OtpPurpose;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\HtmlString;

/**
 * 6-digit OTP e-mail (docs/SECURITY.md §3b). Queued and ENCRYPTED on the queue because the payload holds the
 * plaintext code. Content: the greeting, the code, its validity — no links, no NIS/NISN. Sender = MAIL_FROM_ADDRESS
 * (noreply). Plain Blade, not Markdown; greeting rule, card and footer sentence are those of the notification mails
 * (MailGreeting, resources/views/vendor/mail/html/themes/sth.css, PlainMailMessage::FOOTER).
 */
class OtpCodeMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public const PURPOSE_VERIFY_EMAIL = OtpPurpose::VerifyEmail->value;

    public const PURPOSE_RESET_PASSWORD = OtpPurpose::ResetPassword->value;

    /**
     * The first line of the mail (MailGreeting::for() of the account). A property with a default, not a promoted
     * parameter: a mail that was queued before this property existed still unserializes, and is then greeted by
     * the first name.
     */
    public ?string $greeting = null;

    public function __construct(
        public string $firstName,
        public string $code,
        public string $purpose,
        public int $ttlMinutes,
        ?string $greeting = null,
    ) {
        $this->greeting = $greeting;
        $this->onQueue((string) config('fortify.sth.otp.queue', 'otp'));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->purpose === self::PURPOSE_RESET_PASSWORD
                ? 'Kode atur ulang kata sandi'
                : 'Kode verifikasi email',
        );
    }

    public function content(): Content
    {
        $greeting = $this->greeting ?? MailGreeting::student($this->firstName);

        return new Content(
            view: 'auth.mail.otp-code',
            text: 'auth.mail.otp-code-text',
            with: [
                'intro' => $this->purpose === self::PURPOSE_RESET_PASSWORD
                    ? 'Ada permintaan atur ulang kata sandi SMAGA Talent Hub.'
                    : 'Email ini sedang dihubungkan ke akun SMAGA Talent Hub.',
                // Other names than the public property: a public property of the same name would win over these.
                'greetingLine' => $greeting,
                // The text/plain part must not HTML-escape the name ("Ma'ruf", not "Ma&#039;ruf").
                'greetingText' => new HtmlString($greeting),
                'footer' => PlainMailMessage::FOOTER,
            ],
        );
    }
}
