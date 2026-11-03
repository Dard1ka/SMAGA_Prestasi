<?php

declare(strict_types=1);

namespace App\Livewire\Akun;

use App\Actions\Accounts\FreezeAccountFromNotice;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * K8 "Bukan saya?" page (`akun.bukan-saya`, B1): opened from the signed link in an email-binding notice
 * (route middleware `signed`, 7 days). A GET never changes anything (mail scanners prefetch links); the owner
 * confirms with the button, which freezes the account through FreezeAccountFromNotice (single use, sessions
 * revoked, Admin alerted). Works for guests and signed-in users; a signed-in user of that account is logged out.
 */
#[Title('Bukan saya')]
#[Layout('layouts::auth', ['variant' => 'simple'])]
final class BukanSaya extends Component
{
    public const STATE_ASK = 'ask';

    public const STATE_DONE = 'done';

    public const STATE_INVALID = 'invalid';

    /** Notice id from the signed URL (verified by the `signed` middleware on the page request). */
    #[Locked]
    public string $notice = '';

    #[Locked]
    public string $state = self::STATE_ASK;

    public function mount(string $notice): void
    {
        $this->notice = $notice;
        $this->state = FreezeAccountFromNotice::usable($notice) ? self::STATE_ASK : self::STATE_INVALID;
    }

    public function freeze(FreezeAccountFromNotice $freeze): void
    {
        if ($this->state !== self::STATE_ASK) {
            return;
        }

        $user = $freeze->handle($this->notice);

        if ($user === null) {
            $this->state = self::STATE_INVALID;

            return;
        }

        if (Auth::check() && (string) Auth::id() === (string) $user->getKey()) {
            Auth::guard('web')->logout();
            session()->invalidate();
            session()->regenerateToken();
        }

        $this->state = self::STATE_DONE;
    }

    public function render(): View
    {
        return view('livewire.akun.bukan-saya');
    }
}
