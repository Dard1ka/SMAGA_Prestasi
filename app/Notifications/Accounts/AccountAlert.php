<?php

declare(strict_types=1);

namespace App\Notifications\Accounts;

use App\Enums\RoleName;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Account-security alert for Admin/Kesiswaan (B1): account frozen after 10 failed logins (K3), "Bukan saya?"
 * report (K8, the Admin "ticket"), more than 10 resets in an hour (K10). Queued mail (queue `mail`) + database
 * row for the bell. Names a student by name + class only (never NIS/NISN, email or birth date).
 */
final class AccountAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public const TARGET_ACTIVATION = 'aktivasi';

    public const TARGET_SYSTEM = 'ringkasan';

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly array $lines,
        public readonly string $target = self::TARGET_ACTIVATION,
    ) {
        $this->onQueue((string) config('fortify.sth.mail_queue', 'mail'));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new PlainMailMessage)->subject($this->title)->greeting(MailGreeting::for($notifiable));

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        $url = $this->url($notifiable);

        if ($url !== null) {
            $message->action('Buka SMAGA Talent Hub', $url);
        }

        return $message;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => implode(' ', $this->lines),
            'url' => $this->url($notifiable),
        ];
    }

    public function url(object $notifiable): ?string
    {
        $isAdmin = method_exists($notifiable, 'hasRole') && $notifiable->hasRole(RoleName::Admin->value);

        $candidates = match (true) {
            $this->target === self::TARGET_SYSTEM => ['admin.ringkasan'],
            $isAdmin => ['admin.aktivasi.index', 'kesiswaan.aktivasi.index'],
            default => ['kesiswaan.aktivasi.index'],
        };

        foreach ($candidates as $name) {
            if (Route::has($name)) {
                return route($name);
            }
        }

        return null;
    }
}
