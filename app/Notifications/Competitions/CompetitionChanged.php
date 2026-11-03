<?php

declare(strict_types=1);

namespace App\Notifications\Competitions;

use App\Models\Competition;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use App\Notifications\Verification\Notice;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the students of one running registration: Kesiswaan changed something they rely on in a published lomba
 * (lead, 2026-10-10, D-126). Who is told and about which facts: App\Actions\Competitions\CompetitionChange. One
 * notice per save, one sentence per changed fact with the value as it is now, so a later save corrects an earlier
 * one. The link opens the student's own registration (Lomba saya), which still answers after the lomba was closed.
 * The guru pembimbing gets BinaanCompetitionChanged. Scalars only; the sentences never print a web address
 * (SECURITY §3m-1).
 */
final class CompetitionChanged extends Notice
{
    public const KIND = 'competition.changed';

    public const INTRO = 'Kesiswaan mengubah data lomba ini.';

    /** @var list<string> one sentence per changed fact (CompetitionChange::line()) */
    public array $lines = [];

    /** false = bell only: the save reached more people than CompetitionChange::MAIL_LIMIT. */
    public bool $mail = true;

    /**
     * @param  string  $registrationId  the recipients' own registration of this lomba
     * @param  list<string>  $lines
     */
    public static function for(Competition $competition, string $registrationId, array $lines, bool $mail = true): self
    {
        $lines = array_values($lines);

        $notice = new self(
            kind: self::KIND,
            title: 'Info lomba berubah: '.$competition->name,
            body: implode(' ', [self::INTRO, ...$lines]),
            routeName: 'siswa.lomba-saya.show',
            routeParameters: ['registration' => $registrationId],
            actionLabel: 'Lihat lomba',
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

    /** Each sentence on its own line (the bell shows them as one paragraph). */
    public function toMail(object $notifiable): MailMessage
    {
        $message = (new PlainMailMessage)
            ->subject($this->title)
            ->greeting(MailGreeting::student($notifiable))
            ->line(self::INTRO);

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        $url = $this->url();

        return $url !== null ? $message->action($this->actionLabel, $url) : $message;
    }
}
