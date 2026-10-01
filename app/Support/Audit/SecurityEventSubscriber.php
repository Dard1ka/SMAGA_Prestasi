<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\OtpCode;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Feeds the `security` log (SECURITY §3l) from places that already exist, without touching them:
 * - auth events: Failed (login failure), Lockout (blocked attempt), Login (success);
 * - OTP abuse: an OtpCode whose `attempts` reaches 3 (`security.otp_failed`) or the maximum (`security.otp_exhausted`);
 * - audited Admin/account actions: every activity row of the account/auth/security/import/user logs and the
 *   events listed in AUDITED_EVENT_PREFIXES (resets, archive/disable, role changes, Kepsek replacement, consent,
 *   signature, settings, …) → `security.audit.<event>` with ids only (never the properties: reasons/notes);
 * - forward(): any message logged elsewhere whose text starts with `security.` (W1-C's lockout, OTP test mode,
 *   ambiguous identifier, initial password on an active account, …) is copied to the `security` channel.
 * Registered in AppServiceProvider::boot().
 */
final class SecurityEventSubscriber
{
    /** activity_log.log_name values that are always security relevant. */
    public const AUDITED_LOGS = ['account', 'auth', 'security', 'import', 'user'];

    /** activity_log.event prefixes that are security relevant in any log (B5: role.change_*, archive date, …). */
    public const AUDITED_EVENT_PREFIXES = [
        'account.', 'principal.', 'student.consent', 'student.import', 'student.archive', 'signature.', 'settings.',
        'two_factor.', 'archive.', 'role.', 'role_change.', 'weight.published', 'letter_template.', 'security.',
    ];

    /** …and every export (SECURITY §3l "export massal"): events ending in `.exported`. */
    public const AUDITED_EVENT_SUFFIXES = ['.exported'];

    public const OTP_ALERT_ATTEMPTS = 3;

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Failed::class, [self::class, 'loginFailed']);
        $events->listen(Lockout::class, [self::class, 'lockout']);
        $events->listen(Login::class, [self::class, 'login']);
        $events->listen(MessageLogged::class, [self::class, 'forward']);
        $events->listen('eloquent.created: '.Activity::class, [self::class, 'activityCreated']);
        $events->listen('eloquent.updated: '.OtpCode::class, [self::class, 'otpUpdated']);
    }

    public function loginFailed(Failed $event): void
    {
        $field = (string) config('fortify.username', 'email');
        $identifier = $event->credentials[$field] ?? $event->credentials['email'] ?? null;

        SecurityLog::warning('security.login_failed', [
            'guard' => $event->guard,
            'identifier' => self::identifier($identifier),
            'user_id' => $event->user?->getAuthIdentifier(),
        ]);
    }

    public function lockout(Lockout $event): void
    {
        $field = (string) config('fortify.username', 'email');

        SecurityLog::warning('security.login_locked', [
            'identifier' => self::identifier($event->request->input($field)),
        ]);
    }

    public function login(Login $event): void
    {
        SecurityLog::info('security.login', [
            'guard' => $event->guard,
            'user_id' => $event->user->getAuthIdentifier(),
        ]);
    }

    public function forward(MessageLogged $event): void
    {
        if (SecurityLog::isWriting() || ! str_starts_with($event->message, 'security.')) {
            return;
        }

        SecurityLog::write($event->level, $event->message, $event->context);
    }

    public function activityCreated(Activity $activity): void
    {
        $event = (string) ($activity->event ?? '');
        $logName = (string) ($activity->log_name ?? '');

        if (! in_array($logName, self::AUDITED_LOGS, true) && ! self::isAuditedEvent($event)) {
            return;
        }

        $name = str_contains($event, '.') ? $event : trim($logName.'.'.$event, '.');
        $context = [
            'activity_id' => $activity->getKey(),
            'log' => $logName,
            'causer_id' => $activity->causer_id,
            'subject_type' => $activity->subject_type !== null ? class_basename((string) $activity->subject_type) : null,
            'subject_id' => $activity->subject_id,
        ];

        // Only what was committed: a rolled-back Action leaves no security line either.
        $activity->getConnection()->afterCommit(fn () => SecurityLog::info('security.audit.'.$name, $context));
    }

    public function otpUpdated(OtpCode $otp): void
    {
        if (! $otp->wasChanged('attempts')) {
            return;
        }

        $attempts = (int) $otp->attempts;
        $max = (int) config('fortify.sth.otp.max_attempts', OtpCode::MAX_ATTEMPTS);

        if ($attempts < self::OTP_ALERT_ATTEMPTS) {
            return;
        }

        SecurityLog::warning($attempts >= $max ? 'security.otp_exhausted' : 'security.otp_failed', [
            'user_id' => $otp->user_id,
            'purpose' => $otp->purpose,
            'attempts' => $attempts,
        ]);
    }

    public static function isAuditedEvent(string $event): bool
    {
        return $event !== '' && (Str::startsWith($event, self::AUDITED_EVENT_PREFIXES) || Str::endsWith($event, self::AUDITED_EVENT_SUFFIXES));
    }

    /**
     * What a failed login may record of the typed identifier: NIS/NISN (digits) and e-mail addresses as typed;
     * anything else (staff usernames, or a password typed into the wrong field) only as a short keyed fingerprint,
     * so a password (e.g. the initial password, CLAUDE.md rule 3) never reaches a log file.
     */
    public static function identifier(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^\d{4,20}$/', $value) === 1) {
            return $value;
        }

        if (mb_strlen($value) <= 100 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return mb_strtolower($value);
        }

        return 'fp:'.substr(hash_hmac('sha256', mb_strtolower($value), 'sth-security-log|'.config('app.key')), 0, 12);
    }
}
