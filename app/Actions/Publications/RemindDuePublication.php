<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Enums\PublicationStatus;
use App\Enums\RoleName;
use App\Models\Publication;
use App\Models\User;
use App\Notifications\Publications\PublicationDue;
use App\Policies\PublicationPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The schedule of a publication is a reminder for Humas, nothing more (lead, 2026-10-10; D-122, which replaces the
 * auto-publish half of D-47). When `scheduled_at` of a `terjadwal` publication arrives, every active Humas account
 * gets PublicationDue ("Waktunya upload publikasi" + channels) once, and `reminded_at` is stamped so the next run
 * skips the row. No user actor (the scheduler calls it).
 *
 * Nothing else changes: status stays `terjadwal` (the screens show it as "Lewat jadwal"), `published_at` stays empty
 * and the student is NOT told. STH posts nothing to a channel, so only Humas knows when the publication is really
 * out: "Tandai dipublikasikan" (PublishPublication) is the one place that sets `dipublikasikan` and notifies the
 * student. "Jadwalkan ulang" (SchedulePublication) empties `reminded_at`, so a new time gets its own reminder.
 *
 * Re-checked under lockForUpdate(): still terjadwal, due, not reminded yet and still eligible (verified achievement
 * + a student who may be named, PublicationPolicy::eligible; QA10: a disabled or deleted student is skipped like one
 * without consent, so nobody is mailed the name); otherwise skipped (null). A row without a stored channel is
 * reminded too: the reminder needs none, and PublishPublication still asks for one. When no Humas account can log
 * in at that moment the row is stamped all the same (exactly once per schedule); it stays visible as "Lewat jadwal"
 * on Humas › Jadwal and in the sidebar count. Audited `publication.reminded` with by = scheduler.
 * No file is involved: STH makes no flyer (M3-E), and a flyer file from before that stays on the private disk.
 */
final class RemindDuePublication
{
    /**
     * Rows whose reminder is due: terjadwal, scheduled_at ≤ $now, not reminded yet, verified + named student
     * (QA10), oldest first.
     *
     * @return Builder<Publication>
     */
    public static function due(CarbonImmutable $now): Builder
    {
        return Publication::query()
            ->where('status', PublicationStatus::Terjadwal)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $now)
            ->whereNull('reminded_at')
            ->whereHas('achievement', fn (Builder $a) => $a
                ->whereNotNull('verified_at')
                ->whereHas('student', fn (Builder $s) => NamedStudent::constrain($s)))
            ->orderBy('scheduled_at')
            ->orderBy('id');
    }

    /** @return Publication|null the reminded row, null when skipped */
    public function handle(Publication|string $publication, ?CarbonImmutable $now = null): ?Publication
    {
        $id = $publication instanceof Publication ? (string) $publication->getKey() : $publication;
        $now ??= CarbonImmutable::now();

        $reminded = DB::transaction(function () use ($id, $now): ?Publication {
            /** @var Publication|null $locked */
            $locked = Publication::query()->whereKey($id)->lockForUpdate()->first();

            if (
                $locked === null
                || $locked->status !== PublicationStatus::Terjadwal
                || $locked->scheduled_at === null
                || $locked->scheduled_at->greaterThan($now)
                || $locked->reminded_at !== null
                || ! PublicationPolicy::eligible($locked)
            ) {
                return null;
            }

            // Only the mark: status, published_at, channels and caption stay exactly as Humas left them.
            $locked->forceFill(['reminded_at' => $now])->save();

            activity('publication')
                ->performedOn($locked)
                ->event('publication.reminded')
                ->withProperties([
                    'achievement_id' => $locked->achievement_id,
                    'scheduled_at' => $locked->scheduled_at->toIso8601String(),
                    'channels' => array_values((array) $locked->channels),
                    'by' => 'scheduler',
                ])
                ->log('publication.reminded');

            return $locked;
        });

        if ($reminded !== null) {
            $this->notify($reminded);
        }

        return $reminded;
    }

    /**
     * Active Humas accounts (role humas, can log in).
     *
     * @return Collection<int, User>
     */
    public static function humasRecipients(): Collection
    {
        return User::query()->canLogIn()->role(RoleName::Humas->value)->get();
    }

    /** Humas only. The student hears about the publication from PublishPublication, never from here. */
    private function notify(Publication $publication): void
    {
        $publication->loadMissing(array_map(fn (string $r): string => 'achievement.'.$r, AchievementFacts::RELATIONS));

        $humas = self::humasRecipients();

        if ($humas->isNotEmpty()) {
            Notification::send($humas, PublicationDue::for($publication));
        }
    }
}
