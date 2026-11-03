<?php

declare(strict_types=1);

namespace App\Notifications\Reports;

use App\Domain\Deadlines\ReportFollowUp;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * To the ketua when the scheduler moves a registration to menunggu_laporan (the day after final_on). No deadline
 * since D-151: it asks to report now (ReportFollowUp::PROMPT).
 */
class ReportWindowOpened extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * A team registration: only then the mail says every member gets full points (FS1 QA). A declared default, not a
     * promoted one, so a notice queued before this property existed still reads as before.
     */
    public bool $team = true;

    public function __construct(
        public readonly string $registrationId,
        public readonly string $competitionName,
        bool $team = true,
    ) {
        $this->team = $team;
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
        $message = (new PlainMailMessage)
            ->subject($this->title())
            ->greeting(MailGreeting::student($notifiable))
            ->line($this->body());

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
        return ReportFollowUp::PROMPT.($this->team
            ? ' Setiap anggota tim mendapat poin penuh setelah diverifikasi.'
            : ' Poin diberikan setelah diverifikasi.');
    }

    private function url(): ?string
    {
        return Route::has('siswa.lomba-saya.show')
            ? route('siswa.lomba-saya.show', ['registration' => $this->registrationId])
            : null;
    }
}
