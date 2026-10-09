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
 * - One generic message for every failure (unknown identifier, wrong password, disabled/archived, a student's
 *   email before the activation is finished) — K11. The text stays the one of lang/id/auth.php.
 * - Only compares the stored hash (K6); unknown identifiers still run Hash::check on a dummy hash, and the
 *   whole check runs in a Timebox, so response time does not reveal whether an account exists.
 * - Progressive lockout per account bucket and client address (LoginLockout, QA10) on top of the `throttle:login`
 *   limiter; the right password clears the counter of its own address only. After many failures from every address
 *   the account only lets in devices it signed in from before (KnownDevices, QA10 review).
 * - Disabled/archived accounts are refused and their sessions revoked (D-29, D-30).
 * - K7 detection: a failed login on an already-activated student account with that student's initial
 *   password pattern is logged as `security.initial_password_on_active` (never grants access). A student who
 *   kept the password from school at activation (`activation.password_kept`) logs in with it, so K7 cannot fire
 *   for that account until the owner changes the password.
 */
final class AuthenticateUser
{
    public const GENERIC_ERROR = 'NIS/NISN atau kata sandi salah.';

    private const RESOLVED = 'sth.login.authenticated_user';

    private const FAILED = 'sth.login.failed';

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
        $account = $this->identifiers->forRequest($request);

        $knownDevice = KnownDevices::recognizes($request, $account);

        $this->lockout->ensureNotLocked($request, $bucket, $knownDevice);

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
            $this->lockout->recordFailure($request, $bucket, $account, $knownDevice);

            $this->fail($request);
        }

        if (! $user->status instanceof UserStatus || ! $user->status->canLogIn()) {
            $this->revokeSessions->handle($user);

            Log::notice('security.login_refused_inactive', ['user_id' => $user->getKey(), 'status' => $user->status?->value]);

            $this->fail($request, $user);
        }

        // QA10: only this address (and device kind) is cleared; an attacker's stays locked (LoginLockout).
        $this->lockout->succeeded($request, $bucket, $knownDevice);

        $request->attributes->set(self::RESOLVED, $user);

        return $user;
    }

    /**
     * Was this login refused after the password check (wrong password, unknown identifier, inactive account)? Read by
     * the hourly `throttle:login` limit, which counts only such attempts (QA10, AppServiceProvider).
     */
    public static function failed(Request $request): bool
    {
        return $request->attributes->get(self::FAILED) === true;
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
        // Fortify hands this class a copy of the request (its LoginRequest); the route limiter holds the original.
        $request->attributes->set(self::FAILED, true);
        request()->attributes->set(self::FAILED, true);

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
