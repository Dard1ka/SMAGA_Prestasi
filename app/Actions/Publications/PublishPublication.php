<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use App\Notifications\Publications\AchievementPublished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Humas marks a publication as published ("Tandai dipublikasikan", from `siap` or `terjadwal`):
 * status → dipublikasikan, published_at = now. Caption/channels from the form may be saved in the same
 * step (at least one channel is required). Audited; the student is notified after commit.
 */
final class PublishPublication
{
    /**
     * @param  array<int, mixed>|null  $channels  null = keep the stored channels
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Publication $publication, ?string $caption = null, ?array $channels = null): Publication
    {
        Gate::forUser($actor)->authorize('publish', $publication);

        $caption = $caption !== null ? PublicationInput::caption($caption, required: false) : null;
        $channels = $channels !== null ? PublicationInput::channels($channels) : null;

        $locked = DB::transaction(function () use ($actor, $publication, $caption, $channels): Publication {
            /** @var Publication $locked */
            $locked = Publication::query()->whereKey($publication->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [PublicationStatus::Siap, PublicationStatus::Terjadwal], true)) {
                throw ValidationException::withMessages(['publication' => 'Publikasi ini sudah dipublikasikan.']);
            }

            $finalChannels = $channels ?? PublicationInput::channels((array) $locked->channels);
            $from = $locked->status;

            $locked->fill([
                'channels' => $finalChannels,
                'caption' => $caption ?? $locked->caption,
            ]);
            $locked->forceFill([
                'status' => PublicationStatus::Dipublikasikan,
                'published_at' => now(),
                'created_by' => $locked->created_by ?? $actor->getKey(),
            ])->save();

            activity('publication')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('publication.published')
                ->withProperties([
                    'achievement_id' => $locked->achievement_id,
                    'from' => $from?->value,
                    'channels' => $finalChannels,
                    'scheduled_at' => $locked->scheduled_at?->toIso8601String(),
                ])
                ->log('publication.published');

            return $locked;
        });

        $publication->setRawAttributes($locked->getAttributes(), true);

        $this->notify($publication);

        return $publication;
    }

    /** Is the student told when this publication is published? Not an alumnus or a disabled account (QA8: the toast asks). */
    public static function notifiesStudent(Publication $publication): bool
    {
        $publication->loadMissing('achievement.student.user');

        return $publication->achievement?->student?->user?->canLogIn() === true;
    }

    private function notify(Publication $publication): void
    {
        $publication->loadMissing('achievement.student.user', 'achievement.competition');

        if (self::notifiesStudent($publication)) {
            $publication->achievement->student->user->notify(AchievementPublished::for($publication));
        }
    }
}
