<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Http\Middleware\RestrictTwoFactorToStaff;
use App\Models\User;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Minimal TOTP setup for staff (docs/SECURITY.md §3a, §3m-9), reached through `staff.2fa`. Route requires a
 * fresh password confirmation. Uses Fortify's own actions: enable (secret + 8 recovery codes) → scan QR →
 * confirm with a 6-digit code → show recovery codes once. Wave 2 moves this into `akun.keamanan`.
 * Student accounts get 404 (RestrictTwoFactorToStaff on the route and again in user()).
 */
#[Layout('layouts::auth', ['variant' => 'simple'])]
#[Title('Verifikasi dua langkah')]
class TwoFactorSetup extends Component
{
    #[Locked]
    public bool $showRecoveryCodes = false;

    public string $code = '';

    public function mount(EnableTwoFactorAuthentication $enable): void
    {
        $user = $this->user();

        if ($user->two_factor_confirmed_at === null) {
            // A secret that was never confirmed is replaced, so an old QR screenshot stops working.
            $enable($user, force: true);
        }
    }

    public function confirm(ConfirmTwoFactorAuthentication $confirm): void
    {
        $this->validate(['code' => ['required', 'digits:6']], ['required' => 'Wajib diisi.', 'digits' => 'Isi 6 angka dari aplikasi autentikator.']);

        $user = $this->user();

        try {
            $confirm($user, $this->code);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['code' => 'Kode salah. Coba kode terbaru dari aplikasi.']);
        }

        // New session id once 2FA is on; the old row is deleted (a wrong code above never gets here).
        session()->regenerate(true);

        // Audit row `two_factor.confirmed`: App\Support\Audit\TwoFactorAuditSubscriber (Fortify's event).

        $this->code = '';
        $this->showRecoveryCodes = true;
    }

    public function finish(): mixed
    {
        return $this->redirectRoute('home');
    }

    public function render(): mixed
    {
        $user = $this->user();
        $confirmed = $user->two_factor_confirmed_at !== null;

        $secret = $confirmed ? null : Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        return view('livewire.auth.two-factor-setup', [
            'confirmed' => $confirmed,
            'qrDataUri' => $secret === null ? null : 'data:image/svg+xml;base64,'.base64_encode($this->qrSvg($user, $secret)),
            'secret' => $secret,
            'recoveryCodes' => $this->showRecoveryCodes ? $user->recoveryCodes() : [],
        ]);
    }

    /**
     * Same QR as Fortify's twoFactorQrCodeSvg(), but labelled with the email/username: the Fortify helper reads
     * $user->{Fortify::username()}, and our login field `identifier` is not a user column.
     */
    private function qrSvg(User $user, string $secret): string
    {
        $url = app(TwoFactorAuthenticationProvider::class)->qrCodeUrl(
            (string) config('app.name'),
            (string) ($user->email ?: $user->username ?: $user->getKey()),
            $secret,
        );

        return (new Writer(new ImageRenderer(
            new RendererStyle(192, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(21, 26, 51))),
            new SvgImageBackEnd,
        )))->writeString($url);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        // Staff only, also on Livewire update requests (they do not pass the route's middleware): 404 for students.
        abort_unless(RestrictTwoFactorToStaff::allows($user), 404);

        return $user;
    }
}
