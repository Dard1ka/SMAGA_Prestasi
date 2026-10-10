<?php

declare(strict_types=1);

namespace App\Notifications\Publications;

use App\Actions\Publications\EventWhen;
use App\Models\Appreciation;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * To the student when an achievement is put on an appreciation event (e.g. upacara Senin). Scalars only;
 * never NIS/NISN/birth date. Queued on the mail queue, sent after commit.
 * `eventDate` is the phrase after "pada": "Senin, 12 Oktober 2026 pukul 07.00 WIB" (EventWhen::sentence(), D-123),
 * the date alone for an event without a start time.
 */
final class AppreciationScheduled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $appreciationId,
        public readonly string $eventName,
        public readonly string $eventDate,
        public readonly string $competitionName,
        public readonly string $resultLabel,
    ) {
        $this->onQueue((string) config('fortify.sth.mail_queue', 'mail'));
        $this->afterCommit();
    }

    public static function for(Appreciation $appreciation): self
    {
        $appreciation->loadMissing(['event', 'achievement.competition']);

        return new self(
            appreciationId: (string) $appreciation->getKey(),
            eventName: (string) ($appreciation->event?->name ?? 'acara apresiasi'),
            // Day and start time in one phrase (D-123); the property keeps its name for jobs already queued.
            eventDate: EventWhen::sentence($appreciation->event),
            competitionName: (string) ($appreciation->achievement?->competition?->name ?? 'lomba'),
            resultLabel: (string) ($appreciation->achievement?->result?->label() ?? 'Prestasi'),
        );
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function title(): string
    {
        return 'Kamu masuk daftar apresiasi';
    }

    public function body(): string
    {
        $when = $this->eventDate !== '' ? ' pada '.$this->eventDate : '';

        return 'Prestasi kamu, '.$this->resultLabel.' '.$this->competitionName.', akan diapresiasi di '.$this->eventName.$when.'.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new PlainMailMessage)
            ->subject($this->title())
            ->greeting(MailGreeting::student($notifiable))
            ->line($this->body())
            ->action('Buka SMAGA Talent Hub', $this->url());
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'appreciation.scheduled',
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
            'appreciation_id' => $this->appreciationId,
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
