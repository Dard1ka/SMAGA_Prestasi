<?php

declare(strict_types=1);

use App\Actions\Registrations\AwaitReport;
use App\Actions\Registrations\StartCompetition;
use App\Enums\RegistrationStatus;
use App\Notifications\Reports\ReportWindowOpened;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

/*
 * B3 · backlog #14: the timeline transitions of `sth:registrations:advance` as Actions
 * (StartCompetition, AwaitReport). The command itself stays covered by Siswa/Hasil/RegistrationSchedulerTest.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    F::boot();

    $this->nadia = F::student();
    $this->competition = F::competition(); // starts 21 Sep, final 24 Sep 2026 → report due 8 Nov 2026
});

afterEach(fn () => F::resetToday());

it('starts the lomba only from surat_terbit and only from starts_on, audited as the scheduler', function (): void {
    $registration = F::registration($this->nadia, RegistrationStatus::SuratTerbit, $this->competition);
    $start = app(StartCompetition::class);

    expect($start->handle($registration, CarbonImmutable::parse('2026-09-20')))->toBeNull()
        ->and($start->due(CarbonImmutable::parse('2026-09-20'))->count())->toBe(0)
        ->and($start->due(CarbonImmutable::parse('2026-09-21'))->pluck('id')->all())->toBe([$registration->getKey()]);

    $moved = $start->handle((string) $registration->getKey(), CarbonImmutable::parse('2026-09-21'));

    expect($moved?->status)->toBe(RegistrationStatus::Berlangsung)
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::Berlangsung)
        ->and($start->handle($registration, CarbonImmutable::parse('2026-09-22')))->toBeNull(); // idempotent

    $audit = Activity::query()->where('event', 'registration.berlangsung')->firstOrFail();
    expect($audit->causer_id)->toBeNull()
        ->and($audit->properties->only(['from', 'to', 'by'])->all())->toBe(['from' => 'surat_terbit', 'to' => 'berlangsung', 'by' => 'scheduler']);
    Notification::assertNothingSent();
});

it('never moves registrations in other states', function (): void {
    $approved = F::registration($this->nadia, RegistrationStatus::Disetujui, $this->competition);
    $cancelled = F::registration(F::student('Dimas Arya Nugroho'), RegistrationStatus::Dibatalkan, $this->competition);
    $day = CarbonImmutable::parse('2026-10-01');

    expect(app(StartCompetition::class)->handle($approved, $day))->toBeNull()
        ->and(app(AwaitReport::class)->handle($cancelled, $day))->toBeNull()
        ->and(app(AwaitReport::class)->handle('01J0000000000000000000NONE', $day))->toBeNull()
        ->and($approved->fresh()->status)->toBe(RegistrationStatus::Disetujui)
        ->and($cancelled->fresh()->status)->toBe(RegistrationStatus::Dibatalkan);
});

it('opens the report window the day after the final and tells the ketua the due date', function (): void {
    $registration = F::registration($this->nadia, RegistrationStatus::Berlangsung, $this->competition, [F::student('Dimas Arya Nugroho')]);
    $await = app(AwaitReport::class);

    expect($await->handle($registration, CarbonImmutable::parse('2026-09-24')))->toBeNull(); // final day: still running
    Notification::assertNothingSent();

    expect($await->handle($registration, CarbonImmutable::parse('2026-09-25'))?->status)->toBe(RegistrationStatus::MenungguLaporan);

    Notification::assertSentTo($this->nadia->user, ReportWindowOpened::class, fn (ReportWindowOpened $n): bool => $n->dueOn === '8 Nov 2026'
        && $n->registrationId === $registration->getKey());
    Notification::assertCount(1); // only the ketua reports
    expect(Activity::query()->where('event', 'registration.menunggu_laporan')->count())->toBe(1);
});

it('keeps the advance command thin: same result through the Actions', function (): void {
    $registration = F::registration($this->nadia, RegistrationStatus::SuratTerbit, $this->competition);

    $this->artisan('sth:registrations:advance', ['--date' => '2026-10-01'])
        ->expectsOutput('Berlangsung: 1 · menunggu laporan: 1')
        ->assertSuccessful();

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguLaporan);
});
