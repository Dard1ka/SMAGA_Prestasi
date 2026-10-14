<?php

declare(strict_types=1);

namespace App\Notifications\Verification;

use App\Domain\Points\PointFormatter;
use App\Enums\RegistrationType;
use App\Models\Achievement;
use App\Models\Registration;
use App\Models\Report;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;

/**
 * To the guru pembimbing when the verifier decides on a binaan's laporan hasil (QA5, lead 2026-10-07, D-110):
 * verified (capaian + points), revision asked (the note) or rejected (the reason). The members get their own
 * notices (ReportVerified, ReportRevisionRequested, ReportRejected); this one links to the guru's binaan page
 * and greets the guru formally ("Yth. <full name>,", like the other notices for staff). Names only, never NIS/NISN.
 */
final class BinaanReportDecided extends Notice
{
    /** @param Collection<int, Achievement> $achievements every member's row of one report (same capaian and points) */
    public static function verified(Report $report, Collection $achievements): self
    {
        $first = $achievements->first();
        $names = $achievements->map(fn (Achievement $a): string => (string) ($a->student?->user?->name ?? 'Siswa'))->values();
        $team = $names->count() > 1;
        $who = $team ? $names[0].' dan '.($names->count() - 1).' anggota timnya' : (string) ($names[0] ?? 'Siswa binaan');
        $points = (new PointFormatter)->format((string) ($first->points ?? '0'));

        return self::make(
            $report,
            (string) ($first?->competition?->name ?? 'lomba'),
            'report.binaan_verified',
            'Laporan binaan diverifikasi: ',
            $who.' meraih '.($first?->result->label() ?? 'prestasi').'. '.($team ? 'Setiap anggota mendapat ' : 'Poinnya ').$points.' poin.',
        );
    }

    public static function revision(Report $report, string $competitionName, string $note, string $decider): self
    {
        // FS1 QA: an individu registration has no ketua tim; its one student fixes the report.
        $team = Registration::query()->whereKey($report->registration_id)->value('type') === RegistrationType::Tim;

        return self::make($report, $competitionName, 'report.binaan_revision', 'Laporan binaan perlu revisi: ',
            'Catatan '.$decider.': '.self::sentence($note).($team ? ' Ketua tim memperbaiki laporan dari akunnya.' : ' Siswa memperbaiki laporan dari akunnya.'));
    }

    public static function rejected(Report $report, string $competitionName, string $note): self
    {
        return self::make($report, $competitionName, 'report.binaan_rejected', 'Laporan binaan ditolak: ',
            'Alasan: '.self::sentence($note));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new PlainMailMessage)
            ->subject($this->title)
            ->greeting(MailGreeting::staff($notifiable))
            ->line($this->body);

        $url = $this->url();

        return $url !== null ? $message->action($this->actionLabel, $url) : $message;
    }

    private static function make(Report $report, string $competitionName, string $kind, string $titlePrefix, string $body): self
    {
        return new self(
            kind: $kind,
            title: $titlePrefix.$competitionName,
            body: $body,
            routeName: 'guru.binaan.show',
            routeParameters: ['registration' => (string) $report->registration_id],
            actionLabel: 'Lihat binaan',
            extra: ['report_id' => (string) $report->getKey(), 'registration_id' => (string) $report->registration_id],
        );
    }

    /** The note as one sentence, so the text after it does not run on. */
    private static function sentence(string $note): string
    {
        $note = trim($note);

        return preg_match('/[.!?]$/u', $note) === 1 ? $note : $note.'.';
    }
}
