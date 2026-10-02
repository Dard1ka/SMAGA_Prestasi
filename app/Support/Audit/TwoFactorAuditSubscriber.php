<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;

/**
 * Audit rows (activity log `auth`) for every TOTP change, whichever way it happens: the setup screen
 * (App\Livewire\Auth\TwoFactorSetup) or Fortify's JSON routes, which call the same Fortify actions and fire these
 * events. Ids only, never the secret or the recovery codes. SecurityEventSubscriber copies the rows to the
 * `security` log as `security.audit.two_factor.*`. Registered in FortifyServiceProvider::boot().
 *
 * `two_factor.enabled` = a new secret was stored (also each time the setup screen replaces an unconfirmed one);
 * `two_factor.recovery_codes_regenerated` = the codes were replaced on request (not the first set from enabling).
 * An Admin reset removes TOTP without these events: see `account.staff_reset` / `account.reset`.
 */
final class TwoFactorAuditSubscriber
{
    /** Fortify event class => activity_log.event */
    public const EVENTS = [
        TwoFactorAuthenticationEnabled::class => 'two_factor.enabled',
        TwoFactorAuthenticationConfirmed::class => 'two_factor.confirmed',
        TwoFactorAuthenticationDisabled::class => 'two_factor.disabled',
        RecoveryCodesGenerated::class => 'two_factor.recovery_codes_regenerated',
    ];

    /** Rows per user and event within a minute. */
    public const MAX_ROWS_PER_MINUTE = 10;

    public function subscribe(Dispatcher $events): void
    {
        foreach (array_keys(self::EVENTS) as $event) {
            $events->listen($event, [self::class, 'record']);
        }
    }

    public function record(object $event): void
    {
        $name = self::EVENTS[$event::class] ?? null;
        $user = $event->user ?? null;

        if ($name === null || ! $user instanceof User) {
            return;
        }

        // Fortify's JSON routes have no throttle and the audit log is append-only: without a cap a signed-in staff
        // account could grow it without limit by re-posting. The first rows of each kind are always written.
        // Fortify's two-factor actions always act on the signed-in user, so subject and causer are the same.
        RateLimiter::attempt(
            'audit:'.$name.':'.$user->getKey(),
            self::MAX_ROWS_PER_MINUTE,
            fn () => activity('auth')->performedOn($user)->causedBy($user)->event($name)->log($name),
        );
    }
}
