<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** otp_codes.purpose */
enum OtpPurpose: string
{
    use HasOptions;

    case VerifyEmail = 'verify_email';
    case ResetPassword = 'reset_password';

    public function label(): string
    {
        return match ($this) {
            self::VerifyEmail => 'Verifikasi email',
            self::ResetPassword => 'Atur ulang kata sandi',
        };
    }
}
