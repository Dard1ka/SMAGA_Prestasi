<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Models\User;
use App\Notifications\Auth\PasswordWasReset;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * "Lupa kata sandi" step 2: code + new password. The code is checked first WITHOUT spending it; only a
 * correct code unlocks the account-specific password rules; the code is consumed right before the password
 * is stored. Afterwards every session is revoked, a notice is mailed and the reset is audited.
 * All code/identifier failures give the same message (no enumeration).
 * A reset counts as the password step of the first-login flow: an account that had bound its email but not yet
 * closed that step becomes active here (CompleteActivation::whenPasswordAlreadyChosen).
 */
final class ResetPasswordWithCode
{
    public const INVALID_CODE = 'Kode salah atau sudah kedaluwarsa.';

    public function __construct(
        private readonly RequestPasswordResetCode $request,
        private readonly VerifyOtpCode $verify,
        private readonly SetNewPassword $setPassword,
        private readonly CompleteActivation $activation,
    ) {}

    /**
     * @param  array<string, string>  $messages  custom validation messages for the password field
     *
     * @throws ValidationException
     */
    public function handle(string $identifier, string $code, string $password, string $passwordConfirmation, array $messages = []): User
    {
        $user = $this->request->eligibleUser($identifier);

        if ($user === null || $this->verify->handle($user, RequestPasswordResetCode::PURPOSE, $code, consume: false) === null) {
            throw ValidationException::withMessages(['code' => self::INVALID_CODE]);
        }

        Validator::make(
            ['password' => $password, 'password_confirmation' => $passwordConfirmation],
            ['password' => $this->setPassword->rules($user)],
            $messages,
            ['password' => 'kata sandi'],
        )->validate();

        if ($this->verify->handle($user, RequestPasswordResetCode::PURPOSE, $code) === null) {
            throw ValidationException::withMessages(['code' => self::INVALID_CODE]);
        }

        // A reset also counts as the password change the first-login flow asks for.
        $this->setPassword->handle($user, $password, null, 'account.password_reset');
        $this->activation->whenPasswordAlreadyChosen($user);

        $user->notify(new PasswordWasReset);

        return $user;
    }
}
