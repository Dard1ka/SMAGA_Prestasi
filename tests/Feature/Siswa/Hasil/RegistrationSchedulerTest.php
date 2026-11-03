<?php

declare(strict_types=1);

use App\Enums\RegistrationStatus;
use App\Notifications\Reports\ReportWindowOpened;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    F::boot();

    $this->nadia = F::student();
    $this->competition = F::competition(); // starts 21 Sep, final 24 Sep 2026
});

afterEach(fn () => F::resetToday());

it('moves surat_terbit to berlangsung on starts_on and berlangsung to menunggu_laporan after final_on', function (): void {
    $registration = F::registration($this->nadia, RegistrationStatus::SuratTerbit, $this->competition);

    F::today('2026-09-20');
    $this->artisan('sth:registrations:advance')->assertSuccessful();
    expect($registration->fresh()->status)->toBe(RegistrationStatus::SuratTerbit);

    F::today('2026-09-21');
    $this->artisan('sth:registrations:advance')->assertSuccessful();
    expect($registration->fresh()->status)->toBe(RegistrationStatus::Berlangsung);

    F::today('2026-09-24'); // final day: still running
    $this->artisan('sth:registrations:advance')->assertSuccessful();
    expect($registration->fresh()->status)->toBe(RegistrationStatus::Berlangsung);
    Notification::assertNothingSent();

    F::today('2026-09-25');
    $this->artisan('sth:registrations:advance')->assertSuccessful();
    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguLaporan);

    // D-151: no due date in the notice any more.
    Notification::assertSentTo($this->nadia->user, ReportWindowOpened::class, fn (ReportWindowOpened $n): bool => $n->registrationId === $registration->getKey());

    $events = Activity::query()->where('subject_id', $registration->getKey())->whereIn('event', ['registration.berlangsung', 'registration.menunggu_laporan'])->orderBy('id')->pluck('event')->all();
    expect($events)->toBe(['registration.berlangsung', 'registration.menunggu_laporan']);
});

it('is idempotent and moves a lomba whose final has passed two steps in one run', function (): void {
    $late = F::registration($this->nadia, RegistrationStatus::SuratTerbit, $this->competition);
    $other = F::registration(F::student('Dimas Arya Nugroho'), RegistrationStatus::Disetujui, $this->competition);

    F::today('2026-10-01');
    $this->artisan('sth:registrations:advance')->assertSuccessful();
    $this->artisan('sth:registrations:advance')->assertSuccessful();

    expect($late->fresh()->status)->toBe(RegistrationStatus::MenungguLaporan)
        ->and($other->fresh()->status)->toBe(RegistrationStatus::Disetujui); // no letters yet: TU first

    Notification::assertSentToTimes($this->nadia->user, ReportWindowOpened::class, 1);
    expect(Activity::query()->where('subject_id', $late->getKey())->where('event', 'registration.menunggu_laporan')->count())->toBe(1);
});

it('rejects a malformed --date', function (): void {
    $this->artisan('sth:registrations:advance', ['--date' => '01-10-2026'])->assertFailed();
    $this->artisan('sth:reports:remind', ['--date' => '2026-13-40'])->assertFailed();
});

it('registers both jobs daily in Asia/Jakarta without overlapping', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $e): bool => str_contains((string) $e->command, 'sth:registrations:advance') || str_contains((string) $e->command, 'sth:reports:remind'));

    expect($events)->toHaveCount(2);
    $events->each(function (Event $e): void {
        expect($e->timezone)->toBe('Asia/Jakarta')
            ->and($e->withoutOverlapping)->toBeTrue()
            ->and($e->expression)->toMatch('/^\d+ \d+ \* \* \*$/');
    });
});
