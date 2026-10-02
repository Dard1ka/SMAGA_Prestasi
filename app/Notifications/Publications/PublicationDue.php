<?php

declare(strict_types=1);

namespace App\Notifications\Publications;

use App\Actions\Publications\AchievementFacts;
use App\Actions\Publications\PublicationInput;
use App\Models\Publication;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * To every active Humas account when a scheduled publication reached its time and was marked
 * dipublikasikan automatically (B3, backlog #12): "post it now on <channels>". Scalars only; the
 * caption and flyer stay in the app (link to the publication panel). Queued after commit.
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
        return 'Waktunya unggah publikasi';
    }

    public function body(): string
    {
        $where = $this->channels !== '' ? ' ke '.$this->channels : '';

        return 'Jadwal '.$this->scheduledText.' sudah tiba: '.$this->headline.' '.$this->competitionName.' ('.$this->studentName.'). '
            .'Statusnya kini Dipublikasikan. Unggah caption dan flyer'.$where.' sekarang.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new PlainMailMessage)
            ->subject($this->title())
            ->line($this->body())
            ->action('Buka publikasi', $this->url())
            ->salutation('SMAGA Talent Hub');
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
