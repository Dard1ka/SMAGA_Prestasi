<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Accounts\RevokeUserSessions;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Models\PrincipalReplacement;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Admin\PrincipalReplacementNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Carries out a due "Ganti Kepala Sekolah" request (called by the scheduler, or at once when there was no
 * Kepala Sekolah). Idempotent: a request that is not pending or not due yet is ignored.
 *
 * - every current holder loses `kepala_sekolah` and their sessions; a holder left without any role is
 *   disabled (they can no longer log in);
 * - the new account gets `kepala_sekolah`; the signer name/rank/NIP settings are taken from its staff
 *   profile, and the old signature image is detached (it belongs to the previous Kepala Sekolah: the
 *   Admin uploads the new one before TU can generate letters again);
 * - a target that is no longer eligible (disabled, now Admin, …) cancels the request instead.
 * Audited (`principal.applied` / `principal.cancelled`); both accounts and every Admin are notified.
 */
final class ApplyPrincipalReplacement
{
    public function __construct(
        private readonly AdminRecipients $recipients,
        private readonly RevokeUserSessions $revokeSessions,
    ) {}

    public function handle(PrincipalReplacement $replacement, ?User $actor = null): bool
    {
        $result = DB::transaction(function () use ($replacement, $actor): ?array {
            /** @var PrincipalReplacement|null $locked */
            $locked = PrincipalReplacement::query()->whereKey($replacement->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->isPending() || $locked->effective_at->isFuture()) {
                return null;
            }

            /** @var User|null $target */
            $target = User::query()->whereKey($locked->to_user_id)->lockForUpdate()->first();

            try {
                if ($target === null) {
                    throw ValidationException::withMessages(['user' => 'Akun tujuan tidak ditemukan.']);
                }

                RequestPrincipalReplacement::assertEligible($target);
            } catch (ValidationException $e) {
                $locked->forceFill(['cancelled_at' => now(), 'cancel_reason' => 'Otomatis: '.collect($e->errors())->flatten()->first()])->save();

                activity('account')->performedOn($locked)->event('principal.cancelled')
                    ->withProperties(['automatic' => true, 'reason' => $locked->cancel_reason])->log('principal.cancelled');

                return ['cancelled' => true, 'replacement' => $locked, 'target' => $target, 'previous' => []];
            }

            $previous = User::query()->role(RoleName::KepalaSekolah->value)->lockForUpdate()->get();

            foreach ($previous as $old) {
                $old->removeRole(RoleName::KepalaSekolah->value);

                if ($old->getRoleNames()->isEmpty() && $old->status?->canLogIn()) {
                    $old->forceFill(['status' => UserStatus::Disabled])->save();
                }
            }

            $target->assignRole(RoleName::KepalaSekolah->value);
            $profile = $target->staffProfile()->first();

            Setting::put(SettingKey::SignerName, (string) $target->name);
            Setting::put(SettingKey::SignerRank, (string) ($profile?->rank ?? ''));
            Setting::put(SettingKey::SignerNip, (string) ($profile?->nip ?? ''));
            $previousSignature = Setting::get(SettingKey::SignatureFileId);
            Setting::put(SettingKey::SignatureFileId, null);

            $locked->forceFill(['applied_at' => now()])->save();

            $log = activity('account')->performedOn($locked)->event('principal.applied')->withProperties([
                'from_user_ids' => $previous->modelKeys(),
                'to_user_id' => $target->getKey(),
                'detached_signature_file_id' => $previousSignature,
            ]);

            if ($actor !== null) {
                $log->causedBy($actor);
            }

            $log->log('principal.applied');

            return ['cancelled' => false, 'replacement' => $locked, 'target' => $target, 'previous' => $previous->all()];
        });

        if ($result === null) {
            return false;
        }

        foreach ($result['previous'] as $old) {
            $this->revokeSessions->handle($old);
        }

        $recipients = $this->recipients->admins();

        foreach ([...$result['previous'], $result['target']] as $user) {
            if ($user !== null) {
                $recipients->push($user);
            }
        }

        $from = $result['previous'][0] ?? null;

        Notification::send(
            $recipients->unique(fn (User $u) => $u->getKey()),
            new PrincipalReplacementNotice(
                $result['cancelled'] ? PrincipalReplacementNotice::CANCELLED : PrincipalReplacementNotice::APPLIED,
                (string) $from?->name,
                (string) $result['target']?->name,
                now()->translatedFormat('j F Y H:i'),
            ),
        );

        return ! $result['cancelled'];
    }

    /** Scheduler entry point: apply every due request. Returns how many were applied. */
    public function applyDue(): int
    {
        $applied = 0;

        PrincipalReplacement::query()->due()->orderBy('effective_at')->get()
            ->each(function (PrincipalReplacement $replacement) use (&$applied): void {
                $applied += (int) $this->handle($replacement);
            });

        return $applied;
    }
}
