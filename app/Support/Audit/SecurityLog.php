<?php

declare(strict_types=1);

namespace App\Support\Audit;

use BackedEnum;
use Illuminate\Support\Facades\Log;
use Stringable;
use Throwable;

/**
 * Writes to the `security` log channel (config/logging.php: daily file, 90 days; SECURITY §3l).
 *
 *   SecurityLog::warning('security.login_failed', ['identifier' => '225226201']);
 *
 * - Event names start with `security.`; the context is scrubbed: keys that look like secrets (password, otp/code,
 *   token, secret, session, recovery codes) are dropped, long strings are cut. Never pass file contents.
 * - In an HTTP request the client IP and a shortened user agent are added.
 * - Anything else that logs a message starting with `security.` to the default channel (e.g. W1-C's
 *   `Log::warning('security.login_lockout', …)`) is copied here by SecurityEventSubscriber::forward().
 * - Logging never breaks the caller: write errors are swallowed.
 */
final class SecurityLog
{
    public const CHANNEL = 'security';

    /** Context keys never written (case-insensitive, also nested). */
    public const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'current_password', 'new_password', 'temp_password', 'temporary_password',
        'code', 'otp', 'code_hash', 'token', 'remember_token', 'api_token', 'secret', 'two_factor_secret',
        'two_factor_recovery_codes', 'recovery_codes', 'session', 'session_id', 'cookie', 'birth_date',
    ];

    private const MAX_STRING = 200;

    private static bool $writing = false;

    /** @param array<string, mixed> $context */
    public static function info(string $event, array $context = []): void
    {
        self::write('info', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public static function notice(string $event, array $context = []): void
    {
        self::write('notice', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $event, array $context = []): void
    {
        self::write('warning', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public static function critical(string $event, array $context = []): void
    {
        self::write('critical', $event, $context);
    }

    /** @param array<string, mixed> $context */
    public static function write(string $level, string $event, array $context = []): void
    {
        if (self::$writing) {
            return;
        }

        self::$writing = true;

        try {
            Log::channel(self::CHANNEL)->log($level, $event, self::scrub($context) + self::requestContext());
        } catch (Throwable) {
            // A full disk or an unwritable log file must not break a login or an Action.
        } finally {
            self::$writing = false;
        }
    }

    /** True while this class is writing (the forwarder ignores its own messages). */
    public static function isWriting(): bool
    {
        return self::$writing;
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    public static function scrub(array $context, int $depth = 0): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => $depth < 3 ? self::scrub($value, $depth + 1) : '[…]',
                is_string($value) => mb_strlen($value) > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING).'…' : $value,
                $value === null, is_bool($value), is_int($value), is_float($value) => $value,
                $value instanceof BackedEnum => $value->value,
                $value instanceof Stringable => mb_substr((string) $value, 0, self::MAX_STRING),
                default => get_debug_type($value),
            };
        }

        return $clean;
    }

    /** @return array<string, string> */
    private static function requestContext(): array
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return [];
        }

        $request = request();

        return array_filter([
            'ip' => (string) $request->ip(),
            'ua' => mb_substr((string) $request->userAgent(), 0, 120),
        ], fn (string $v): bool => $v !== '');
    }
}
