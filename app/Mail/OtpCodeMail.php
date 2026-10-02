<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\OtpPurpose;
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
 * plaintext code. Content: first name, code, validity — no links, no NIS/NISN. Sender = MAIL_FROM_ADDRESS (noreply).
 */
class OtpCodeMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public const PURPOSE_VERIFY_EMAIL = OtpPurpose::VerifyEmail->value;

    public const PURPOSE_RESET_PASSWORD = OtpPurpose::ResetPassword->value;

    public function __construct(
        public string $firstName,
        public string $code,
        public string $purpose,
        public int $ttlMinutes,
    ) {
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
        return new Content(
            view: 'auth.mail.otp-code',
            text: 'auth.mail.otp-code-text',
            with: [
                'intro' => $this->purpose === self::PURPOSE_RESET_PASSWORD
                    ? 'Kamu meminta atur ulang kata sandi SMAGA Talent Hub.'
                    : 'Kamu sedang menghubungkan email ke akun SMAGA Talent Hub.',
                // The text/plain part must not HTML-escape the name ("Ma'ruf", not "Ma&#039;ruf").
                'firstNameText' => new HtmlString($this->firstName),
            ],
        );
    }
}
