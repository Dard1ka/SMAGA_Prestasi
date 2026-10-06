<?php

declare(strict_types=1);

namespace App\Notifications\Publications;

use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * To the student when one achievement is taken off an appreciation event that itself goes on ("Lepas dari
 * acara", M2-B): they were told "Kamu masuk daftar apresiasi", so they are told it is off for that event.
 * The event is not cancelled (that is AppreciationCancelled). Scalars only (the row is gone when this is
 * sent); never NIS/NISN/birth date. Queued on the mail queue, sent after commit.
 */
final class AppreciationReleased extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $eventName,
        public readonly string $eventDate,
        public readonly string $achievement,
    ) {
        $this->onQueue((string) config('fortify.sth.mail_queue', 'mail'));
        $this->afterCommit();
    }

    /** @param Appreciation $appreciation the released row (achievement.competition loaded) */
    public static function for(AppreciationEvent $event, Appreciation $appreciation): self
    {
        return new self(
            eventName: (string) $event->name,
            eventDate: (string) $event->held_on?->locale('id')->translatedFormat('l, j F Y'),
            achievement: trim(
                (string) ($appreciation->achievement?->result?->label() ?? 'Prestasi')
                .' '.(string) ($appreciation->achievement?->competition?->name ?? 'lomba')
            ),
        );
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function title(): string
    {
        return 'Jadwal apresiasi berubah';
    }

    public function body(): string
    {
        $when = $this->eventDate !== '' ? ' pada '.$this->eventDate : '';

        return 'Prestasi kamu, '.$this->achievement.', tidak jadi diapresiasi di '.$this->eventName.$when
            .'. Prestasi ini bisa dijadwalkan lagi di acara lain.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = strtok(trim((string) ($notifiable->name ?? '')), ' ') ?: null;

        return (new PlainMailMessage)
            ->subject($this->title())
            ->greeting($firstName !== null ? 'Halo '.$firstName.',' : 'Halo,')
            ->line($this->body())
            ->action('Buka SMAGA Talent Hub', $this->url())
            ->salutation('SMAGA Talent Hub');
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'appreciation.released',
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
        ];
    }

    public function url(): string
    {
        foreach (['siswa.portofolio', 'siswa.beranda'] as $name) {
            if (Route::has($name)) {
                return route($name);
            }
        }

        return url('/beranda');
    }
}
