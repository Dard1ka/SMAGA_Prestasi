<?php

declare(strict_types=1);

namespace App\Notifications\Registrations;

use App\Models\User;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use App\Support\RevisionQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * SV8, D-199: to Tata Usaha and Kesiswaan (`registration.revise_decide`) when a revision of an approved registration
 * can be decided: every student it invites accepted (at once when it invites nobody). Staff text: no pronoun (D-119).
 */
class RevisionAwaitingDecision extends RegistrationNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new PlainMailMessage)
            ->subject('Revisi pendaftaran menunggu keputusan: '.$this->competitionName())
            ->greeting(MailGreeting::staff($notifiable))
            ->line($this->message())
            ->line('Tim yang sudah disetujui tetap berlaku sampai revisi ini diputuskan.')
            ->action('Lihat revisi', $this->url($notifiable));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'registration.revision_requested',
            'registration_id' => $this->registration->getKey(),
            'title' => 'Revisi pendaftaran',
            'message' => $this->message(),
            'url' => $this->url($notifiable),
        ];
    }

    private function message(): string
    {
        return 'Ketua tim '.$this->competitionName().' ('.$this->leaderName().') mengajukan revisi pendaftaran yang sudah disetujui.';
    }

    private function url(object $notifiable): string
    {
        $queue = $notifiable instanceof User ? RevisionQueue::routeName($notifiable) : RevisionQueue::KESISWAAN;

        return self::link([$queue, RevisionQueue::KESISWAAN, RevisionQueue::TU]);
    }
}
