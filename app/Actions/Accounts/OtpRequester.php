<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * The browser that asked for a "Lupa kata sandi" code (QA10 review). A reset code belongs to that browser: its
 * session (kept on the server, never shown to the client) holds a random token, and the cache keeps per token,
 * account and purpose the ids of the codes issued to it. Only those codes can be checked from that browser, only its
 * own new code cancels its older one, and its wrong codes count against it alone (IssueOtpCode, VerifyOtpCode). So
 * whoever knows a student's NIS only spends the codes it asked for itself, never the owner's.
 */
final class OtpRequester
{
    public const SESSION_KEY = 'sth.otp_requester';

    /** Codes remembered per token, account and purpose (newest last). */
    private const KEEP = 20;

    /** Longer than the 24 h the limits look back (CleanupOtpCodes deletes the rows after 24 h). */
    private const TTL_SECONDS = 25 * 3600;

    public function __construct(private readonly Cache $cache) {}

    /** The token of the current browser session, made on first use. */
    public static function current(): string
    {
        $token = session()->get(self::SESSION_KEY);

        if (! is_string($token) || strlen($token) < 32) {
            $token = Str::random(40);
            session()->put(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /** @return list<string> ids of the codes issued to this requester for the account and purpose */
    public function codes(string $requester, User $user, string $purpose): array
    {
        $ids = $this->cache->get($this->key($requester, $user, $purpose), []);

        return is_array($ids) ? array_values(array_filter($ids, is_string(...))) : [];
    }

    public function remember(string $requester, User $user, string $purpose, string $codeId): void
    {
        $ids = [...$this->codes($requester, $user, $purpose), $codeId];

        $this->cache->put($this->key($requester, $user, $purpose), array_slice($ids, -self::KEEP), self::TTL_SECONDS);
    }

    private function key(string $requester, User $user, string $purpose): string
    {
        return 'sth:otp-requester:'.hash('sha256', $requester).':'.$user->getKey().':'.$purpose;
    }
}
