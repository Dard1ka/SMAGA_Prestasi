<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Akun › Keamanan "Perangkat yang masuk" (B1): the user's own rows of the database session table. Session ids
 * never leave the server: each row is identified by an HMAC key (`key()`), which RevokeOtherSession matches.
 * Returns [] when sessions are not stored in the database (then the list cannot be shown).
 */
final class ListUserSessions
{
    public static function available(): bool
    {
        return config('session.driver') === 'database';
    }

    public static function key(string $sessionId): string
    {
        return hash_hmac('sha256', 'sth-session|'.$sessionId, (string) config('app.key'));
    }

    /**
     * The current session is always in the list. Right after its id was renewed (password change: the old row is
     * deleted, the new one is written when the request ends) it has no row yet; it is then described from the
     * request itself (`$currentUserAgent`, `$currentIp`), so "Perangkat ini" does not vanish until a reload (Q2-07).
     *
     * @return list<array{key: string, current: bool, device: string, ip: ?string, lastActive: CarbonImmutable}>
     */
    public function handle(User $user, ?string $currentSessionId, ?string $currentUserAgent = null, ?string $currentIp = null): array
    {
        if (! self::available()) {
            return [];
        }

        $sessions = DB::connection(config('session.connection'))
            ->table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier())
            ->orderByDesc('last_activity')
            ->limit(50)
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $row): array => [
                'key' => self::key((string) $row->id),
                'current' => $currentSessionId !== null && hash_equals((string) $row->id, $currentSessionId),
                'device' => self::device((string) $row->user_agent),
                'ip' => $row->ip_address !== null ? (string) $row->ip_address : null,
                'lastActive' => CarbonImmutable::createFromTimestamp((int) $row->last_activity, config('app.timezone')),
            ]);

        if ($currentSessionId !== null && ! $sessions->contains('current', true)) {
            $sessions->push([
                'key' => self::key($currentSessionId),
                'current' => true,
                'device' => self::device((string) $currentUserAgent),
                'ip' => $currentIp,
                'lastActive' => CarbonImmutable::now(config('app.timezone')),
            ]);
        }

        return $sessions
            ->sortByDesc(fn (array $s): int => $s['current'] ? PHP_INT_MAX : $s['lastActive']->getTimestamp())
            ->values()
            ->all();
    }

    /** "Chrome di Windows", "Safari di iPhone", … (never the raw user agent). */
    public static function device(string $userAgent): string
    {
        $ua = mb_strtolower($userAgent);

        $browser = match (true) {
            str_contains($ua, 'edg/') => 'Edge',
            str_contains($ua, 'opr/') || str_contains($ua, 'opera') => 'Opera',
            str_contains($ua, 'samsungbrowser') => 'Samsung Internet',
            str_contains($ua, 'firefox') || str_contains($ua, 'fxios') => 'Firefox',
            str_contains($ua, 'chrome') || str_contains($ua, 'crios') => 'Chrome',
            str_contains($ua, 'safari') => 'Safari',
            default => 'Peramban lain',
        };

        $os = match (true) {
            str_contains($ua, 'iphone') => 'iPhone',
            str_contains($ua, 'ipad') => 'iPad',
            str_contains($ua, 'android') => 'Android',
            str_contains($ua, 'windows') => 'Windows',
            str_contains($ua, 'mac os') || str_contains($ua, 'macintosh') => 'macOS',
            str_contains($ua, 'cros') => 'ChromeOS',
            str_contains($ua, 'linux') => 'Linux',
            default => null,
        };

        return $os !== null ? $browser.' di '.$os : $browser;
    }
}
