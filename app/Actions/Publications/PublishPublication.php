<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use App\Notifications\Publications\AchievementPublished;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Humas marks a publication as published ("Tandai dipublikasikan", from `siap` or `terjadwal`):
 * status → dipublikasikan, published_at = now. Caption/channels from the form may be saved in the same
 * step (at least one channel is required). Audited; the student is notified after commit.
 *
 * Since D-122 this is the ONLY place that sets `dipublikasikan` and the only sender of AchievementPublished: the
 * scheduler only reminds Humas (RemindDuePublication), because only Humas knows when the post is really out.
 *
 * D-160 (the lead, 2026-10-19: "pastikan ada notifikasi ke siswa dengan benar"): a team has one card per member, but
 * one post shows the whole team ("Data untuk flyer"). So the other cards of the same report that still wait and that
 * this Humas account may publish (teammates(): the student may be named) are published in the same step, with the same
 * caption and channels, each audited (`together_with` = the card that was clicked) and each student told. Before, the
 * teammates stayed in the queue and heard nothing. `$team` holds the cards published together by the last handle().
 */
final class PublishPublication
{
    /** @var list<Publication> the teammates' cards the last handle() published together with the clicked one */
    public array $team = [];

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
        $teamIds = self::teammates($actor, $publication)->modelKeys();
        $this->team = [];

        [$locked, $team] = DB::transaction(function () use ($actor, $publication, $caption, $channels, $teamIds): array {
            /** @var Publication $locked */
            $locked = Publication::query()->whereKey($publication->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [PublicationStatus::Siap, PublicationStatus::Terjadwal], true)) {
                throw ValidationException::withMessages(['publication' => 'Publikasi ini sudah dipublikasikan.']);
            }

            $finalChannels = $channels ?? PublicationInput::channels((array) $locked->channels);
            $finalCaption = $caption ?? $locked->caption;
            $this->publishRow($actor, $locked, $finalChannels, $finalCaption, null);

            $team = [];

            // Id order, like every batch of locks here; a teammate published meanwhile in another tab is left as it is.
            foreach (Publication::query()->whereKey($teamIds)->orderBy('id')->lockForUpdate()->get() as $mate) {
                if (in_array($mate->status, [PublicationStatus::Siap, PublicationStatus::Terjadwal], true)) {
                    $this->publishRow($actor, $mate, $finalChannels, $finalCaption, (string) $locked->getKey());
                    $team[] = $mate;
                }
            }

            return [$locked, $team];
        });

        $publication->setRawAttributes($locked->getAttributes(), true);
        $this->team = $team;

        foreach ([$publication, ...$team] as $published) {
            $this->notify($published);
        }

        return $publication;
    }

    /**
     * D-160: the other cards of the same team (one publication per member, same report) that still wait (`siap` or
     * `terjadwal`) and that the actor may publish (PublicationPolicy::publish: the student may be named).
     *
     * @return Collection<int, Publication>
     */
    public static function teammates(User $actor, Publication $publication): Collection
    {
        $publication->loadMissing('achievement');
        $reportId = $publication->achievement?->report_id;

        if ($reportId === null) {
            return new Collection;
        }

        $gate = Gate::forUser($actor);

        return Publication::query()
            ->whereKeyNot($publication->getKey())
            ->whereIn('status', [PublicationStatus::Siap, PublicationStatus::Terjadwal])
            ->whereHas('achievement', fn ($q) => $q->where('report_id', $reportId))
            ->with('achievement.student.user')
            ->orderBy('id')
            ->get()
            ->filter(fn (Publication $mate): bool => $gate->allows('publish', $mate))
            ->values();
    }

    /** Is the student told when this publication is published? Not an alumnus or a disabled account (QA8: the toast asks). */
    public static function notifiesStudent(Publication $publication): bool
    {
        $publication->loadMissing('achievement.student.user');

        return $publication->achievement?->student?->user?->canLogIn() === true;
    }

    /** @param list<string> $channels */
    private function publishRow(User $actor, Publication $row, array $channels, ?string $caption, ?string $togetherWith): void
    {
        $from = $row->status;

        $row->fill([
            'channels' => $channels,
            'caption' => $caption,
        ]);
        $row->forceFill([
            'status' => PublicationStatus::Dipublikasikan,
            'published_at' => now(),
            'created_by' => $row->created_by ?? $actor->getKey(),
        ])->save();

        activity('publication')
            ->performedOn($row)
            ->causedBy($actor)
            ->event('publication.published')
            ->withProperties([
                'achievement_id' => $row->achievement_id,
                'from' => $from?->value,
                'channels' => $channels,
                'scheduled_at' => $row->scheduled_at?->toIso8601String(),
                ...($togetherWith !== null ? ['together_with' => $togetherWith] : []),
            ])
            ->log('publication.published');
    }

    private function notify(Publication $publication): void
    {
        $publication->loadMissing('achievement.student.user', 'achievement.competition');

        if (self::notifiesStudent($publication)) {
            $publication->achievement->student->user->notify(AchievementPublished::for($publication));
        }
    }
}
