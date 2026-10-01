<?php

declare(strict_types=1);

namespace App\Notifications\Registrations;

use App\Models\Registration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Base for registration notifications: queued (after commit), mail on the mail queue + database.
 * Content stays minimal (SECURITY §3m-3): lomba name, people's names, a link; never NIS/NISN/contact data.
 */
abstract class RegistrationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    // Not readonly: SerializesModels restores the model on the queue worker.
    public function __construct(public Registration $registration)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return [
            'mail' => (string) config('fortify.sth.mail_queue', 'mail'),
            'database' => 'default',
        ];
    }

    protected function competitionName(): string
    {
        return (string) $this->registration->loadMissing('competition')->competition?->name;
    }

    protected function leaderName(): string
    {
        return (string) $this->registration->loadMissing('leader.user')->leader?->user?->name;
    }

    protected static function firstName(object $notifiable): string
    {
        return strtok(trim((string) ($notifiable->name ?? '')), ' ') ?: 'Kamu';
    }

    /** First existing route (screens land in wave 2 in parallel), else the role landing. */
    protected static function link(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            [$name, $parameters] = is_array($candidate) ? $candidate : [$candidate, []];

            if (Route::has($name)) {
                return route($name, $parameters);
            }
        }

        return url('/beranda');
    }
}
