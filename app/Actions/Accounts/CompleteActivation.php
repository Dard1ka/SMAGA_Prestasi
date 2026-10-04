<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\Accounts\EmailBindingNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * First-login activation, order of the lead's decision of 2026-10-04: email → code → password.
 *
 * - bindEmail(): step 2. Binds the email proven by a verified `verify_email` OTP and mails the K8 notice with the
 *   "Bukan saya?" link to that address (SendEmailBindingNotice). The account stays `pending_activation` until the
 *   password step, unless no password step is left (the password was already chosen: an account that was half-way
 *   in the old order), then it becomes active here.
 * - changePassword(): step 3, new password (SetNewPassword rules, must differ from the current one).
 * - keepPassword(): step 3 for STUDENTS only: keeps the stored hash, whatever it is (the password itself is never
 *   built, shown or logged, CLAUDE.md rule 3). Audited as `activation.password_kept`; `password_changed_at` stays
 *   null, so the school can list who still uses the password it handed out. Staff must change theirs (403).
 * - whenPasswordAlreadyChosen(): closes the activation after "Lupa kata sandi" on a half-activated account.
 *
 * The account becomes `active` in the same transaction as the password step: `activated_at` (K9 report) is set and
 * the K1/K3 fields are cleared. In OTP test mode the email stays unverified (email_verified_at = null) so it is
 * re-verified once real OTP delivery is on (docs/SECURITY.md §3b point 4).
 */
final class CompleteActivation
{
    public const PURPOSE = OtpPurpose::VerifyEmail->value;

    public const EVENT_EMAIL_BOUND = 'account.email_bound';

    public const EVENT_PASSWORD_CHANGED = 'account.initial_password_changed';

    public const EVENT_PASSWORD_KEPT = 'activation.password_kept';

    public const EVENT_ACTIVATED = 'account.activated';

    public const MSG_SAME_PASSWORD = 'Pakai kata sandi yang berbeda dari yang sekarang.';

    public const MSG_EMAIL_FIRST = 'Hubungkan email dulu.';

    public const MSG_ALREADY_ACTIVE = 'Akun ini sudah aktif.';

    public function __construct(
        private readonly SendEmailBindingNotice $notices,
        private readonly SetNewPassword $setPassword,
        private readonly DetermineActivationStep $activationStep,
    ) {}

    /** Only a student account may keep its password; staff got theirs typed by an Admin and must replace it. */
    public static function mayKeepPassword(User $user): bool
    {
        return $user->student()->exists() && ! $user->isStaff();
    }

