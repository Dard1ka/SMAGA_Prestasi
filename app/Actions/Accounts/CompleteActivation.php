<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Domain\Accounts\PhoneNumber;
use App\Domain\Accounts\StudentProfileInput;
use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Models\OtpCode;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Accounts\EmailBindingNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * First-login activation, order of the lead's decision of 2026-10-04: email → code → (students: Data diri, D-152) →
 * password.
 *
 * - bindEmail(): step 2. Binds the email proven by a verified `verify_email` OTP and mails the K8 notice with the
 *   "Bukan saya?" link to that address (SendEmailBindingNotice). The account stays `pending_activation` until the
 *   password step, unless no password step is left (the password was already chosen: an account that was half-way
 *   in the old order), then it becomes active here (a student with an open profile step only after confirmProfile).
 * - confirmProfile(): students only, birth date + No HP (D-152).
 * - changePassword(): step 3, new password (SetNewPassword rules, must differ from the current one). Every account
 *   replaces its password here, students and staff alike (D-138; D-92 now holds for all): there is no way to keep
 *   the stored one, so the password of the school or of an Admin reset never outlives the first login.
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

    /** Audit rows written before D-138 only: the skip is gone, but old rows keep their label. */
    public const EVENT_PASSWORD_KEPT = 'activation.password_kept';

    public const EVENT_ACTIVATED = 'account.activated';

    /** D-152: the student confirmed birth date + No HP during the activation (flags only, no values). */
    public const EVENT_PROFILE_CONFIRMED = 'activation.profile_confirmed';

    public const MSG_SAME_PASSWORD = 'Pakai kata sandi yang berbeda dari yang sekarang.';

    public const MSG_EMAIL_FIRST = 'Hubungkan email dulu.';

    public const MSG_PROFILE_FIRST = 'Isi data diri dulu.';

    public const MSG_PROFILE_DONE = 'Data diri sudah tersimpan.';

    public const MSG_ALREADY_ACTIVE = 'Akun ini sudah aktif.';

    public function __construct(
        private readonly SendEmailBindingNotice $notices,
        private readonly SetNewPassword $setPassword,
        private readonly DetermineActivationStep $activationStep,
    ) {}

    /** The stored password is the temporary one of an Admin reset (ResetStudentAccount) and was not replaced yet. */
    public static function hasTemporaryPassword(User $user): bool
    {
        return AccountControls::date($user, 'temporary_password_issued_at') !== null;
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

            // No password step left (it was chosen before the email, in the old order): the activation is done,
            // unless a student still has to confirm the profile (D-152, confirmProfile activates then).
            if (! $locked->must_change_password && ! DetermineActivationStep::profileOpen($locked)) {
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

        // Typing the current password again would be a hidden "keep": every account must replace it (D-138).
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
                || ! DetermineActivationStep::emailVerified($locked)
                || DetermineActivationStep::profileOpen($locked)) {
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
                // Always true since D-138; false only on rows written when a student could still keep the school password.
                'password_changed' => $user->password_changed_at !== null,
            ])
            ->log(self::EVENT_ACTIVATED);
    }

    /**
     * Step "Data diri" (D-152), students only, between the code and the password: the birth date (pre-filled from the
     * import, may be corrected) and the No HP, both required (StudentProfileInput). Stores them, stamps
     * `students.profile_confirmed_at` and audits `activation.profile_confirmed` with two flags only (which field
     * changed), never a value. When no password step is left (a password chosen through "Lupa kata sandi" while
     * activating), the account becomes active here.
     *
     * @throws ValidationException keys `birth_date` / `phone`
     */
    public function confirmProfile(User $user, string $birthDate, string $phone): void
    {
        $this->assertProfileStep($user);

        $errors = StudentProfileInput::errors($birthDate, $phone, CarbonImmutable::today());

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /** @var CarbonImmutable $date */
        $date = StudentProfileInput::parseBirthDate($birthDate);
        $number = PhoneNumber::normalize($phone);

        DB::transaction(function () use ($user, $date, $number): void {
            $locked = $this->lock($user);
            $this->assertProfileStep($locked);

            /** @var Student $student */
            $student = Student::query()->where('user_id', $locked->getKey())->lockForUpdate()->firstOrFail();

            $birthDateChanged = $student->birth_date?->format('Y-m-d') !== $date->format('Y-m-d');
            // A value that cannot be decrypted (an old key) counts as "changed": it is replaced.
            $phoneChanged = rescue(fn () => $student->phone, null, false) !== $number;

            $student->forceFill([
                'birth_date' => $date->format('Y-m-d'),
                'phone' => $number,
                'profile_confirmed_at' => now(),
            ])->save();

            activity('auth')
                ->performedOn($locked)
                ->causedBy($locked)
                ->event(self::EVENT_PROFILE_CONFIRMED)
                ->withProperties([
                    'birth_date_changed' => $birthDateChanged,
                    'phone_changed' => $phoneChanged,
                ])
                ->log(self::EVENT_PROFILE_CONFIRMED);

            if (! $locked->must_change_password) {
                $this->activate($locked);
            }

            $this->sync($user, $locked);
        });

        $user->unsetRelation('student');
    }

    /**
     * @throws ValidationException
     */
    private function assertPasswordStep(User $user): void
    {
        $step = ($this->activationStep)($user);

        if ($step !== DetermineActivationStep::PASSWORD) {
            throw ValidationException::withMessages([
                'password' => match ($step) {
                    null => self::MSG_ALREADY_ACTIVE,
                    DetermineActivationStep::PROFILE => self::MSG_PROFILE_FIRST,
                    default => self::MSG_EMAIL_FIRST,
                },
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertProfileStep(User $user): void
    {
        $step = ($this->activationStep)($user);

        if ($step !== DetermineActivationStep::PROFILE) {
            throw ValidationException::withMessages([
                'birth_date' => match ($step) {
                    null => self::MSG_ALREADY_ACTIVE,
                    DetermineActivationStep::EMAIL => self::MSG_EMAIL_FIRST,
                    default => self::MSG_PROFILE_DONE,
                },
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
