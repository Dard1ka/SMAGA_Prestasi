<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\Accounts\DetermineActivationStep;
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
 * - contains "@" → the one account with that email (`users.email` is unique): a staff account always, a student
 *   account only once its first-login activation is finished (email bound and verified, status `active`; lead's
 *   decision of 2026-10-04). Before that, and for a disabled or archived student, the address resolves to nobody:
 *   same answer, same timing and same rate-limit bucket as an address nobody uses;
 * - anything else → staff user by username.
 * Students never log in by username and staff never by NIS/NISN.
 *
 * bucketKey() gives every identifier of one account (NIS, NISN, email) the same rate-limit bucket ("u:<id>"), and
 * a stable HMAC bucket for unknown identifiers so limiter behaviour does not reveal whether an account exists.
 */
final class LoginIdentifier
{
    private const RESOLVED = 'sth.login.identifier_user';

    public function __construct(private readonly DetermineActivationStep $activationStep) {}

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

        $byEmail = str_contains($identifier, '@');

        $user = User::query()
            ->where($byEmail ? 'email' : 'username', $identifier)
            ->first();

        if ($user === null) {
            return null;
        }

        if (self::isStudentAccount($user)) {
            // Never by username; by email only after the activation proved the address is the student's own.
            return $byEmail && $this->activationStep->finished($user) ? $user : null;
        }

        return $user;
    }

    /** The same test as RestrictTwoFactorToStaff uses: the `siswa` role or a student record. */
    public static function isStudentAccount(User $user): bool
    {
        return $user->hasRole(RoleName::Siswa->value) || $user->student()->exists();
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
