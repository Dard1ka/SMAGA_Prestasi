<?php

declare(strict_types=1);

namespace App\Notifications\Reports;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * To the ketua when the scheduler moves a registration to menunggu_laporan (the day after final_on).
 */
class ReportWindowOpened extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $registrationId,
        public readonly string $competitionName,
        public readonly string $dueOn,
    ) {}

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
        $message = (new MailMessage)
            ->subject($this->title())
            ->line($this->body())
            ->salutation('SMAGA Talent Hub');

        $url = $this->url();

        return $url !== null ? $message->action('Laporkan hasil', $url) : $message;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'report.window_opened',
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
            'registration_id' => $this->registrationId,
        ];
    }

    private function title(): string
    {
        return 'Saatnya laporkan hasil '.$this->competitionName;
    }

    private function body(): string
    {
        return 'Laporkan hasil lombamu paling lambat '.$this->dueOn.'. Setiap anggota tim mendapat poin penuh setelah diverifikasi.';
    }

    private function url(): ?string
    {
        return Route::has('siswa.lomba-saya.show')
            ? route('siswa.lomba-saya.show', ['registration' => $this->registrationId])
            : null;
    }
}
