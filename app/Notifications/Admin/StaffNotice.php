<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

use App\Enums\RoleName;
use App\Models\User;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Shape of the short staff notices (subject + lines + kind + optional link): queued mail (queue `mail`) + database
 * row for the bell. QA10: split out of AdminNotice so a notice can share the shape WITHOUT being an account notice:
 * only an AdminNotice reaches an address nobody verified (User::routeNotificationForMail(), D-68); work mail with a
 * lomba name or a free-text reason (LetterRevokedNotice) extends this class directly.
 */
abstract class StaffNotice extends Notification implements ShouldQueue
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

    /**
     * The lines as one recipient reads them (QA10: an Admin is told where to cancel, anyone else to ask an Admin).
     *
     * @return list<string>
     */
    public function linesFor(object $notifiable): array
    {
        return $this->lines();
    }

    public function url(): ?string
    {
        return null;
    }

    /** Only an Admin can act in Admin › Pengguna & peran; anyone else is asked to turn to an Admin (QA10, D-119). */
    protected static function isAdmin(object $notifiable): bool
    {
        return $notifiable instanceof User && $notifiable->loadMissing('roles')->hasRole(RoleName::Admin->value);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new PlainMailMessage)->subject($this->subject());

        foreach ($this->linesFor($notifiable) as $line) {
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
            'body' => implode(' ', $this->linesFor($notifiable)),
            'url' => $this->url(),
        ];
    }
}
