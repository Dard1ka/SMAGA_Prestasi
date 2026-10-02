<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

use App\Notifications\Support\PlainMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base for the Admin-area notices (weights, signature, Kepala Sekolah replacement, archive run):
 * queued mail (queue `mail`) + database row for the bell. Short Indonesian text, no personal data
 * beyond staff names. Mail is skipped automatically for accounts without an email.
 */
abstract class AdminNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue((string) config('fortify.sth.mail_queue', 'mail'));
    }

    abstract public function subject(): string;

    /** @return list<string> */
    abstract public function lines(): array;

    /** Stable key for the bell/filtering (e.g. 'weights.published'). */
    abstract public function kind(): string;

    public function url(): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new PlainMailMessage)->subject($this->subject());

        foreach ($this->lines() as $line) {
            $message->line($line);
        }

        if ($this->url() !== null) {
            $message->action('Buka SMAGA Talent Hub', $this->url());
        }

        return $message->salutation('SMAGA Talent Hub');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind(),
            'title' => $this->subject(),
            'body' => implode(' ', $this->lines()),
            'url' => $this->url(),
        ];
    }
}
