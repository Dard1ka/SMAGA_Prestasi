<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Domain\Accounts\PasswordPolicy;
use App\Models\User;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Stores a new password chosen by the user (first-login change or OTP reset). Rules = Laravel Password
 * defaults for the length/HIBP part + App\Domain\Accounts\PasswordPolicy (no initial pattern, school name,
 * birth date, NIS/NISN or own name — SECURITY K5; since M2-A also no username, no email name and no common
 * password such as "password123"). Other sessions are revoked; the audit log never holds the password.
 * A password stored here is the owner's own, so the mark of an Admin's temporary password is cleared
 * (`temporary_password_issued_at`, see ResetStudentAccount).
 */
final class SetNewPassword
{
    public function __construct(
        private readonly PasswordPolicy $policy,
        private readonly RevokeUserSessions $revokeSessions,
    ) {}

    /**
     * Validation rules for the `password` field (use with a `password_confirmation` field).
     *
     * @return array<int, mixed>
     */
    public function rules(User $user): array
    {
        // Staff get the stricter app-wide defaults (AppServiceProvider); students the K5 length rule.
        if ($user->isStaff()) {
            $length = Password::default();
        } else {
            $length = Password::min($this->policy->minLength())->max(PasswordPolicy::MAX_LENGTH);

            if (app()->isProduction()) {
                $length = $length->uncompromised();
            }
        }

        return [
            'required',
            'string',
            'confirmed',
            $length,
            function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                foreach ($this->policyErrors($user, (string) $value) as $message) {
                    $fail($message);
                }
            },
        ];
    }

    /** @return list<string> */
    public function policyErrors(User $user, string $password): array
    {
        $student = $user->student;

        $errors = $this->policy->validate(
            $password,
            $student?->birth_date,
            $student?->nis,
            $student?->nisn,
            $user->name,
            $user->username,
            $user->email,
        );

        // Length is reported by the Password rule already.
        return array_values(array_filter($errors, fn (string $e): bool => ! str_starts_with($e, 'Minimal ') && $e !== PasswordPolicy::MSG_TOO_LONG));
    }

    /**
     * The line under a "new password" field, matching the rules that `rules()` applies to this account: a staff
     * account reads the staff length and character rules and its own identifiers, not the student's NIS/NISN
     * (QA Q2-05). Without a user (the guest "Lupa kata sandi" screen) the student text is returned.
     */
    public function hint(?User $user = null): string
    {
        $min = $this->policy->minLength();
        $needs = ['huruf', 'angka'];

        if ($user?->isStaff() && ($default = Password::default()) instanceof Password) {
            $applied = $default->appliedRules();
            $min = (int) $applied['min'];
            $needs = $applied['mixedCase'] ? ['huruf besar', 'huruf kecil', 'angka'] : $needs;

            if ($applied['symbols']) {
                $needs[] = 'simbol';
            }
        }

        $own = $user === null || $user->student !== null
            ? 'tanggal lahir, NIS/NISN, nama'
            : 'nama, nama pengguna, email';

        return sprintf(
            'Minimal %d karakter, ada %s. Jangan pakai %s, atau kata yang mudah ditebak.',
            $min,
            Arr::join($needs, ', ', count($needs) > 2 ? ', dan ' : ' dan '),
            $own,
        );
    }

    public function handle(User $user, string $password, ?string $keepSessionId = null, string $reason = 'password.changed'): void
    {
        DB::transaction(function () use ($user, $password, $reason): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'must_change_password' => false,
                'password_changed_at' => now(),
                'temporary_password_issued_at' => null,
            ])->save();

            activity('auth')
                ->performedOn($user)
                ->causedBy($user)
                ->event($reason)
                ->log($reason);
        });

        $this->revokeSessions->handle($user, $keepSessionId);
    }
}
