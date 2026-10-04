<?php

declare(strict_types=1);

namespace App\Actions\Talent;

use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Models\TalentApplication;
use App\Models\User;
use App\Notifications\Talent\TalentReviewRequested;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * A student asks Kesiswaan to look at a rejected pilihan talenta again (Profil talenta, "Minta ditinjau ulang";
 * lead, 2026-10-04). Only the owner, only while the row is "tidak lolos", one open request per rejection, with
 * an optional short message (≤ 500 characters). The request never changes the status: Kesiswaan decides with
 * RestoreTalentApplication (which also closes the request). Every Kesiswaan account is notified (queued).
 * Validation keys: pilihan, pesan.
 */
final class RequestTalentReview
{
    /** @throws ValidationException */
    public function handle(User $actor, TalentApplication $application, ?string $message = null): TalentApplication
    {
        Gate::forUser($actor)->authorize('requestReview', $application);

        $message = TalentNote::clean($message, 'pesan', TalentNote::MAX_REVIEW_MESSAGE, 'Pesan');

        $locked = DB::transaction(function () use ($actor, $application, $message): TalentApplication {
            /** @var TalentApplication $locked */
            $locked = TalentApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== TalentStatus::TidakLolos) {
                throw ValidationException::withMessages(['pilihan' => 'Pilihan ini sudah dibuka kembali.']);
            }

            if ($locked->review_requested_at !== null) {
                throw ValidationException::withMessages(['pilihan' => 'Permintaanmu sudah terkirim. Tunggu kabar dari Kesiswaan.']);
            }

            $locked->forceFill(['review_requested_at' => now(), 'review_message' => $message])->save();

            activity('talent')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('talent.review_requested')
                // Only the fact, never the student's text: the audit log cannot be edited or deleted (D-63),
                // the message itself lives in talent_applications.review_message.
                ->withProperties(['has_message' => $message !== null && $message !== ''])
                ->log('talent.review_requested');

            return $locked;
        });

        $application->setRawAttributes($locked->getAttributes(), true);

        $recipients = User::query()
            ->canLogIn()
            ->whereHas('roles', fn (Builder $q) => $q->where('name', RoleName::Kesiswaan->value))
            ->get();

        if ($recipients->isNotEmpty()) {
            $student = $locked->student()->with(['user:id,name', 'classroom:id,name'])->first();

            Notification::send($recipients, TalentReviewRequested::for(
                $locked,
                (string) ($student?->user?->name ?? $actor->name),
                $student?->classroom?->name,
                MoveTalentStage::talentLabel($locked),
                $message,
            ));
        }

        return $locked;
    }
}
