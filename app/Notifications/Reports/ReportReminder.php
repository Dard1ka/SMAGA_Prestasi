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
 * To the ketua: the laporan hasil is still missing (sth:reports:remind, 3 and 14 days after final_on, D-151). There
 * is no deadline any more, so the reminder only asks to report now.
 *
 * A reminder queued before D-151 carries `dueOn` + `daysLeft` instead of `daysAfterFinal`. The day count is a plain
 * property with a default, so such a payload still unserializes and is sent with today's wording:
 * SerializesModels::__unserialize() sets only the properties this class declares and skips the two old keys (no
 * dynamic property, no deprecation; ReportReminderAfterFinalTest).
 */
class ReportReminder extends Notification implements ShouldQueue
{
    use Queueable;

    /** Days between final_on and this reminder (ReportFollowUp::REMINDER_DAYS); 0 on a reminder queued before D-151. */
    public int $daysAfterFinal = 0;

    public function __construct(
        public readonly string $registrationId,
        public readonly string $competitionName,
        int $daysAfterFinal,
    ) {
        $this->daysAfterFinal = $daysAfterFinal;
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
            'kind' => 'report.reminder',
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
            'registration_id' => $this->registrationId,
            'days_after_final' => $this->daysAfterFinal,
        ];
    }

    private function title(): string
    {
        return 'Laporan '.$this->competitionName.' ditunggu';
    }

    private function body(): string
    {
        return 'Lomba '.$this->competitionName.' sudah selesai. '.rtrim(ReportFollowUp::PROMPT, '.').': upload sertifikat dan foto dokumentasi.';
    }

    private function url(): ?string
    {
        return Route::has('siswa.lomba-saya.show')
            ? route('siswa.lomba-saya.show', ['registration' => $this->registrationId])
            : null;
    }
}
