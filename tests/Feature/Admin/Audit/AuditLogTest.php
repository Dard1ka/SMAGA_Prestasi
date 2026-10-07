<?php

declare(strict_types=1);

use App\Actions\Registrations\AwaitReport;
use App\Actions\Registrations\StartCompetition;
use App\Actions\Reports\ExportAuditLog;
use App\Actions\Reports\ExportCells;
use App\Enums\RoleName;
use App\Livewire\Admin\AuditLog;
use App\Models\User;
use App\Notifications\Admin\LargeExportNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    Notification::fake();
    $this->withoutVite();
    $this->admin = A::admin();
    $this->tu = A::staff(RoleName::TataUsaha, ['name' => 'Ibu Siti Rahmawati']);
    Activity::query()->delete(); // test DB only: drop the model events of the fixtures above

    $this->travelTo('2026-09-28 10:00:00');
    activity('account')->causedBy($this->admin)->performedOn($this->tu)->event('account.staff_reset')
        ->withProperties(['reason' => 'HP hilang.'])->log('account.staff_reset');

    $this->travelTo('2026-09-30 10:00:00');
    activity('letter')->causedBy($this->tu)->event('letters.issued')
        ->withProperties(['letters' => [['number' => '400.3.8/313/MRD/IX/2026']]])->log('letters.issued');

    $this->travelTo('2026-10-01 01:00:00');
    activity('account')->performedOn($this->tu)->event('account.archived')
        ->withProperties(['reason' => '=HYPERLINK("https://contoh.test")', 'automatic' => true])->log('account.archived');

    $this->travelTo('2026-10-01 09:00:00');
});

function b5AuditRows(array $filters): array
{
    return ExportAuditLog::query($filters)->orderBy('id')->pluck('event')->all();
}

// Filters --------------------------------------------------------------------------------------------

it('filters by actor, system, event, subject type and date range', function (): void {
    expect(b5AuditRows([]))->toBe(['account.staff_reset', 'letters.issued', 'account.archived'])
        ->and(b5AuditRows(['pelaku' => (string) $this->tu->id]))->toBe(['letters.issued'])
        ->and(b5AuditRows(['pelaku' => 'sistem']))->toBe(['account.archived'])
        ->and(b5AuditRows(['peristiwa' => 'account.staff_reset']))->toBe(['account.staff_reset'])
        ->and(b5AuditRows(['subjek' => 'user']))->toBe(['account.staff_reset', 'account.archived'])
        ->and(b5AuditRows(['dari' => '2026-09-29', 'sampai' => '2026-09-30']))->toBe(['letters.issued'])
        ->and(b5AuditRows(['dari' => '2026-09-30', 'sampai' => '2026-09-29']))->toBe(['letters.issued'])
        ->and(b5AuditRows(['q' => 'Rahmawati']))->toBe(['letters.issued']);
});

it('ignores malformed filter values', function (): void {
    expect(ExportAuditLog::normalize(['pelaku' => '1 OR 1=1', 'peristiwa' => "x'; DROP", 'subjek' => '../User', 'dari' => '2026-02-31', 'sampai' => 'kemarin']))
        ->toBe(['pelaku' => '', 'peristiwa' => '', 'subjek' => '', 'dari' => '', 'sampai' => '', 'q' => ''])
        ->and(b5AuditRows(['pelaku' => 'abc']))->toHaveCount(3);
});

it('maps subject types to short keys with Indonesian labels', function (): void {
    expect(ExportAuditLog::subjectTypes())->toBe(['user' => ['class' => User::class, 'label' => 'Akun']]);
});

// Screen ---------------------------------------------------------------------------------------------

it('shows the log read-only to an Admin, newest first, with filters from the URL', function (): void {
    $this->actingAs($this->admin)->get(route('admin.audit.index'))
        ->assertOk()
        ->assertSeeInOrder(['Akun diarsipkan', 'Surat diterbitkan', 'Akun staf direset'])
        ->assertSee('HP hilang.')
        ->assertDontSee('Hapus log');

    $this->actingAs($this->admin)->get(route('admin.audit.index', ['peristiwa' => 'letters.issued']))
        ->assertOk()
        ->assertSee('Surat diterbitkan')
        ->assertDontSee('HP hilang.');
});

it('denies the audit log to every role without audit.view', function (RoleName $role): void {
    $this->actingAs(A::staff($role))->get(route('admin.audit.index'))->assertForbidden();
    expect(fn () => app(ExportAuditLog::class)->handle(A::staff($role, ['username' => 'lain.'.$role->value]), []))->toThrow(AuthorizationException::class);
})->with([RoleName::Kesiswaan, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas, RoleName::Guru]);

// Export ---------------------------------------------------------------------------------------------

