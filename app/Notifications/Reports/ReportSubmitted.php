<?php

declare(strict_types=1);

namespace App\Notifications\Reports;

use App\Models\Report;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * To Kesiswaan after a leader sends (or re-sends) a laporan hasil (SubmitReport). No NIS/NISN, no files.
 */
class ReportSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $reportId,
        public readonly string $competitionName,
        public readonly string $leaderName,
        public readonly string $resultLabel,
        public readonly bool $late,
        public readonly bool $resubmitted,
    ) {}

    public static function for(Report $report, string $competitionName, string $leaderName, bool $resubmitted): self
    {
        return new self(
            (string) $report->getKey(),
            $competitionName,
            $leaderName,
            $report->claimed_result->label(),
            (bool) $report->late,
            $resubmitted,
        );
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
            ->line($this->body())
            ->salutation('SMAGA Talent Hub');

        $url = $this->url();

        return $url !== null ? $message->action('Buka verifikasi hasil', $url) : $message;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'report.submitted',
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
            'report_id' => $this->reportId,
        ];
    }

    private function title(): string
    {
        return ($this->resubmitted ? 'Laporan hasil diperbaiki: ' : 'Laporan hasil baru: ').$this->competitionName;
    }

    private function body(): string
    {
        return $this->leaderName.' melaporkan '.$this->resultLabel.'.'
            .($this->late ? ' Laporan dikirim setelah batas waktu.' : '')
            .' Mohon diverifikasi.';
    }

    private function url(): ?string
    {
        return Route::has('kesiswaan.verifikasi.index') ? route('kesiswaan.verifikasi.index') : null;
    }
}
