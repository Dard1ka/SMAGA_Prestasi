<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\LoginIdentifier;
use App\Domain\Accounts\PasswordPolicy;
use App\Domain\Archive\GraduationCalculator;
use App\Domain\Deadlines\ReportDeadline;
use App\Enums\SettingKey;
use App\Models\Setting;
use App\Support\Audit\AuditChain;
use App\Support\Audit\SecurityEventSubscriber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public const MIN_PASSWORD_LENGTH = 10;

    /** Body of a throttled Livewire request (backlog #18; shown as a toast by resources/js/app.js). */
    public const LIVEWIRE_THROTTLED_MESSAGE = 'Terlalu banyak permintaan. Tunggu sebentar lalu coba lagi.';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Lead decision (SECURITY K5): new passwords need at least 10 characters.
        $this->app->bind(PasswordPolicy::class, fn (): PasswordPolicy => new PasswordPolicy(self::MIN_PASSWORD_LENGTH));

        // Admin-editable settings (D-25, Admin › Pengaturan › Batas & arsip). bind(), not singleton: a long-running
        // queue worker or scheduler must see a changed value on the next resolve.
        $this->app->bind(ReportDeadline::class, fn (): ReportDeadline => new ReportDeadline(
            self::settingInt(SettingKey::ReportDeadlineDays, ReportDeadline::DEFAULT_DAYS, ReportDeadline::MAX_DAYS),
        ));
        $this->app->bind(GraduationCalculator::class, fn (): GraduationCalculator => new GraduationCalculator(
            self::settingInt(SettingKey::ArchiveMonthsAfterGrade12, GraduationCalculator::DEFAULT_ARCHIVE_MONTHS, GraduationCalculator::MAX_ARCHIVE_MONTHS),
        ));
    }

    /**
     * Integer setting within 1..$max; the default when the value is out of range or the settings table is not
     * there yet (first migrate, package discovery).
     */
    public static function settingInt(SettingKey $key, int $default, int $max): int
    {
        $value = (int) rescue(fn (): mixed => Setting::get($key, $default), $default, false);

        return $value >= 1 && $value <= $max ? $value : $default;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->guardUnsafeConfiguration();

        Model::shouldBeStrict(! $this->app->isProduction());

        // Markdown mails: text printed with {{ }} cannot open a link, an image or HTML (SECURITY §3m-1; second layer
        // under App\Notifications\Support\PlainMailMessage).
        Markdown::withSecuredEncoding();

        // Staff passwords (students additionally go through App\Domain\Accounts\PasswordPolicy).
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->max(64)->letters()->mixedCase()->numbers()->symbols()->uncompromised()
            : Password::min(self::MIN_PASSWORD_LENGTH)->max(64));

        $this->configureRateLimiting();

        // B2 (backlog #16, #18): security log feeds + audit hash chain; throttled Livewire update endpoint.
        Event::subscribe(SecurityEventSubscriber::class);
        AuditChain::register();
        $this->configureLivewireEndpoint();
    }

    /**
     * Refuse to boot with settings that must never reach a shared environment (docs/SECURITY.md §3b, §3e).
     * Runs for HTTP, queue, scheduler and artisan alike.
     *
     * @throws RuntimeException
     */
    public function guardUnsafeConfiguration(): void
    {
        // OTP test mode (D-15): only APP_ENV=local may enable it. "testing" boots but the verifier ignores the
        // flag there (VerifyOtpCode::testModeActive() also requires local).
        if (config('fortify.sth.otp_test_mode') && ! $this->app->environment('local', 'testing')) {
            throw new RuntimeException('STH_OTP_TEST_MODE hanya boleh aktif di APP_ENV=local.');
        }

        if (! $this->app->isProduction()) {
            return;
        }

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            throw new RuntimeException('MAIL_MAILER log/array dilarang di production.');
        }

        if (config('app.debug')) {
            throw new RuntimeException('APP_DEBUG harus false di production.');
        }

        $cookie = (string) config('session.cookie');

        if (str_starts_with($cookie, '__Host-')
            && (! config('session.secure') || filled(config('session.domain')) || config('session.path') !== '/')) {
            throw new RuntimeException('Cookie __Host- wajib Secure, Path=/ dan tanpa Domain.');
        }
    }

    /**
     * Named rate limiters. Login buckets per ACCOUNT (NIS and NISN of one student share a bucket, SECURITY §3a),
     * strict per account+IP, looser per IP because the whole school sits behind one NAT address.
     */
    protected function configureRateLimiting(): void
    {
        $tooMany = fn (string $field) => function (Request $request, array $headers) use ($field) {
            $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));

            return back()
                ->withInput($request->only($field))
                ->withErrors([$field => self::retryMessage($seconds)]);
        };

        RateLimiter::for('login', function (Request $request) use ($tooMany) {
            $bucket = app(LoginIdentifier::class)->bucketKey($request);

            return [
                Limit::perMinute(5)->by('login:'.$bucket.'|'.$request->ip())->response($tooMany('identifier')),
                Limit::perHour(20)->by('login-h:'.$bucket)->response($tooMany('identifier')),
                Limit::perMinute(60)->by('login-ip:'.$request->ip())->response($tooMany('identifier')),
            ];
        });

        RateLimiter::for('two-factor', function (Request $request) use ($tooMany) {
            return Limit::perMinute(5)
                ->by('2fa:'.$request->session()->get('login.id', 'guest').'|'.$request->ip())
                ->response($tooMany('code'));
        });
    }

    public static function retryMessage(int $seconds): string
    {
        return $seconds >= 60
            ? 'Terlalu banyak percobaan. Coba lagi dalam '.(int) ceil($seconds / 60).' menit.'
            : 'Terlalu banyak percobaan. Coba lagi dalam '.$seconds.' detik.';
    }

    /**
     * B2 (backlog #18): Livewire's update endpoint (`/livewire-<hash>/update`) is re-registered with the named
     * limiter `livewire-update`: per user when logged in, per client IP for guests (public pages, auth screens).
     * Limits: config('livewire.sth_throttle.*'). A blocked request gets 429 + Retry-After and a short Indonesian
     * body; resources/js/app.js turns it into a toast instead of Livewire's error modal.
     */
    protected function configureLivewireEndpoint(): void
    {
        RateLimiter::for('livewire-update', function (Request $request) {
            $userId = $request->user()?->getAuthIdentifier();

            $limit = $userId !== null
                ? Limit::perMinute((int) config('livewire.sth_throttle.user_per_minute', 180))->by('lw-user:'.$userId)
                : Limit::perMinute((int) config('livewire.sth_throttle.guest_per_minute', 120))->by('lw-ip:'.$request->ip());

            return $limit->response(fn (Request $request, array $headers) => response(
                self::LIVEWIRE_THROTTLED_MESSAGE,
                429,
                $headers + ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store'],
            ));
        });

        Livewire::setUpdateRoute(fn (array $handle, string $path) => Route::post($path, $handle)
            ->middleware(['web', 'throttle:livewire-update']));

        // Backlog #17: Livewire serves its CSP build under the same URL and ?id= as the standard build, which browsers
        // cache for a year. A distinct query string makes them fetch the CSP build instead of running a cached
        // standard build (that one needs eval, which the CSP no longer allows).
        if (config('livewire.csp_safe') && blank(config('livewire.asset_url'))) {
            config(['livewire.asset_url' => EndpointResolver::scriptPath().'?build=csp']);
        }
    }
}
