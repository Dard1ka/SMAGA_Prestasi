<?php

declare(strict_types=1);

use App\Actions\Reports\ExportPeriod;
use App\Actions\Reports\ExportPrestasiData;
use App\Actions\Reports\ExportPrestasiReport;
use App\Domain\Archive\SchoolYear;
use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Kepsek\Laporan;
use App\Livewire\Kepsek\PrestasiBidang;
use App\Notifications\Admin\LargeExportNotice;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\KepalaSekolah\W2cFixtures;
use Tests\Feature\Kepsek\KepsekFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::seed();
    Notification::fake();
    $this->withoutVite();
    $this->travelTo('2026-10-01 10:00:00');
    $this->kepsek = F::kepsek();

    $this->mentor = W2cFixtures::mentor('Retno Wulandari, S.Pd.');
    $this->nadia = F::student('Nadia Putri Anindya', 'XI-4');              // no publish consent: internal pages still name her
    $this->raka = F::student('Raka Pradipta', 'XII-2');
    $this->raka->user->forceFill(['status' => UserStatus::Archived])->save();

    F::achievement($this->nadia, Bidang::Riset, Level::Nasional, ResultType::Juara2, '68.00', '2026-09-24', 'OPSI 2026', $this->mentor);
    F::achievement($this->nadia, Bidang::Sains, Level::Kota, ResultType::Juara1, '32.00', '2026-08-10', 'KRENOVA Kota Semarang 2026');
    F::achievement($this->raka, Bidang::Riset, Level::Nasional, ResultType::Juara1, '80.00', '2026-03-05', 'FIKSI 2026', $this->mentor);
});

// Data -----------------------------------------------------------------------------------------------

