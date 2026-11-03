<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Pengaturan;

use App\Actions\Accounts\VerifyOtpCode;
use App\Models\Setting;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Tab "Email": read-only. OTP test mode is an environment flag (STH_OTP_TEST_MODE, only effective with
 * APP_ENV=local, D-15/SECURITY §3b), never a setting, so it cannot be switched here. SMTP is configured by
 * the deploy team (docs/DEPLOY.md); no host/user/password is shown.
 */
final class Email extends Component
{
    public function mount(): void
    {
        Gate::authorize('update', Setting::class);
    }

    public function render(): View
    {
        $mailer = (string) config('mail.default');

        return view('livewire.admin.pengaturan.email', [
            'testMode' => VerifyOtpCode::testModeActive(),
            'mailerReady' => ! in_array($mailer, ['log', 'array'], true),
            'fromAddress' => (string) config('mail.from.address'),
            'fromName' => (string) config('mail.from.name'),
        ]);
    }
}
