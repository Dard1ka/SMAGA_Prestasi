<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Auth\TwoFactorSetup;
use App\Models\User;
use App\Support\Audit\TwoFactorAuditSubscriber;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

/*
 | Every TOTP change of a staff account leaves an audit row (log `auth`), whether it comes from the setup screen
 | or from Fortify's JSON routes: enabled, confirmed, disabled, recovery codes regenerated. No secrets in the row.
 */

beforeEach(function (): void {
    H::seedRoles();
});

/** @return Collection<int, Activity> */
function m3Audit(string $event): Collection
{
    return Activity::query()->where('event', $event)->orderBy('id')->get();
}

/** Signed-in staff member with a fresh password confirmation. */
function m3TotpStaff(RoleName $role = RoleName::Kesiswaan): User
{
    $staff = H::staff($role);

    test()->actingAs($staff)->withSession(['auth.password_confirmed_at' => time()]);

    return $staff;
}

it('audits enabling TOTP', function (): void {
    $staff = m3TotpStaff();

    $this->postJson(route('two-factor.enable'))->assertOk();

    $row = m3Audit('two_factor.enabled')->sole();

    expect($row->log_name)->toBe('auth')
        ->and($row->description)->toBe('two_factor.enabled')
        ->and($row->subject_type)->toBe($staff->getMorphClass())
        ->and((string) $row->subject_id)->toBe((string) $staff->getKey())
        ->and((string) $row->causer_id)->toBe((string) $staff->getKey());
});

it('audits confirming TOTP', function (): void {
    $staff = m3TotpStaff();

    $this->postJson(route('two-factor.enable'))->assertOk();
    $secret = Fortify::currentEncrypter()->decrypt($staff->fresh()->two_factor_secret);

    // A wrong code confirms nothing and leaves no row.
    $this->postJson(route('two-factor.confirm'), ['code' => '000000'])->assertUnprocessable();
    expect(m3Audit('two_factor.confirmed'))->toHaveCount(0);

    $this->postJson(route('two-factor.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertOk();

    $row = m3Audit('two_factor.confirmed')->sole();

    expect($row->log_name)->toBe('auth')
        ->and((string) $row->subject_id)->toBe((string) $staff->getKey())
        ->and((string) $row->causer_id)->toBe((string) $staff->getKey());
});

it('audits new recovery codes', function (): void {
    $staff = m3TotpStaff();
    $this->postJson(route('two-factor.enable'))->assertOk();

    // Enabling creates the first codes; only replacing them counts as "regenerated".
    expect(m3Audit('two_factor.recovery_codes_regenerated'))->toHaveCount(0);

    $this->postJson(route('two-factor.regenerate-recovery-codes'))->assertOk();

    $row = m3Audit('two_factor.recovery_codes_regenerated')->sole();

    expect($row->log_name)->toBe('auth')
        ->and((string) $row->subject_id)->toBe((string) $staff->getKey());
});

it('audits disabling TOTP', function (): void {
    $staff = m3TotpStaff(RoleName::Admin);
    $this->postJson(route('two-factor.enable'))->assertOk();

    $this->deleteJson(route('two-factor.disable'))->assertOk();

    $row = m3Audit('two_factor.disabled')->sole();

    expect($row->log_name)->toBe('auth')
        ->and((string) $row->subject_id)->toBe((string) $staff->getKey())
        ->and((string) $row->causer_id)->toBe((string) $staff->getKey());

    // Nothing left to disable: no second row.
    $this->deleteJson(route('two-factor.disable'))->assertOk();
    expect(m3Audit('two_factor.disabled'))->toHaveCount(1);
});

it('audits the setup screen the same way, with one row per event', function (): void {
    $staff = m3TotpStaff();

    $component = Livewire::test(TwoFactorSetup::class);
    $secret = Fortify::currentEncrypter()->decrypt($staff->fresh()->two_factor_secret);

    expect(m3Audit('two_factor.enabled'))->toHaveCount(1);

    $component->set('code', app(Google2FA::class)->getCurrentOtp($secret))->call('confirm')->assertHasNoErrors();

    expect(m3Audit('two_factor.confirmed'))->toHaveCount(1)
        ->and((string) m3Audit('two_factor.confirmed')->sole()->causer_id)->toBe((string) $staff->getKey());
});

it('never writes the secret or the recovery codes into the audit rows', function (): void {
    $staff = m3TotpStaff();

    $this->postJson(route('two-factor.enable'))->assertOk();
    $this->postJson(route('two-factor.regenerate-recovery-codes'))->assertOk();

    $staff->refresh();
    $secret = Fortify::currentEncrypter()->decrypt($staff->two_factor_secret);
    $rows = (string) json_encode(Activity::query()->get()->toArray());

    expect($rows)->not->toContain($secret)->not->toContain($staff->two_factor_secret);

    foreach ($staff->recoveryCodes() as $code) {
        expect($rows)->not->toContain($code);
    }
});

it('caps the rows per user and event, so re-posting cannot flood the append-only audit log', function (): void {
    $staff = m3TotpStaff();
    $max = TwoFactorAuditSubscriber::MAX_ROWS_PER_MINUTE;

    foreach (range(1, $max + 5) as $i) {
        $this->postJson(route('two-factor.enable'), ['force' => true])->assertOk();
    }

    expect(m3Audit('two_factor.enabled'))->toHaveCount($max);

    // Another kind of event and another account still get their rows; a minute later this one does again.
    $this->deleteJson(route('two-factor.disable'))->assertOk();
    expect(m3Audit('two_factor.disabled'))->toHaveCount(1);

    m3TotpStaff(RoleName::Admin);
    $this->postJson(route('two-factor.enable'))->assertOk();
    expect(m3Audit('two_factor.enabled'))->toHaveCount($max + 1);

    $this->travel(61)->seconds();

    $this->actingAs($staff)->postJson(route('two-factor.enable'))->assertOk();
    expect(m3Audit('two_factor.enabled'))->toHaveCount($max + 2);
});

it('mirrors the two-factor audit rows into the security log', function (): void {
    config(['logging.channels.security' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    Log::forgetChannel('security');

    $staff = m3TotpStaff();
    $this->postJson(route('two-factor.enable'))->assertOk();
    $this->deleteJson(route('two-factor.disable'))->assertOk();

    $handler = collect(Log::channel('security')->getLogger()->getHandlers())->first(fn ($h) => $h instanceof TestHandler);
    $records = collect($handler->getRecords())->keyBy(fn (LogRecord $r): string => $r->message);

    expect($records->keys()->all())->toContain('security.audit.two_factor.enabled', 'security.audit.two_factor.disabled')
        ->and($records['security.audit.two_factor.disabled']->context)->toMatchArray(['log' => 'auth', 'causer_id' => $staff->getKey(), 'subject_type' => 'User']);
});
