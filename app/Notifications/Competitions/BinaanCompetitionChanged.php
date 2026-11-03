<?php

declare(strict_types=1);

namespace App\Notifications\Competitions;

use App\Models\Competition;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use App\Notifications\Verification\Notice;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the guru pembimbing: Kesiswaan changed something a binaan relies on in a published lomba (lead, 2026-10-10,
 * D-126). Who is told and about which facts: App\Actions\Competitions\CompetitionChange. One notice per guru and
 * save, whatever the number of the guru's registrations on that lomba: with one it opens that binaan page, with
 * several the guru's list. The guru is greeted formally ("Yth. <full name>,") and never hears about the deadline or
 * the team size, which are the team's matter. The students get CompetitionChanged. Scalars only, no student names;
 * never a web address.
 */
final class BinaanCompetitionChanged extends Notice
{
    public const KIND = 'competition.binaan_changed';

    public const INTRO = 'Kesiswaan mengubah data lomba yang diikuti siswa binaan.';

    /** @var list<string> one sentence per changed fact (CompetitionChange::line()) */
    public array $lines = [];

    /** false = bell only: the save reached more people than CompetitionChange::MAIL_LIMIT. */
    public bool $mail = true;

    /**
     * @param  list<string>  $lines
     * @param  string|null  $registrationId  the binaan page to open; null = several registrations, the guru's list
     */
    public static function for(Competition $competition, array $lines, ?string $registrationId = null, bool $mail = true): self
    {
        $lines = array_values($lines);

        $notice = new self(
            kind: self::KIND,
            title: 'Info lomba binaan berubah: '.$competition->name,
            body: implode(' ', [self::INTRO, ...$lines]),
            routeName: $registrationId !== null ? 'guru.binaan.show' : 'guru.beranda',
            routeParameters: $registrationId !== null ? ['registration' => $registrationId] : [],
            actionLabel: 'Lihat binaan',
            extra: ['competition_id' => (string) $competition->getKey(), 'registration_id' => $registrationId],
        );

        $notice->lines = $lines;
        $notice->mail = $mail;

        return $notice;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->mail ? parent::via($notifiable) : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new PlainMailMessage)
            ->subject($this->title)
            ->greeting(MailGreeting::staff($notifiable))
            ->line(self::INTRO);

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        $url = $this->url();

        return $url !== null ? $message->action($this->actionLabel, $url) : $message;
    }
}
