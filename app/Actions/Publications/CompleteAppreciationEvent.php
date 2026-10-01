<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use App\Enums\AppreciationStatus;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Tandai selesai": after the event took place, every `dijadwalkan` appreciation of it becomes
 * `selesai`. Returns how many rows changed. Audited.
 */
final class CompleteAppreciationEvent
{
    /**
     * @throws ValidationException
     */
    public function handle(User $actor, AppreciationEvent $event): int
    {
        Gate::forUser($actor)->authorize('update', $event);

        return DB::transaction(function () use ($actor, $event): int {
            /** @var AppreciationEvent $locked */
            $locked = AppreciationEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->held_on->greaterThan(CarbonImmutable::today())) {
                throw ValidationException::withMessages(['event' => 'Acara ini belum berlangsung.']);
            }

            $pending = Appreciation::query()
                ->where('appreciation_event_id', $locked->getKey())
                ->where('status', AppreciationStatus::Dijadwalkan)
                ->lockForUpdate()
                ->get();

            if ($pending->isEmpty()) {
                throw ValidationException::withMessages(['event' => 'Tidak ada apresiasi yang perlu ditandai.']);
            }

            foreach ($pending as $appreciation) {
                $appreciation->forceFill(['status' => AppreciationStatus::Selesai])->save();
            }

            activity('appreciation')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('appreciation.event_completed')
                ->withProperties([
                    'held_on' => $locked->held_on->toDateString(),
                    'appreciation_ids' => $pending->modelKeys(),
                ])
                ->log('appreciation.event_completed');

            return $pending->count();
        });
    }
}