    /**
     * Step 2: bind the verified email.
     *
     * @throws ValidationException when the code is not this user's, the email step is not open, or the email was
     *                             taken by another account in the meantime
     */
    public function bindEmail(User $user, OtpCode $otp): void
    {
        if ((string) $otp->user_id !== (string) $user->getKey() || $otp->purpose !== OtpPurpose::VerifyEmail) {
            throw ValidationException::withMessages(['code' => 'Kode tidak valid.']);
        }

        $email = mb_strtolower((string) $otp->email);

        DB::transaction(function () use ($user, $email): void {
            $locked = $this->lock($user);

            if (($this->activationStep)($locked) !== DetermineActivationStep::EMAIL) {
                throw ValidationException::withMessages(['code' => 'Kode tidak valid.']);
            }

            $taken = User::query()
                ->where('email', $email)
                ->whereKeyNot($locked->getKey())
                ->lockForUpdate()
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['email' => 'Email ini sudah dipakai akun lain.']);
            }

            $locked->forceFill([
                'email' => $email,
                'email_verified_at' => VerifyOtpCode::testModeActive() ? null : now(),
            ])->save();

            activity('auth')
                ->performedOn($locked)
                ->causedBy($locked)
                ->event(self::EVENT_EMAIL_BOUND)
                ->withProperties([
                    'email' => AccountControls::maskEmail($email),
                    'otp_test_mode' => VerifyOtpCode::testModeActive(),
                ])
                ->log(self::EVENT_EMAIL_BOUND);

            // No password step left (it was chosen before the email, in the old order): the activation is done.
            if (! $locked->must_change_password) {
                $this->activate($locked);
            }

            $this->sync($user, $locked);
        });

        // K8: tell the bound address, with the single-use "Bukan saya?" link.
        $this->notices->handle($user, EmailBindingNotice::BOUND, $email, $email);
    }

    /**
     * Step 3 with a new password. Other sessions are revoked (SetNewPassword), `$keepSessionId` stays.
     *
     * @param  array<string, string>  $messages  custom validation messages for the password field
     *
     * @throws ValidationException
     */
    public function changePassword(User $user, string $password, string $passwordConfirmation, ?string $keepSessionId = null, array $messages = []): void
    {
        $this->assertPasswordStep($user);

        Validator::make(
            ['password' => $password, 'password_confirmation' => $passwordConfirmation],
            ['password' => $this->setPassword->rules($user)],
            $messages,
            ['password' => 'kata sandi'],
        )->validate();

        // Typing the current password again would be a hidden "keep": students have a button for that, staff may not.
        if (Hash::check($password, (string) $user->password)) {
            throw ValidationException::withMessages(['password' => self::MSG_SAME_PASSWORD]);
        }

        DB::transaction(function () use ($user, $password, $keepSessionId): void {
            $locked = $this->lock($user);
            $this->assertPasswordStep($locked);

            $this->setPassword->handle($locked, $password, $keepSessionId, self::EVENT_PASSWORD_CHANGED);
            $this->activate($locked);

            $this->sync($user, $locked);
        });
    }

    /**
     * Step 3 without a change (students only): the stored hash stays as it is.
     *
     * @throws AuthorizationException for an account that is not a student's
     * @throws ValidationException when the account is not at the password step
     */
    public function keepPassword(User $user): void
    {
        if (! self::mayKeepPassword($user)) {
            throw new AuthorizationException('Kata sandi akun ini wajib diganti.');
        }

        DB::transaction(function () use ($user): void {
            $locked = $this->lock($user);
            $this->assertPasswordStep($locked);

            $locked->forceFill(['must_change_password' => false])->save();

            // No password material: only the fact, the account and the time.
            activity('auth')
                ->performedOn($locked)
                ->causedBy($locked)
                ->event(self::EVENT_PASSWORD_KEPT)
                ->log(self::EVENT_PASSWORD_KEPT);

            $this->activate($locked);

            $this->sync($user, $locked);
        });
    }

    /**
     * For a password chosen outside the activation screen ("Lupa kata sandi" on an account whose email is bound but
     * whose password step is still open): with a verified email and no forced change left, the account is active.
     *
     * @return bool true when this call activated the account
     */
    public function whenPasswordAlreadyChosen(User $user): bool
    {
        return DB::transaction(function () use ($user): bool {
            $locked = $this->lock($user);

            if ($locked->status !== UserStatus::PendingActivation
                || $locked->must_change_password
                || ! DetermineActivationStep::emailVerified($locked)) {
                return false;
            }

            $this->activate($locked);
            $this->sync($user, $locked);

            return true;
        });
    }

    /** Inside a transaction. An account that is already active (email re-verification) is left as it is. */
    private function activate(User $user): void
    {
        if ($user->status !== UserStatus::PendingActivation) {
            return;
        }

        $user->forceFill([
            'status' => UserStatus::Active,
            // K9 activation date; the K1/K3 counters are no longer needed once activated.
            'activated_at' => now(),
            'initial_password_expires_at' => null,
            'failed_activation_attempts' => 0,
        ])->save();

        activity('auth')
            ->performedOn($user)
            ->causedBy($user)
            ->event(self::EVENT_ACTIVATED)
            ->withProperties([
                'otp_test_mode' => VerifyOtpCode::testModeActive(),
                // false = the owner kept the password the school (or an Admin reset) handed out.
                'password_changed' => $user->password_changed_at !== null,
            ])
            ->log(self::EVENT_ACTIVATED);
    }

    /**
     * @throws ValidationException
     */
    private function assertPasswordStep(User $user): void
    {
        $step = ($this->activationStep)($user);

        if ($step !== DetermineActivationStep::PASSWORD) {
            throw ValidationException::withMessages([
                'password' => $step === null ? self::MSG_ALREADY_ACTIVE : self::MSG_EMAIL_FIRST,
            ]);
        }
    }

    private function lock(User $user): User
    {
        /** @var User $locked */
        $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }

    /** The caller's instance (the signed-in user of the request) sees what was stored. */
    private function sync(User $user, User $locked): void
    {
        $user->setRawAttributes($locked->getAttributes(), true);
    }
}
