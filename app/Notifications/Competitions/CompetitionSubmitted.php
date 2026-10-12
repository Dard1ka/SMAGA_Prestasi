<?php

declare(strict_types=1);

namespace App\Notifications\Competitions;

use App\Models\Competition;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Owner: W2-A1 (sent only by App\Actions\Competitions\SubmitCompetition); it sits in this folder next to
 * W2-B's CompetitionPublished/CompetitionRejected — edit it together with SubmitCompetition (W3-A note).
 * To verifiers (competition.verify: Kesiswaan + Admin) when anyone submits a lomba (D-27).
 * The submitted URL is never put in the mail (phishing, SECURITY §3m-1): reviewers open it in STH.
 */
class CompetitionSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    // Not readonly: SerializesModels restores the model on the queue worker.
    public function __construct(public Competition $competition, public string $submitterName)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return [
            'mail' => (string) config('fortify.sth.mail_queue', 'mail'),
            'database' => 'default',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new PlainMailMessage)
            ->subject('Verifikasi usulan lomba: '.$this->competition->name)
            ->greeting(MailGreeting::for($notifiable))
            ->line($this->message())
            ->line('Periksa penyelenggara dan link-nya sebelum dipublikasikan.')
            ->action('Verifikasi usulan lomba', $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'competition.submitted',
            'competition_id' => $this->competition->getKey(),
            'title' => 'Usulan lomba baru',
            'message' => $this->message(),
            'url' => $this->url(),
        ];
    }

    private function message(): string
    {
        return $this->submitterName.' mengusulkan '.$this->competition->name.'.';
    }

    private function url(): string
    {
        return Route::has('kesiswaan.lomba.index')
            ? route('kesiswaan.lomba.index', ['tab' => 'verifikasi'])
            : url('/beranda');
    }
}