it('aggregates a school year per bidang, tingkat, kelas and guru pembimbing', function (): void {
    $data = app(ExportPrestasiData::class);
    $rows = $data->rows(ExportPeriod::schoolYear(SchoolYear::fromString('2026/2027')));
    $summary = $data->summary($rows);

    expect($rows)->toHaveCount(2)
        ->and($summary['totals'])->toMatchArray(['prestasi' => 2, 'siswa' => 1, 'lomba' => 2, 'nasional' => 1, 'points' => '100.00'])
        ->and(collect($summary['bidang'])->firstWhere('label', 'Riset')['prestasi'])->toBe(1)
        ->and(collect($summary['kelas'])->pluck('label')->all())->toBe(['XI-4'])
        ->and(collect($summary['pembimbing'])->pluck('label')->all())->toContain('Retno Wulandari, S.Pd.', 'Tanpa pembimbing');

    $all = $data->summary($data->rows(ExportPeriod::all()));
    expect($all['totals']['prestasi'])->toBe(3)
        ->and(collect($all['kelas'])->pluck('label')->all())->toBe(['XI-4', 'XII-2']);

    $range = $data->rows(ExportPeriod::range(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30')));
    expect($range->pluck('lomba')->all())->toBe(['OPSI 2026']);
});

// Screens --------------------------------------------------------------------------------------------

it('opens the three Kepala Sekolah screens', function (): void {
    $this->actingAs($this->kepsek);

    $this->get(route('kepsek.prestasi', ['bidang' => 'riset']))->assertOk()->assertSee('Nadia Putri Anindya')->assertSee('Raka Pradipta')->assertDontSee($this->nadia->nis);
    $this->get(route('kepsek.laporan'))->assertOk()->assertSee('Tahun ajaran 2026/2027')->assertSee('OPSI 2026')->assertDontSee($this->nadia->nis);
    $this->get(route('kepsek.surat.index'))->assertOk();
});

it('lets Admin open the laporan (linked from the shared dashboard) but not the other Kepala Sekolah screens', function (): void {
    $admin = A::admin();

    $this->actingAs($admin)->get(route('kepsek.laporan'))->assertOk();
    $this->actingAs($admin)->get(route('kepsek.prestasi'))->assertForbidden();
    $this->actingAs($admin)->get(route('kepsek.surat.index'))->assertForbidden();
});

it('denies the Kepala Sekolah screens to other roles', function (RoleName $role): void {
    $user = A::staff($role);

    foreach (['kepsek.prestasi', 'kepsek.laporan', 'kepsek.surat.index'] as $route) {
        $this->actingAs($user)->get(route($route))->assertForbidden();
    }
})->with([RoleName::Guru, RoleName::Kesiswaan, RoleName::TataUsaha, RoleName::Humas]);

it('sends guests to the login page', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['kepsek.prestasi', 'kepsek.laporan', 'kepsek.surat.index']);

it('drills down bidang → siswa → detail with every name and alumni marked', function (): void {
    $this->actingAs($this->kepsek);

    Livewire::test(PrestasiBidang::class)
        ->call('pilihBidang', 'riset')
        ->assertSee('Raka Pradipta')
        ->assertSee('Alumni')
        ->call('pilihSiswa', $this->nadia->id)
        ->assertSet('siswa', $this->nadia->id)
        ->assertSee('OPSI 2026')
        ->assertSee('KRENOVA Kota Semarang 2026')
        ->assertSee('Retno Wulandari, S.Pd.')
        ->set('tahun', '2025/2026')
        ->call('pilihBidang', 'riset')
        ->assertDontSee('OPSI 2026');
});

it('filters the laporan by a date range and by bidang', function (): void {
    $this->actingAs($this->kepsek);

    Livewire::test(Laporan::class)
        ->assertSee('OPSI 2026')
        ->assertDontSee('FIKSI 2026')
        ->set('tahun', 'semua')
        ->assertSee('FIKSI 2026')
        ->set('tahun', 'rentang')
        ->set('dari', '2026-03-01')
        ->set('sampai', '2026-03-31')
        ->assertSee('FIKSI 2026')
        ->assertDontSee('OPSI 2026')
        ->set('tahun', 'semua')
        ->set('bidang', 'sains')
        ->assertSee('KRENOVA Kota Semarang 2026')
        ->assertDontSee('FIKSI 2026');
});

// Export ---------------------------------------------------------------------------------------------

it('exports a watermarked XLSX with explicit string cells, no NIS, and audits it', function (): void {
    F::achievement($this->nadia, Bidang::SeniBudaya, Level::Provinsi, ResultType::Juara3, '42.00', '2026-09-01', '=HYPERLINK("https://contoh.test")');

    $result = app(ExportPrestasiReport::class)->handle($this->kepsek, 'xlsx', ExportPeriod::schoolYear(SchoolYear::fromString('2026/2027')));

    $zip = new ZipArchive;
    expect($zip->open($result['path']))->toBeTrue();
    $sheets = $zip->getFromName('xl/worksheets/sheet1.xml').$zip->getFromName('xl/worksheets/sheet2.xml');
    $core = (string) $zip->getFromName('docProps/core.xml');
    $zip->close();
    @unlink($result['path']);

    expect($result['rows'])->toBe(3)
        ->and($result['filename'])->toEndWith('.xlsx')
        ->and($sheets)->toContain('Diunduh oleh Dra. Sri Wahyuni, M.Pd. (kepsek)')
        ->and($sheets)->toContain('Nadia Putri Anindya')
        ->and($sheets)->toContain('=HYPERLINK')
        ->and($sheets)->not->toContain('<f>')
        ->and($sheets)->not->toContain($this->nadia->nis)
        ->and($core)->toContain('Dra. Sri Wahyuni, M.Pd.');

    $log = Activity::query()->where('event', 'school_report.exported')->sole();
    expect($log->causer_id)->toBe($this->kepsek->id)
        ->and($log->properties['format'])->toBe('xlsx')
        ->and($log->properties['rows'])->toBe(3)
        ->and($log->properties['period']['label'])->toBe('Tahun ajaran 2026/2027')
        ->and($log->properties['watermark'])->toContain('Dra. Sri Wahyuni, M.Pd.');
    Notification::assertNotSentTo($this->kepsek, LargeExportNotice::class);
});

it('exports a PDF through the locked-down renderer and audits it', function (): void {
    $result = app(ExportPrestasiReport::class)->handle($this->kepsek, 'pdf', ExportPeriod::all(), Bidang::Riset);
    $bytes = (string) file_get_contents($result['path']);
    @unlink($result['path']);

    expect($bytes)->toStartWith('%PDF-')
        ->and($result['rows'])->toBe(2)
        ->and($result['filename'])->toContain('-riset-')
        ->and(Activity::query()->where('event', 'school_report.exported')->sole()->properties['sha256'])->toBe(hash('sha256', $bytes));
});

it('lets only accounts with the school report abilities export', function (RoleName $role): void {
    expect(fn () => app(ExportPrestasiReport::class)->handle(A::staff($role), 'pdf', ExportPeriod::all()))
        ->toThrow(AuthorizationException::class);
})->with([RoleName::Guru, RoleName::TataUsaha, RoleName::Humas]);

it('asks for the password before downloading from the screen', function (): void {
    $this->actingAs($this->kepsek);

    Livewire::test(Laporan::class)->call('export', 'xlsx')->assertRedirect(route('password.confirm'));
    expect(Activity::query()->where('event', 'school_report.exported')->count())->toBe(0);

    session(A::passwordConfirmed());
    Livewire::test(Laporan::class)->call('export', 'xlsx')->assertFileDownloaded();
    expect(Activity::query()->where('event', 'school_report.exported')->count())->toBe(1);
});
