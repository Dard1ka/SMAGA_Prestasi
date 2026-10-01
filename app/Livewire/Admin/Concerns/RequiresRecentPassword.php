<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Concerns;

/**
 * SECURITY §3m-9: sensitive Admin actions (roles, resets, Ganti Kepala Sekolah, signature, weights, limits)
 * need a password confirmation from the last 15 minutes, even though the page itself only needs the
 * framework default (`password.confirm` middleware, 3 h). Call at the top of an action:
 *
 *     if (! $this->passwordRecentlyConfirmed()) { return; }
 *
 * Otherwise the user is sent to Fortify's /user/confirm-password and comes back to $this->returnUrl().
 */
trait RequiresRecentPassword
{
    public const PASSWORD_WINDOW_SECONDS = 900;

    protected function passwordRecentlyConfirmed(): bool
    {
        $confirmedAt = (int) session('auth.password_confirmed_at', 0);

        if (time() - $confirmedAt <= self::PASSWORD_WINDOW_SECONDS) {
            return true;
        }

        session()->put('url.intended', $this->returnUrl());
        $this->redirectRoute('password.confirm');

        return false;
    }

    abstract protected function returnUrl(): string;
}
