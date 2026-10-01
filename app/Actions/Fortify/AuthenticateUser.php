<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\Accounts\RevokeUserSessions;
use App\Domain\Accounts\InitialPassword;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * Fortify::authenticateUsing callback (docs/SECURITY.md §3a).
 * - One generic message for every failure (unknown identifier, wrong password, disabled/archived) — K11.
 * - Only compares the stored hash (K6); unknown identifiers still run Hash::check on a dummy hash, and the
 *   whole check runs in a Timebox, so response time does not reveal whether an account exists.
 * - Progressive lockout per account bucket (LoginLockout) on top of the `throttle:login` limiter.
 * - Disabled/archived accounts are refused and their sessions revoked (D-29, D-30).
 * - K7 detection: a failed login on an already-activated student account with that student's initial
 *   password pattern is logged as `security.initial_password_on_active` (never grants access).
 */
final class AuthenticateUser
{
    public const GENERIC_ERROR = 'NIS/NISN atau kata sandi salah.';

    private const RESOLVED = 'sth.login.authenticated_user';

    private const TIMEBOX_MICROSECONDS = 250_000;

    public function __construct(
        private readonly LoginIdentifier $identifiers,
        private readonly LoginLockout $lockout,
        private readonly RevokeUserSessions $revokeSessions,
        private readonly InitialPassword $initialPassword,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request): User
    {
        if ($request->attributes->has(self::RESOLVED)) {
            return $request->attributes->get(self::RESOLVED);
        }

        // No "remember me" (SECURITY §3a: none for staff, shared PCs at school): Fortify reads this flag later.
        $request->merge(['remember' => false]);

        $bucket = $this->identifiers->bucketKey($request);

        $this->lockout->ensureNotLocked($request, $bucket);

        $password = (string) $request->input('password');

        /** @var User|null $user */
        $user = (new Timebox)->call(function () use ($request, $password): ?User {
            $user = $this->identifiers->forRequest($request);

            if ($user === null) {
                Hash::check($password, self::dummyHash());

                return null;
            }

            return Hash::check($password, $user->password) ? $user : $this->failedPassword($user, $password);
        }, self::TIMEBOX_MICROSECONDS);

        if ($user === null) {
            $this->lockout->recordFailure($bucket);

            $this->fail($request);
        }

        if (! $user->status instanceof UserStatus || ! $user->status->canLogIn()) {
            $this->revokeSessions->handle($user);

            Log::notice('security.login_refused_inactive', ['user_id' => $user->getKey(), 'status' => $user->status?->value]);

            $this->fail($request, $user);
        }

        $this->lockout->clear($bucket);

        $request->attributes->set(self::RESOLVED, $user);

        return $user;
    }

    private function failedPassword(User $user, string $password): null
    {
        $student = $user->student;

        if ($student !== null && ! $user->must_change_password && $student->birth_date !== null
            && $this->initialPassword->matches($password, $student->birth_date)) {
            Log::warning('security.initial_password_on_active', ['user_id' => $user->getKey()]);

            activity('security')
                ->performedOn($user)
                ->event('security.initial_password_on_active')
                ->log('security.initial_password_on_active');
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    private function fail(Request $request, ?User $user = null): never
    {
        event(new Failed(config('fortify.guard', 'web'), $user, [
            Fortify::username() => (string) $request->input(Fortify::username()),
        ]));

        throw ValidationException::withMessages([Fortify::username() => self::GENERIC_ERROR]);
    }

    /** A hash made with the current driver/cost, cached so unknown identifiers cost the same as real ones. */
    private static function dummyHash(): string
    {
        $key = 'sth:login-dummy-hash:'.config('hashing.driver').':'.config('hashing.bcrypt.rounds');

        return Cache::rememberForever($key, fn (): string => Hash::make(Str::random(40)));
    }
}
