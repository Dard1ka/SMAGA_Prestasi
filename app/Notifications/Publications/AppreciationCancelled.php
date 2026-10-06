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
 * To the student when an appreciation event that listed their achievement is deleted (M1-E, "Hapus acara"):
 * they were told "Kamu masuk daftar apresiasi", so they are told it is off. One notification per student and
 * event, also when several of their achievements were on it. Scalars only (the rows are gone when this is
 * sent); never NIS/NISN/birth date. Queued on the mail queue, sent after commit.
 */
final class AppreciationCancelled extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $achievements  "Juara II OPSI 2026", one per released row of this student
     */
    public function __construct(
        public readonly string $eventName,
        public readonly string $eventDate,
        public readonly array $achievements,
    ) {
        $this->onQueue((string) config('fortify.sth.mail_queue', 'mail'));
        $this->afterCommit();
    }

    /**
     * @param  iterable<Appreciation>  $appreciations  the student's rows on the event (achievement.competition loaded)
     */
    public static function for(AppreciationEvent $event, iterable $appreciations): self
    {
        $achievements = [];

        foreach ($appreciations as $appreciation) {
            $achievements[] = trim(
                (string) ($appreciation->achievement?->result?->label() ?? 'Prestasi')
                .' '.(string) ($appreciation->achievement?->competition?->name ?? 'lomba')
            );
        }

        return new self(
            eventName: (string) $event->name,
            eventDate: (string) $event->held_on?->locale('id')->translatedFormat('l, j F Y'),
            achievements: $achievements,
        );
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function title(): string
    {
        return 'Acara apresiasi dibatalkan';
    }

    public function body(): string
    {
        $when = $this->eventDate !== '' ? ' pada '.$this->eventDate : '';
        $what = count($this->achievements) === 1
            ? 'Prestasi kamu, '.$this->achievements[0].','
            : count($this->achievements).' prestasi kamu';

        return 'Acara '.$this->eventName.$when.' dibatalkan. '.$what
            .' belum jadi diapresiasi di acara itu dan bisa dijadwalkan lagi di acara lain.';
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
            'kind' => 'appreciation.cancelled',
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
