<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Domain\Accounts\PasswordPolicy;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Stores a new password chosen by the user (first-login change or OTP reset). Rules = Laravel Password
 * defaults for the length/HIBP part + App\Domain\Accounts\PasswordPolicy (no initial pattern, school name,
 * birth date, NIS/NISN or own name — SECURITY K5). Other sessions are revoked; the audit log never holds
 * the password.
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
        );

        // Length is reported by the Password rule already.
        return array_values(array_filter($errors, fn (string $e): bool => ! str_starts_with($e, 'Minimal ') && $e !== PasswordPolicy::MSG_TOO_LONG));
    }

    public function handle(User $user, string $password, ?string $keepSessionId = null, string $reason = 'password.changed'): void
    {
        DB::transaction(function () use ($user, $password, $reason): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'must_change_password' => false,
                'password_changed_at' => now(),
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
