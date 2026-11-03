<?php

declare(strict_types=1);

namespace App\Notifications\Publications;

use App\Actions\Publications\EventWhen;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * To the guru pembimbing about a binaan's appreciation (QA5, D-110; client document, "Penjadwalan Apresiasi":
 * "Siswa dan guru pembimbing menerima notifikasi"): put on an event (SCHEDULED, one notice per guru and "Masukkan"
 * listing every binaan of that click, D-124), taken off it (RELEASED), or the
 * event deleted (CANCELLED, one notice per guru listing every binaan on it). The student gets AppreciationScheduled,
 * AppreciationReleased or AppreciationCancelled. Scalars only (the rows may be gone when this is sent): student
 * name + capaian, never NIS/NISN/birth date. Queued on the mail queue, sent after commit.
 */
final class BinaanAppreciationNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const SCHEDULED = 'appreciation.binaan_scheduled';

    public const RELEASED = 'appreciation.binaan_released';

    public const CANCELLED = 'appreciation.binaan_cancelled';

    /**
     * @param  list<string>  $binaan  "Nadia Putri Anindya (Juara II OPSI 2026)", one per appreciation row
     * @param  string|null  $registrationId  the binaan page to open; null = several registrations, the guru's list
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $eventName,
        public readonly string $eventDate,
        public readonly array $binaan,
        public readonly ?string $registrationId = null,
    ) {
        $this->onQueue((string) config('fortify.sth.mail_queue', 'mail'));
        $this->afterCommit();
    }

    /** @param Appreciation $appreciation with event and achievement.{student.user, competition, report} */
    public static function scheduled(Appreciation $appreciation): self
    {
        return self::make(self::SCHEDULED, $appreciation->event, [$appreciation]);
    }

    /**
     * One notice for every binaan of this guru that one "Masukkan" put on the event (D-124).
     *
     * @param  iterable<Appreciation>  $appreciations  this guru's new rows, achievement.{student.user, competition, report} loaded
     */
    public static function scheduledMany(AppreciationEvent $event, iterable $appreciations): self
    {
        return self::make(self::SCHEDULED, $event, $appreciations);
    }

    /** @param Appreciation $appreciation the released row, achievement.{student.user, competition, report} loaded */
    public static function released(AppreciationEvent $event, Appreciation $appreciation): self
    {
        return self::make(self::RELEASED, $event, [$appreciation]);
    }

    /** @param iterable<Appreciation> $appreciations this guru's binaan rows on the deleted event */
    public static function cancelled(AppreciationEvent $event, iterable $appreciations): self
    {
        return self::make(self::CANCELLED, $event, $appreciations);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function title(): string
    {
        return match ($this->kind) {
            self::RELEASED => 'Jadwal apresiasi binaan berubah',
            self::CANCELLED => 'Acara apresiasi binaan dibatalkan',
            default => 'Binaan masuk daftar apresiasi',
        };
    }

    public function body(): string
    {
        $when = $this->eventDate !== '' ? ' pada '.$this->eventDate : '';
        $first = $this->binaan[0] ?? 'Prestasi binaan';

        return match ($this->kind) {
            self::RELEASED => $first.' tidak jadi diapresiasi di '.$this->eventName.$when
                .'. Prestasi ini bisa dijadwalkan lagi di acara lain.',
            self::CANCELLED => 'Acara '.$this->eventName.$when.' dibatalkan. '.(count($this->binaan) === 1
                ? $first.' belum jadi diapresiasi di acara itu dan bisa dijadwalkan lagi di acara lain.'
                : count($this->binaan).' prestasi binaan belum jadi diapresiasi di acara itu: '.implode(', ', $this->binaan)
                    .'. Semuanya bisa dijadwalkan lagi di acara lain.'),
            // SCHEDULED. Several binaan in one click (a team, D-124) are one sentence with all their names.
            default => count($this->binaan) > 1
                ? count($this->binaan).' prestasi binaan akan diapresiasi di '.$this->eventName.$when.': '.implode(', ', $this->binaan).'.'
                : $first.' akan diapresiasi di '.$this->eventName.$when.'.',
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new PlainMailMessage)
            ->subject($this->title())
            ->greeting(MailGreeting::staff($notifiable))
            ->line($this->body())
            ->action('Lihat binaan', $this->url());
    }

    /** @return array<string, string|null> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
            'registration_id' => $this->registrationId,
        ];
    }

    public function url(): string
    {
        if ($this->registrationId !== null && Route::has('guru.binaan.show')) {
            return route('guru.binaan.show', ['registration' => $this->registrationId]);
        }

        return Route::has('guru.beranda') ? route('guru.beranda') : url('/beranda');
    }

    /** @param iterable<Appreciation> $appreciations */
    private static function make(string $kind, ?AppreciationEvent $event, iterable $appreciations): self
    {
        $binaan = [];
        $registrations = [];

        foreach ($appreciations as $appreciation) {
            $achievement = $appreciation->achievement;
            $binaan[] = (string) ($achievement?->student?->user?->name ?? 'Siswa').' ('.trim(
                (string) ($achievement?->result?->label() ?? 'Prestasi').' '.(string) ($achievement?->competition?->name ?? 'lomba')
            ).')';
            $registrations[(string) ($achievement?->report?->registration_id ?? '')] = true;
        }

        $registrationIds = array_keys(array_filter($registrations, fn (bool $v, string $id): bool => $id !== '', ARRAY_FILTER_USE_BOTH));

        return new self(
            kind: $kind,
            eventName: (string) ($event?->name ?? 'acara apresiasi'),
            // Day and start time in one phrase (D-123); the property keeps its name for jobs already queued.
            eventDate: EventWhen::sentence($event),
            binaan: $binaan,
            registrationId: count($registrationIds) === 1 ? (string) $registrationIds[0] : null,
        );
    }
}