it('exports the filtered rows as formula-safe CSV and audits the export', function (): void {
    $result = app(ExportAuditLog::class)->handle($this->admin, ['pelaku' => 'sistem']);
    $csv = (string) file_get_contents($result['path']);

    expect($result['rows'])->toBe(1)
        ->and($result['filename'])->toEndWith('.csv')
        ->and($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('account.archived')
        ->and($csv)->not->toContain('letters.issued')
        ->and($csv)->toContain('Sistem');

    $log = Activity::query()->where('event', 'audit.exported')->sole();
    expect($log->causer_id)->toBe($this->admin->id)
        ->and($log->properties['rows'])->toBe(1)
        ->and($log->properties['filters'])->toBe(['pelaku' => 'sistem'])
        ->and($log->properties['sha256'])->toBe(hash_file('sha256', $result['path']));

    @unlink($result['path']);
    Notification::assertNothingSent();
});

it('prefixes spreadsheet formulas in CSV cells', function (): void {
    expect(ExportCells::csv('=HYPERLINK("x")'))->toBe("'=HYPERLINK(\"x\")")
        ->and(ExportCells::csv('+62 812'))->toBe("'+62 812")
        ->and(ExportCells::csv('-1'))->toBe("'-1")
        ->and(ExportCells::csv('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(ExportCells::csv("\tx"))->toBe("'\tx")
        ->and(ExportCells::csv('Nadia Putri Anindya'))->toBe('Nadia Putri Anindya')
        ->and(ExportCells::csv(['reason' => '=1+1']))->toStartWith('{')
        ->and(ExportCells::csv(null))->toBe('');
});

it('alerts every Admin when an export has more than 500 rows', function (): void {
    for ($i = 0; $i < 501; $i++) {
        activity('setting')->causedBy($this->admin)->event('settings.public_updated')->log('settings.public_updated');
    }

    $result = app(ExportAuditLog::class)->handle($this->admin, ['peristiwa' => 'settings.public_updated']);
    @unlink($result['path']);

    expect($result['rows'])->toBe(501);
    Notification::assertSentTo($this->admin, LargeExportNotice::class);
});

it('asks for the password before the CSV download from the screen', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(AuditLog::class)->call('export')->assertRedirect(route('password.confirm'));
    expect(Activity::query()->where('event', 'audit.exported')->count())->toBe(0);

    session(A::passwordConfirmed());
    Livewire::test(AuditLog::class)->set('pelaku', 'sistem')->call('export')->assertFileDownloaded();
    expect(Activity::query()->where('event', 'audit.exported')->count())->toBe(1);
});

// QA5 retest (2026-10-07, backlog #64): every event the app writes has an Indonesian label --------------------

/** Event keys the app passes to ->event(): the quoted ones and the EVENT_* constants of the Actions. */
function qa5AuditEventKeys(): array
{
    $keys = [];

    foreach (File::allFiles(app_path()) as $file) {
        $code = $file->getContents();

        preg_match_all('/->event\(([^;]*?)\)\s*(?:->|;)/s', $code, $calls);
        foreach ($calls[1] as $argument) {
            preg_match_all("/'([a-z_]+\.[a-z_.]+)'/", $argument, $quoted);
            array_push($keys, ...$quoted[1]);
        }

        preg_match_all("/const EVENT_[A-Z_]+ = '([a-z_]+\.[a-z_.]+)'/", $code, $constants);
        array_push($keys, ...$constants[1]);
    }

    // Built at runtime from a variable: SetNewPassword's reasons, FileController, SchedulePublication, the scheduled
    // TimelineTransition steps ('registration.'.$to->value) and the model event of a restored soft-deleted row.
    array_push($keys, 'password.changed', 'account.password_reset', 'file.downloaded', 'file.public_downloaded',
        'publication.scheduled', 'publication.rescheduled', 'restored');
    foreach ([StartCompetition::class, AwaitReport::class] as $step) {
        $keys[] = 'registration.'.app($step)->to()->value;
    }

    return array_values(array_unique($keys));
}

it('has a label for every event the app writes to the audit log', function (): void {
    $keys = qa5AuditEventKeys();

    expect(count($keys))->toBeGreaterThan(60)
        ->and(array_values(array_diff($keys, array_keys(AuditLog::EVENT_LABELS))))->toBe([]);
});

it('never prints an event key twice when a row has no label', function (): void {
    activity('talent')->causedBy($this->admin)->event('talent.applied')->log('talent.applied');
    activity('file')->causedBy($this->tu)->event('file.downloaded')->log('file.downloaded');

    $this->actingAs($this->admin)->get(route('admin.audit.index'))
        ->assertOk()
        ->assertSee('Siswa mendaftar talenta')
        ->assertSee('Berkas di-download')
        ->assertDontSee('file.downloaded file.downloaded');
});
