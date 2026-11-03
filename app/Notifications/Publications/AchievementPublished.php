<?php

declare(strict_types=1);

namespace App\Notifications\Publications;

use App\Actions\Publications\PublicationInput;
use App\Models\Publication;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * To the student after Humas marked the publication as published. Scalars only (no model
 * serialisation); never NIS/NISN/birth date. Queued on the mail queue, sent after commit.
 */
final class AchievementPublished extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $publicationId,
        public readonly string $achievementId,
        public readonly string $competitionName,
        public readonly string $resultLabel,
        public readonly string $channels,
    ) {
        $this->onQueue((string) config('fortify.sth.mail_queue', 'mail'));
        $this->afterCommit();
    }

    public static function for(Publication $publication): self
    {
        $publication->loadMissing('achievement.competition');
        $achievement = $publication->achievement;

        return new self(
            publicationId: (string) $publication->getKey(),
            achievementId: (string) $achievement?->getKey(),
            competitionName: (string) ($achievement?->competition?->name ?? 'lomba'),
            resultLabel: (string) ($achievement?->result?->label() ?? 'Prestasi'),
            channels: PublicationInput::channelLabels($publication->channels),
        );
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function title(): string
    {
        return 'Prestasimu sudah dipublikasikan';
    }

    public function body(): string
    {
        $where = $this->channels !== '' ? ' lewat '.$this->channels : '';

        return 'Humas SMAGA membagikan prestasi kamu, '.$this->resultLabel.' '.$this->competitionName.$where.'. Selamat!';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new PlainMailMessage)
            ->subject($this->title())
            ->greeting(MailGreeting::student($notifiable))
            ->line($this->body())
            ->action('Lihat portofolio', $this->url());
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'publication.published',
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
            'publication_id' => $this->publicationId,
            'achievement_id' => $this->achievementId,
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
