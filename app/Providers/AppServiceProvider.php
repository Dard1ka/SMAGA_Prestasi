<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\LoginIdentifier;
use App\Domain\Accounts\PasswordPolicy;
use App\Domain\Archive\GraduationCalculator;
use App\Domain\Deadlines\ReportDeadline;
use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public const MIN_PASSWORD_LENGTH = 10;

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

        // Staff passwords (students additionally go through App\Domain\Accounts\PasswordPolicy).
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->max(64)->letters()->mixedCase()->numbers()->symbols()->uncompromised()
            : Password::min(self::MIN_PASSWORD_LENGTH)->max(64));

        $this->configureRateLimiting();
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
}
