<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Enums\PublicationStatus;
use App\Enums\RoleName;
use App\Models\Publication;
use App\Models\User;
use App\Notifications\Publications\AchievementPublished;
use App\Notifications\Publications\PublicationDue;
use App\Policies\PublicationPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Humas auto-publish (B3, backlog #12): a `terjadwal` publication whose scheduled_at has arrived becomes
 * `dipublikasikan` (published_at = the scheduled time) without a user actor. Posting on social media
 * stays manual, so every active Humas account gets PublicationDue ("unggah sekarang" + channels) and the
 * student gets AchievementPublished, both queued after commit.
 *
 * Re-checked under lockForUpdate(): still terjadwal, due, at least one channel, and still eligible
 * (verified achievement + publish_consent, PublicationPolicy::eligible) — otherwise skipped (null) and it
 * stays on Humas › Jadwal as "lewat jadwal". Audited `publication.published` with by = scheduler.
 * No file is involved: STH makes no flyer (M3-E), and a flyer file from before that stays on the private disk.
 */
final class AutoPublishPublication
{
    /** Due, eligible rows (terjadwal, scheduled_at ≤ $now, verified + consent), oldest first. @return Builder<Publication> */
    public static function due(CarbonImmutable $now): Builder
    {
        return Publication::query()
            ->where('status', PublicationStatus::Terjadwal)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $now)
            ->whereHas('achievement', fn (Builder $a) => $a
                ->whereNotNull('verified_at')
                ->whereHas('student', fn (Builder $s) => $s->where('publish_consent', true)))
            ->orderBy('scheduled_at')
            ->orderBy('id');
    }

    /** @return Publication|null the published row, null when skipped */
    public function handle(Publication|string $publication, ?CarbonImmutable $now = null): ?Publication
    {
        $id = $publication instanceof Publication ? (string) $publication->getKey() : $publication;
        $now ??= CarbonImmutable::now();

        $published = DB::transaction(function () use ($id, $now): ?Publication {
            /** @var Publication|null $locked */
            $locked = Publication::query()->whereKey($id)->lockForUpdate()->first();

            if (
                $locked === null
                || $locked->status !== PublicationStatus::Terjadwal
                || $locked->scheduled_at === null
                || $locked->scheduled_at->greaterThan($now)
                || ! PublicationPolicy::eligible($locked)
            ) {
                return null;
            }

            try {
                $channels = PublicationInput::channels((array) $locked->channels);
            } catch (ValidationException) {
                return null; // no channel stored: Humas has to finish it by hand
            }

            $locked->forceFill([
                'channels' => $channels,
                'status' => PublicationStatus::Dipublikasikan,
                'published_at' => $locked->scheduled_at,
            ])->save();

            activity('publication')
                ->performedOn($locked)
                ->event('publication.published')
                ->withProperties([
                    'achievement_id' => $locked->achievement_id,
                    'from' => PublicationStatus::Terjadwal->value,
                    'channels' => $channels,
                    'scheduled_at' => $locked->scheduled_at->toIso8601String(),
                    'by' => 'scheduler',
                ])
                ->log('publication.published');

            return $locked;
        });

        if ($published !== null) {
            $this->notify($published);
        }

        return $published;
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

    private function notify(Publication $publication): void
    {
        $publication->loadMissing(array_map(fn (string $r): string => 'achievement.'.$r, AchievementFacts::RELATIONS));

        $humas = self::humasRecipients();

        if ($humas->isNotEmpty()) {
            Notification::send($humas, PublicationDue::for($publication));
        }

        $student = $publication->achievement?->student?->user;

        if ($student !== null && $student->canLogIn()) {
            $student->notify(AchievementPublished::for($publication));
        }
    }
}
