<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Concerns;

use Livewire\Attributes\Locked;

/**
 * SECURITY §3m-9: sensitive Admin actions (roles, resets, Ganti Kepala Sekolah, signature, weights, limits)
 * need a password confirmation from the last 15 minutes, even though the page itself only needs the
 * framework default (`password.confirm` middleware, 3 h). Call at the top of an action:
 *
 *     if (! $this->passwordRecentlyConfirmed()) { return; }
 *
 * Otherwise the user is sent to Fortify's /user/confirm-password and comes back to $this->returnUrl().
 *
 * A download passes the call that starts it, `passwordRecentlyConfirmed('export')`: back from the password page,
 * that screen runs it once by itself (`$resumeDownload`, rendered by livewire/partials/resume-download), so the
 * user does not have to find the button again (QA Q2-18, backlog #63). Only downloads: an action that changes
 * data never runs without a click.
 */
trait RequiresRecentPassword
{
    public const PASSWORD_WINDOW_SECONDS = 900;

    private const RESUME_SESSION_KEY = 'sth.password_resume';

    /** The download to start on the first render after the password page ("export", "export('xlsx')"), else null. */
    #[Locked]
    public ?string $resumeDownload = null;

    /** @param  string|null  $resumeDownload  a download only: the wire call that starts it again (a literal) */
    protected function passwordRecentlyConfirmed(?string $resumeDownload = null): bool
    {
        if ($this->passwordConfirmedRecently()) {
            $this->resumeDownload = null;

            return true;
        }

        session()->put('url.intended', $this->returnUrl());

        if ($resumeDownload !== null) {
            session()->put(self::RESUME_SESSION_KEY, [
                'component' => static::class,
                'call' => $resumeDownload,
                'until' => time() + self::PASSWORD_WINDOW_SECONDS,
            ]);
        } else {
            session()->forget(self::RESUME_SESSION_KEY);
        }

        $this->redirectRoute('password.confirm');

        return false;
    }

    /** Livewire runs this with mount(): the screen that sent the user to the password page takes its download back. */
    public function mountRequiresRecentPassword(): void
    {
        $resume = session(self::RESUME_SESSION_KEY);

        if (! is_array($resume) || ($resume['component'] ?? null) !== static::class) {
            return;
        }

        session()->forget(self::RESUME_SESSION_KEY);

        if (is_string($resume['call'] ?? null) && (int) ($resume['until'] ?? 0) >= time() && $this->passwordConfirmedRecently()) {
            $this->resumeDownload = $resume['call'];
        }
    }

    abstract protected function returnUrl(): string;

    private function passwordConfirmedRecently(): bool
    {
        return time() - (int) session('auth.password_confirmed_at', 0) <= self::PASSWORD_WINDOW_SECONDS;
    }
}
