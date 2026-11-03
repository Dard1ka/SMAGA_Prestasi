<?php

declare(strict_types=1);

namespace App\Notifications\Publications;

use App\Actions\Publications\AchievementFacts;
use App\Actions\Publications\PublicationInput;
use App\Actions\Publications\PublishPublication;
use App\Models\Publication;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * To every active Humas account when a scheduled publication reached its time (RemindDuePublication, once per
 * schedule): "post it now on <channels>, then press Tandai dipublikasikan". The schedule is only this reminder
 * (D-122): the status stays terjadwal and the student is told nothing until Humas presses that button. The text
 * says "siswa baru diberi tahu saat itu" only when that button will really tell the student
 * (PublishPublication::notifiesStudent(); not an alumnus: never claim a notice nobody gets, D-109).
 * Scalars only; the caption and the data for the flyer stay in the app (link to the publication
 * panel); the flyer itself is made by Humas outside STH (M3-E). Queued after commit.
 * Class name and kind `publication.due` are kept: stored bell rows from before D-122 point at them.
 */
final class PublicationDue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $publicationId,
        public readonly string $studentName,
        public readonly string $headline,
        public readonly string $competitionName,
        public readonly string $channels,
        public readonly string $scheduledText,
        public readonly bool $notifiesStudent = true,
    ) {
        $this->afterCommit();
    }

    public static function for(Publication $publication): self
    {
        $facts = AchievementFacts::of($publication->achievement);

        return new self(
            publicationId: (string) $publication->getKey(),
            studentName: $facts->studentName,
            headline: $facts->headline(),
            competitionName: $facts->competitionName,
            channels: PublicationInput::channelLabels($publication->channels),
            scheduledText: (string) $publication->scheduled_at?->locale('id')->translatedFormat('j M Y, H.i'),
            notifiesStudent: PublishPublication::notifiesStudent($publication),
        );
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return ['mail' => (string) config('fortify.sth.mail_queue', 'mail'), 'database' => 'default'];
    }

    public function title(): string
    {
        return 'Waktunya upload publikasi';
    }

    public function body(): string
    {
        $where = $this->channels !== '' ? ' ke '.$this->channels : '';
        // `??`: a payload queued before this property existed is unserialized without it (no constructor runs).
        $after = ($this->notifiesStudent ?? true)
            ? 'Setelah itu tekan Tandai dipublikasikan di halaman publikasi: siswa baru diberi tahu saat itu. '
            : 'Setelah itu tekan Tandai dipublikasikan di halaman publikasi supaya statusnya menjadi Dipublikasikan. ';

        return 'Jadwal '.$this->scheduledText.' sudah tiba: '.$this->headline.' '.$this->competitionName.' ('.$this->studentName.'). '
            .'Upload publikasinya'.$where.' sekarang. '
            .$after
            .'Caption dan data untuk flyer ada di halaman publikasi.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new PlainMailMessage)
            ->subject($this->title())
            ->greeting(MailGreeting::for($notifiable))
            ->line($this->body())
            ->action('Buka publikasi', $this->url());
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'publication.due',
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
            'publication_id' => $this->publicationId,
        ];
    }

    public function url(): string
    {
        return Route::has('humas.beranda') ? route('humas.beranda', ['pilih' => $this->publicationId]) : url('/beranda');
    }
}
