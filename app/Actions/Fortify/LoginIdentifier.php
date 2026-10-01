<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\RoleName;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Fortify;

/**
 * Resolves the login field (docs/SECURITY.md §3a K11):
 * - digits only → student by NIS or NISN (10 digits is usually a NISN; both columns are checked, and if the
 *   value matches two different accounts the login is refused and a security event is logged);
 * - contains "@" → staff user by email; anything else → staff user by username.
 * Students never log in by email/username and staff never by NIS/NISN.
 *
 * bucketKey() gives NIS and NISN of one student the same rate-limit bucket ("u:<id>"), and a stable HMAC
 * bucket for unknown identifiers so limiter behaviour does not reveal whether an account exists.
 */
final class LoginIdentifier
{
    private const RESOLVED = 'sth.login.identifier_user';

    public static function normalize(string $identifier): string
    {
        $identifier = trim($identifier);

        return str_contains($identifier, '@')
            ? mb_strtolower($identifier)
            : (string) preg_replace('/\s+/', '', mb_strtolower($identifier));
    }

    public function resolve(string $identifier): ?User
    {
        $identifier = self::normalize($identifier);

        if ($identifier === '' || mb_strlen($identifier) > 254) {
            return null;
        }

        if (ctype_digit($identifier)) {
            return $this->resolveStudent($identifier);
        }

        $user = User::query()
            ->where(str_contains($identifier, '@') ? 'email' : 'username', $identifier)
            ->first();

        // Staff only: a student account never signs in with an email or username.
        return $user !== null && ! $user->hasRole(RoleName::Siswa->value) && $user->student()->doesntExist() ? $user : null;
    }

    /** Memoised per request, so the rate limiter and the authenticator share one lookup. */
    public function forRequest(Request $request): ?User
    {
        if (! $request->attributes->has(self::RESOLVED)) {
            $request->attributes->set(self::RESOLVED, $this->resolve((string) $request->input(Fortify::username())));
        }

        return $request->attributes->get(self::RESOLVED);
    }

    public function bucketKey(Request $request): string
    {
        $user = $this->forRequest($request);

        if ($user !== null) {
            return 'u:'.$user->getKey();
        }

        $normalized = self::normalize((string) $request->input(Fortify::username()));

        return 'x:'.substr(hash_hmac('sha256', $normalized, (string) config('app.key')), 0, 32);
    }

    private function resolveStudent(string $digits): ?User
    {
        if (strlen($digits) > 20) {
            return null;
        }

        $userIds = Student::query()
            ->where('nis', $digits)
            ->orWhere('nisn', $digits)
            ->pluck('user_id')
            ->unique();

        if ($userIds->count() > 1) {
            Log::warning('security.login_identifier_ambiguous', ['user_ids' => $userIds->values()->all()]);

            return null;
        }

        $userId = $userIds->first();

        return $userId === null ? null : User::query()->find($userId);
    }
}
