<?php

declare(strict_types=1);

use App\Console\Commands\DemoLombaCommand;
use App\Enums\Bidang;
use App\Enums\CompetitionStatus;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Competition;
use App\Models\User;
use App\Notifications\Reports\ReportWindowOpened;
use Carbon\CarbonImmutable;
use Database\Seeders\ClassroomSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\TalentSeeder;
use Database\Seeders\WeightSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

/*
 | smaga_server (the lead, 2026-11-05): `sth:lomba:demo` makes a presentation lomba whose registration, lomba day and
 | report all fall on today, and `--majukan` moves only that lomba's registrations past the surat tugas to the report
 | step at once (the daily timeline job would do it the day after the final). Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, ClassroomSeeder::class, TalentSeeder::class, WeightSeeder::class, SettingSeeder::class]);
    Notification::fake();
    F::today('2026-11-05');
});

afterEach(fn () => F::resetToday());

function dlcAdmin(): User
{
    $admin = AuthTestHelpers::staff(RoleName::Admin, ['name' => 'Admin Operator STH', 'email' => 'admin-operator-sth@example.test']);
    $admin->forceFill(['status' => UserStatus::Active])->save();

    return $admin->fresh();
}

function dlcDemo(): Competition
{
    return Competition::query()->where('name', DemoLombaCommand::nameFor(CarbonImmutable::today()))->sole();
}

it('creates today\'s demo lomba: published, open for registration until 23:59, the lomba day today', function (): void {
    $admin = dlcAdmin();

    $this->artisan('sth:lomba:demo')
        ->expectsOutputToContain('Lomba demo dibuat dan dipublikasikan: Lomba Demo Presentasi STH 5 November 2026 (Contoh).')
        ->expectsOutputToContain('php artisan sth:lomba:demo --majukan')
        ->assertSuccessful();

    $demo = dlcDemo();

    expect($demo->status)->toBe(CompetitionStatus::Dipublikasikan)
        ->and($demo->bidang)->toBe(Bidang::Sains)
        ->and($demo->submitted_by)->toBe($admin->getKey())
        ->and($demo->verified_by)->toBe($admin->getKey())
        ->and($demo->juknis_file_id)->not->toBeNull()
        ->and($demo->registration_closes_at?->format('Y-m-d H:i:s'))->toBe('2026-11-05 23:59:59')
        ->and($demo->starts_on?->toDateString())->toBe('2026-11-05')
        ->and($demo->final_on?->toDateString())->toBe('2026-11-05')
        ->and($demo->isOpenForRegistration(CarbonImmutable::parse('2026-11-05 09:00', 'Asia/Jakarta')))->toBeTrue()
        ->and($demo->isOpenForRegistration(CarbonImmutable::parse('2026-11-05 23:30', 'Asia/Jakarta')))->toBeTrue()
        ->and($demo->isOpenForRegistration(CarbonImmutable::parse('2026-11-06 00:01', 'Asia/Jakarta')))->toBeFalse();

    expect(Activity::query()->where('event', 'competition.published')->sole()->properties->get('source'))->toBe('demo');
    Notification::assertNothingSent();
});

it('changes nothing on a second run the same day, and the next day gets its own lomba', function (): void {
    dlcAdmin();

    $this->artisan('sth:lomba:demo')->assertSuccessful();
    $this->artisan('sth:lomba:demo')
        ->expectsOutputToContain('Lomba demo hari ini sudah ada')
        ->assertSuccessful();

    expect(Competition::query()->count())->toBe(1);

    F::today('2026-11-06');
    $this->artisan('sth:lomba:demo')->assertSuccessful();

    expect(Competition::query()->pluck('name')->sort()->values()->all())->toBe([
        'Lomba Demo Presentasi STH 5 November 2026 (Contoh)',
        'Lomba Demo Presentasi STH 6 November 2026 (Contoh)',
    ]);
});

it('writes nothing without an active Admin', function (): void {
    $this->artisan('sth:lomba:demo')
        ->expectsOutputToContain(DemoLombaCommand::NO_ADMIN)
        ->assertFailed();

    expect(Competition::query()->count())->toBe(0);
});

it('moves only the demo lomba\'s registrations whose letters were sent to the report step, at once', function (): void {
    dlcAdmin();
    $this->artisan('sth:lomba:demo')->assertSuccessful();
    $demo = dlcDemo();

    $nadia = F::student();
    $sent = F::registration($nadia, RegistrationStatus::SuratTerbit, $demo);
    $running = F::registration(F::student('Rizky Ananda Putra'), RegistrationStatus::Berlangsung, $demo);
    $approved = F::registration(F::student('Dimas Arya Nugroho'), RegistrationStatus::Disetujui, $demo);
    $otherLomba = F::competition([
        'name' => 'Lomba Lain Hari Ini',
        'registration_opens_at' => '2026-10-01 00:00:00',
        'registration_closes_at' => '2026-11-04 23:59:59',
        'starts_on' => '2026-11-05',
        'final_on' => '2026-11-05',
    ]);
    $other = F::registration(F::student('Salsabila Kirana'), RegistrationStatus::SuratTerbit, $otherLomba);

    $this->artisan('sth:lomba:demo', ['--majukan' => true])
        ->expectsOutputToContain('Lomba demo dimajukan: 2 pendaftaran sekarang bisa mengirim laporan hasil.')
        ->expectsOutputToContain('1 pendaftaran belum sampai surat tugas terkirim')
        ->assertSuccessful();

    expect($sent->fresh()->status)->toBe(RegistrationStatus::MenungguLaporan)
        ->and($running->fresh()->status)->toBe(RegistrationStatus::MenungguLaporan)
        ->and($approved->fresh()->status)->toBe(RegistrationStatus::Disetujui)
        ->and($other->fresh()->status)->toBe(RegistrationStatus::SuratTerbit);

    Notification::assertSentTo($nadia->user, ReportWindowOpened::class, fn (ReportWindowOpened $n): bool => $n->registrationId === $sent->getKey());
    expect(Activity::query()->where('event', 'registration.menunggu_laporan')->count())->toBe(2);

    // A second run finds nothing more to move.
    $this->artisan('sth:lomba:demo', ['--majukan' => true])
        ->expectsOutputToContain('Lomba demo dimajukan: 0 pendaftaran')
        ->assertSuccessful();
});

it('refuses --majukan before today\'s demo lomba exists', function (): void {
    $this->artisan('sth:lomba:demo', ['--majukan' => true])
        ->expectsOutputToContain(DemoLombaCommand::NOT_YET)
        ->assertFailed();
});

it('does nothing in production', function (): void {
    dlcAdmin();
    app()['env'] = 'production';

    try {
        $this->artisan('sth:lomba:demo')->assertExitCode(Command::INVALID);
    } finally {
        app()['env'] = 'testing';
    }

    expect(Competition::query()->count())->toBe(0);
});
