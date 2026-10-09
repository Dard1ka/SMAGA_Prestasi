<?php

declare(strict_types=1);

namespace App\Notifications\Verification;

use App\Notifications\Support\PlainMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Base for the W2-B notifications (Competitions, Verification, Talent): queued mail + database, sent after
 * the surrounding transaction commits. Payload = scalars only (no model serialisation, no NIS/NISN/files).
 * Database shape matches the other areas: kind, title, body, url (+ ids).
 */
abstract class Notice extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, string|int|null>  $extra  ids for the notifications screen
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $routeName = null,
        public readonly array $routeParameters = [],
        public readonly string $actionLabel = 'Buka SMAGA Talent Hub',
        public readonly array $extra = [],
    ) {
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
        return ['mail' => (string) config('fortify.sth.mail_queue', 'mail'), 'database' => 'default'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = strtok(trim((string) ($notifiable->name ?? '')), ' ') ?: null;

        $message = (new PlainMailMessage)
            ->subject($this->titleFor($notifiable))
            ->greeting($firstName !== null ? 'Halo '.$firstName.',' : 'Halo,')
            ->line($this->bodyFor($notifiable))
            ->salutation('SMAGA Talent Hub');

        $url = $this->url();

        return $url !== null ? $message->action($this->actionLabelFor($notifiable), $url) : $message;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->titleFor($notifiable),
            'body' => $this->bodyFor($notifiable),
            'url' => $this->url(),
            ...$this->extra,
        ];
    }

    /** Hooks for a notice whose text depends on the recipient (QA10: ReportRevisionRequested). */
    protected function titleFor(object $notifiable): string
    {
        return $this->title;
    }

    protected function bodyFor(object $notifiable): string
    {
        return $this->body;
    }

    protected function actionLabelFor(object $notifiable): string
    {
        return $this->actionLabel;
    }

    public function url(): ?string
    {
        if ($this->routeName === null || ! Route::has($this->routeName)) {
            return null;
        }

        return route($this->routeName, $this->routeParameters);
    }
}
