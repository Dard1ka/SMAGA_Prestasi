<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Models\User;
use App\Notifications\Accounts\SecurityNotice;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Akun › Keamanan "Ganti kata sandi" (B1): the current password is required; the new one follows the same
 * rules as activation (students: PasswordPolicy(10) via SetNewPassword::rules, staff: Password::defaults()),
 * must differ from the current one; other sessions are revoked (the current one is kept), audited
 * (`password.changed`) and the owner is told by mail + bell. Error keys: current_password, password.
 */
final class ChangePassword
{
    public function __construct(private readonly SetNewPassword $setPassword) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $user, string $currentPassword, string $newPassword, ?string $keepSessionId = null): void
    {
        if ($currentPassword === '' || ! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Kata sandi sekarang salah.']);
        }

        Validator::make(
            ['password' => $newPassword, 'password_confirmation' => $newPassword],
            ['password' => $this->setPassword->rules($user)],
            ['min' => 'Minimal :min karakter.', 'max' => 'Maksimal :max karakter.'],
            ['password' => 'kata sandi baru'],
        )->validate();

        if (Hash::check($newPassword, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Pakai kata sandi yang berbeda dari yang sekarang.']);
        }

        $this->setPassword->handle($user, $newPassword, $keepSessionId, 'password.changed');

        $user->notify(SecurityNotice::passwordChanged());
    }
}
