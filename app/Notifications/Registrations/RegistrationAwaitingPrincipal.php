<?php

declare(strict_types=1);

namespace App\Notifications\Registrations;

use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To every approver (registration.approve: Kepala Sekolah and Tata Usaha, D-150) when a registration enters
 * menunggu_kepsek (individu sent, or all members confirmed). The link opens the recipient's own Persetujuan address.
 */
class RegistrationAwaitingPrincipal extends RegistrationNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new PlainMailMessage)
            ->subject('Pendaftaran lomba menunggu persetujuan')
            ->greeting(MailGreeting::staff($notifiable))
            ->line($this->summary())
            ->line('Mohon tinjau dan beri keputusan di SMAGA Talent Hub.')
            ->action('Buka persetujuan', $this->url($notifiable));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'registration.awaiting_principal',
            'registration_id' => $this->registration->getKey(),
            'title' => 'Menunggu persetujuan',
            'message' => $this->summary(),
            'url' => $this->url($notifiable),
        ];
    }

    private function summary(): string
    {
        $type = $this->registration->isTeam() ? 'tim' : 'individu';

        return $this->competitionName().': '.$this->leaderName().' ('.$type.').';
    }

    private function url(object $notifiable): string
    {
        return self::approvalLink($notifiable);
    }
}
