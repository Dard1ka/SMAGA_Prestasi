<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Models\Competition;
use App\Models\Registration;
use App\Notifications\Registrations\InvitationsExpired;
use App\Notifications\Registrations\RegistrationAwaitingPrincipal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/**
 * Invitation expiry (B3, backlog #9): once the lomba's registration_closes_at has passed, open team
 * invitations can no longer be answered (RegistrationPolicy::teamWindowOpen, QA10), so the scheduler declines them
 * (`declined_at` = now; audited `registration.invitations_expired`, by = scheduler). Then the D-36 rule
 * applies as for a manual decline: all remaining members confirmed and ≥ 2 people → `menunggu_kepsek`
 * (Kepala Sekolah notified); otherwise the team is invalid and stays `menunggu_konfirmasi`.
 * The ketua always hears what happened (InvitationsExpired, `ready` true/false).
 * System transition without a user actor; idempotent (re-checked under lockForUpdate()).
 */
final class ExpirePendingInvitations
{
    public function __construct(private readonly ConfirmMembership $confirm) {}

    /** Team registrations still waiting for answers whose lomba closed registration before $now. @return Builder<Registration> */
    public static function due(CarbonImmutable $now): Builder
    {
        return Registration::query()
            ->where('status', RegistrationStatus::MenungguKonfirmasi)
            ->where('type', RegistrationType::Tim)
            ->whereHas('competition', fn (Builder $c) => $c->withTrashed()
                ->whereNotNull('registration_closes_at')
                ->where('registration_closes_at', '<', $now))
            ->whereHas('members', fn (Builder $m) => $m->whereNull('confirmed_at')->whereNull('declined_at'))
            ->orderBy('id');
    }

    /**
     * Ids of the member rows this job declined for the registration (audit `registration.invitations_expired`), so a
     * screen tells an invitation nobody answered from a real decline (QA10: both carry `declined_at`, D-50).
     *
     * @return list<string>
     */
    public static function expiredMemberIds(Registration $registration): array
    {
        return Activity::query()
            ->where('event', 'registration.invitations_expired')
            ->where('subject_type', $registration->getMorphClass())
            ->where('subject_id', $registration->getKey())
            ->get(['properties'])
            ->flatMap(fn (Activity $row): array => (array) collect($row->properties)->get('member_ids', []))
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array{expired: int, ready: bool, team: int}|null null when nothing was due */
    public function handle(Registration|string $registration, ?CarbonImmutable $now = null): ?array
    {
        $id = $registration instanceof Registration ? (string) $registration->getKey() : $registration;
        $now ??= CarbonImmutable::now();

        $result = DB::transaction(function () use ($id, $now): ?array {
            /** @var Registration|null $locked */
            $locked = Registration::query()->whereKey($id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== RegistrationStatus::MenungguKonfirmasi || ! $locked->isTeam()) {
                return null;
            }

            $closesAt = Competition::withTrashed()->whereKey($locked->competition_id)->value('registration_closes_at');

            if ($closesAt === null || CarbonImmutable::parse($closesAt)->greaterThanOrEqualTo($now)) {
                return null; // still open: invitees may answer
            }

            $pending = $locked->members()->whereNull('confirmed_at')->whereNull('declined_at')->lockForUpdate()->get();

            if ($pending->isEmpty()) {
                return null;
            }

            foreach ($pending as $member) {
                $member->forceFill(['declined_at' => $now])->save();
            }

            $ready = $this->confirm->advanceIfTeamReady($locked);
            $team = $locked->members()->whereNull('declined_at')->count();

            activity('registration')
                ->performedOn($locked)
                ->event('registration.invitations_expired')
                ->withProperties([
                    'member_ids' => $pending->modelKeys(),
                    'expired' => $pending->count(),
                    'team' => $team,
                    'status' => $locked->status->value,
                    'by' => 'scheduler',
                ])
                ->log('registration.invitations_expired');

            return ['registration' => $locked, 'expired' => $pending->count(), 'ready' => $ready, 'team' => $team];
        });

        if ($result === null) {
            return null;
        }

        /** @var Registration $locked */
        $locked = $result['registration'];
        $this->notify($locked, $result['expired'], $result['ready'], $result['team']);

        return ['expired' => $result['expired'], 'ready' => $result['ready'], 'team' => $result['team']];
    }

    private function notify(Registration $registration, int $expired, bool $ready, int $team): void
    {
        if ($ready) {
            Notification::send(CreateRegistration::principals(), new RegistrationAwaitingPrincipal($registration));
        }

        $leader = $registration->loadMissing('leader.user')->leader?->user;

        if ($leader !== null && $leader->canLogIn()) {
            $leader->notify(new InvitationsExpired($registration, $expired, $ready, $team));
        }
    }
}
