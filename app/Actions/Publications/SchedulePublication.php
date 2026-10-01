<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Humas schedules a publication (UI-MAP #22 "Jadwalkan"): caption + channels + date/time → `terjadwal`.
 * A `terjadwal` one can be re-scheduled; a published one cannot change. Audited; no notification
 * (the student hears about it when it is published).
 */
final class SchedulePublication
{
    /**
     * @param  array<int, mixed>  $channels  PublicationChannel values
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Publication $publication, string $caption, array $channels, DateTimeInterface $scheduledAt): Publication
    {
        Gate::forUser($actor)->authorize('schedule', $publication);

        $caption = PublicationInput::caption($caption, required: true);
        $channels = PublicationInput::channels($channels);
        $at = CarbonImmutable::instance($scheduledAt)->setTimezone((string) config('app.timezone'))->startOfMinute();

        if ($at->lessThan(CarbonImmutable::now()->startOfMinute())) {
            throw ValidationException::withMessages(['scheduled_at' => 'Pilih waktu yang belum lewat.']);
        }

        if ($at->greaterThan(CarbonImmutable::now()->addYear())) {
            throw ValidationException::withMessages(['scheduled_at' => 'Jadwal maksimal satu tahun dari sekarang.']);
        }

        $locked = DB::transaction(function () use ($actor, $publication, $caption, $channels, $at): Publication {
            /** @var Publication $locked */
            $locked = Publication::query()->whereKey($publication->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === PublicationStatus::Dipublikasikan) {
                throw ValidationException::withMessages(['publication' => 'Publikasi ini sudah dipublikasikan.']);
            }

            $from = $locked->status;
            $previousAt = $locked->scheduled_at;

            $locked->fill([
                'caption' => $caption,
                'channels' => $channels,
                'scheduled_at' => $at,
            ]);
            $locked->forceFill([
                'status' => PublicationStatus::Terjadwal,
                'created_by' => $locked->created_by ?? $actor->getKey(),
            ])->save();

            $event = $from === PublicationStatus::Terjadwal ? 'publication.rescheduled' : 'publication.scheduled';

            activity('publication')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event($event)
                ->withProperties([
                    'achievement_id' => $locked->achievement_id,
                    'from' => $from?->value,
                    'channels' => $channels,
                    'scheduled_at' => $at->toIso8601String(),
                    'previous_scheduled_at' => $previousAt?->toIso8601String(),
                ])
                ->log($event);

            return $locked;
        });

        $publication->setRawAttributes($locked->getAttributes(), true);

        return $publication;
    }
}
