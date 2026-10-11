<?php

declare(strict_types=1);

use App\Actions\Exports\AchievementFilters;
use App\Actions\Exports\ExportAchievements;
use App\Enums\Bidang;
use App\Enums\EventStatus;
use App\Enums\Level;
use App\Enums\OrganizerType;
use App\Enums\ReportFileKind;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Livewire\Kesiswaan\DatabasePrestasi;
use App\Models\Achievement;
use App\Models\File;
use App\Models\ReportFile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Feature\Kesiswaan\KesiswaanFixtures;
use Tests\Feature\Kesiswaan\Prestasi\PrestasiFixtures;

/*
| B4 · backlog #4: Kesiswaan › Database prestasi (kesiswaan.prestasi.index): filters, totals, detail drawer,
| audited Excel export with formula escaping (SECURITY §3f), achievement.view_all / achievement.export only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));
    KesiswaanFixtures::boot();

    $f = $this->f = new PrestasiFixtures;
    $this->kesiswaan = KesiswaanFixtures::staff(RoleName::Kesiswaan, 'Ibu Dwi Rahayu');
    $this->retno = KesiswaanFixtures::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $this->agus = KesiswaanFixtures::staff(RoleName::Guru, 'Agus Setiawan, S.Or.');

    // Kelas XI in 2026/2027 → angkatan 2025; XII → 2024 (grade12_start_date from the student factory).
    $this->nadia = KesiswaanFixtures::student('Nadia Putri Anindya', 'XI-4', '225226201');
    $this->dimas = KesiswaanFixtures::student('Dimas Arya Nugroho', 'XI-4', '225226202');
    $this->raka = KesiswaanFixtures::student('Raka Pradipta', 'XII-2', '224225101');
    $this->fajar = KesiswaanFixtures::student('Fajar Ramadhan', 'XII-1', '224225102');

    $wice = $f->lomba('WICE 2026', Bidang::Riset, Level::Nasional, '2026-09-24', [
        'organizer_type' => OrganizerType::PerusahaanSwasta, 'event_status' => EventStatus::NonPemerintah]);
    $opsi = $f->lomba('OPSI 2025', Bidang::Riset, Level::Nasional, '2025-09-01');
    $o2sn = $f->lomba('O2SN Provinsi 2026', Bidang::Olahraga, Level::Provinsi, '2026-05-01', [
        'organizer_type' => OrganizerType::PemerintahDaerah, 'event_status' => EventStatus::PemerintahNonPuspresnas]);

    $f->prestasi([$this->nadia, $this->dimas], $wice, ResultType::Juara2, $this->retno);  // tim: 68 + 68
    $f->prestasi([$this->raka], $opsi, ResultType::Juara1, $this->retno);                 // 80 (2025/2026)
    $f->prestasi([$this->fajar], $o2sn, ResultType::Finalis, $this->agus);                // 60 × 0,50 = 30
});

// Access ------------------------------------------------------------------------------------------

it('opens for Kesiswaan and Admin only', function (): void {
    $this->get(route('kesiswaan.prestasi.index'))->assertRedirect(route('login'));

    $this->actingAs($this->kesiswaan)->get(route('kesiswaan.prestasi.index'))->assertOk()->assertSee('WICE 2026');
    $this->actingAs(KesiswaanFixtures::staff(RoleName::Admin, 'Operator Sekolah'))->get(route('kesiswaan.prestasi.index'))->assertOk();

    foreach ([RoleName::Guru, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas] as $role) {
        $this->actingAs(KesiswaanFixtures::staff($role, 'Staf '.$role->value))->get(route('kesiswaan.prestasi.index'))->assertForbidden();
    }

    $this->actingAs($this->nadia->user)->get(route('kesiswaan.prestasi.index'))->assertForbidden();
});

it('grants the database and the export through the policy', function (): void {
    $kepsek = KesiswaanFixtures::staff(RoleName::KepalaSekolah, 'Kepala Sekolah');
    $humas = KesiswaanFixtures::staff(RoleName::Humas, 'Humas');

    expect($this->kesiswaan->can('viewDatabase', Achievement::class))->toBeTrue()
        ->and($this->kesiswaan->can('export', Achievement::class))->toBeTrue()
        // Kepala Sekolah holds achievement.export since B5 (kepsek.laporan) but never reaches this route (role middleware).
        ->and($kepsek->can('viewDatabase', Achievement::class))->toBeTrue()
        ->and($humas->can('viewDatabase', Achievement::class))->toBeTrue()
        ->and($humas->can('export', Achievement::class))->toBeFalse()
        ->and($this->retno->can('viewDatabase', Achievement::class))->toBeFalse()
        ->and($this->nadia->user->can('viewDatabase', Achievement::class))->toBeFalse();

    $this->kesiswaan->forceFill(['status' => 'disabled'])->save();
    expect($this->kesiswaan->fresh()->can('viewDatabase', Achievement::class))->toBeFalse();
});

// List, totals, filters -----------------------------------------------------------------------------

it('lists every verified achievement with totals and the NIS in full (D-131)', function (): void {
    Livewire::actingAs($this->kesiswaan)->test(DatabasePrestasi::class)
        ->assertSee('Nadia Putri Anindya')
        ->assertSee('Dimas Arya Nugroho')
        ->assertSee('OPSI 2025')
        ->assertSee('225226201')
        ->assertDontSee('2252•••01')
        ->assertViewHas('totals', fn (array $t): bool => $t === ['count' => '4', 'students' => '4', 'points' => '246', 'podium' => '3']);
});

it('applies every filter', function (array $input, int $expected): void {
    $input = array_map(fn ($value) => $value instanceof Closure ? $value($this) : $value, $input);
    $filters = AchievementFilters::fromInput($input);

    expect($filters->apply(Achievement::query())->count())->toBe($expected);
})->with([
    'tahun ajaran' => [['tahun' => '2025/2026'], 2],
    'tahun ajaran (lalu)' => [['tahun' => '2024/2025'], 0],
    'siswa nama' => [['q' => 'nadia'], 1],
    'siswa NIS' => [['q' => '224225102'], 1],
    'kelas' => [['kelas' => fn ($t) => (string) $t->nadia->classroom_id], 2],
    'angkatan' => [['angkatan' => '2025'], 2],
    'bidang' => [['bidang' => 'riset'], 3],
    'tingkat' => [['tingkat' => 'provinsi'], 1],
    'jenis penyelenggara' => [['jenis' => 'perusahaan_swasta'], 2],
    'status ajang' => [['ajang' => 'puspresnas'], 1],
    'pembimbing' => [['pembimbing' => fn ($t) => (string) $t->retno->getKey()], 3],
    'individu/tim' => [['tipe' => 'tim'], 2],
    'capaian' => [['capaian' => 'finalis'], 1],
    'gabungan' => [['bidang' => 'riset', 'tipe' => 'individu', 'pembimbing' => fn ($t) => (string) $t->retno->getKey()], 1],
]);

it('ignores invalid filter values instead of failing', function (): void {
    $filters = AchievementFilters::fromInput([
        'tahun' => '2025-2026', 'kelas' => 'x', 'angkatan' => '19', 'bidang' => 'sulap', 'tingkat' => 'galaksi',
        'jenis' => '=1+1', 'ajang' => 'x', 'pembimbing' => '99999999', 'tipe' => 'grup', 'capaian' => 'juara_9', 'q' => '   ',
    ]);

    expect($filters->isEmpty())->toBeTrue()
        ->and($filters->apply(Achievement::query())->count())->toBe(4);

    Livewire::actingAs($this->kesiswaan)->withQueryParams(['bidang' => 'sulap', 'tahun' => 'x'])->test(DatabasePrestasi::class)
        ->assertSet('bidang', '')
        ->assertSet('tahun', '');
});

it('filters the screen and the totals together and resets them', function (): void {
    Livewire::actingAs($this->kesiswaan)->test(DatabasePrestasi::class)
        ->set('bidang', Bidang::Olahraga->value)
        ->assertSee('Fajar Ramadhan')
        ->assertDontSee('Nadia Putri Anindya')
        ->assertSee('Bidang: Olahraga')
        ->assertViewHas('totals', fn (array $t): bool => $t['count'] === '1' && $t['points'] === '30')
        ->set('capaian', ResultType::Juara1->value)
        ->assertSee('Tidak ada prestasi')
        ->call('resetFilters')
        ->assertSet('bidang', '')
        ->assertSet('capaian', '')
        ->assertSee('Nadia Putri Anindya');
});

// Detail drawer ---------------------------------------------------------------------------------------

it('opens the detail drawer with the formula, team and pembimbing', function (): void {
    $achievement = Achievement::query()->where('student_id', $this->nadia->getKey())->firstOrFail();
    $file = File::factory()->create(['original_name' => 'sertifikat-wice-2026.pdf']);
    ReportFile::factory()->create(['report_id' => $achievement->report_id, 'kind' => ReportFileKind::Sertifikat, 'file_id' => $file->getKey()]);

    Livewire::actingAs($this->kesiswaan)->test(DatabasePrestasi::class)
        ->call('showDetail', (string) $achievement->getKey())
        ->assertDispatched('sth-modal-open', id: DatabasePrestasi::DRAWER)
        ->assertSet('detailId', (string) $achievement->getKey())
        ->assertSee('Rumus poin')
        ->assertSee('Retno Wulandari, S.Pd.')
        ->assertSee('Dimas Arya Nugroho')
        ->assertSee('Kategori A')
        ->assertSee('sertifikat-wice-2026.pdf')
        ->assertViewHas('detail', fn (array $d): bool => $d['pointsLabel'] === '68' && $d['tipe'] === 'Tim' && $d['rumus']['total'] === '68.00');
});

it('answers 404 for an unknown achievement and keeps the detail id locked', function (): void {
    Livewire::actingAs($this->kesiswaan)->test(DatabasePrestasi::class)
        ->call('showDetail', '01HZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertNotFound();

    expect(fn () => Livewire::actingAs($this->kesiswaan)->test(DatabasePrestasi::class)->set('detailId', 'x'))
        ->toThrow(Exception::class);
});

// Export ----------------------------------------------------------------------------------------------

it('asks for the password again before exporting', function (): void {
    Livewire::actingAs($this->kesiswaan)->test(DatabasePrestasi::class)
        ->call('export')
        ->assertRedirect(route('password.confirm'));

    expect(Activity::query()->where('event', 'achievement.exported')->exists())->toBeFalse();
});

it('downloads the filtered list as Excel after a recent password confirmation', function (): void {
    session()->put('auth.password_confirmed_at', time());

    Livewire::actingAs($this->kesiswaan)->test(DatabasePrestasi::class)
        ->set('bidang', Bidang::Riset->value)
        ->call('export')
        ->assertFileDownloaded();

    $log = Activity::query()->where('event', 'achievement.exported')->sole();

    expect((int) $log->causer_id)->toBe((int) $this->kesiswaan->getKey())
        ->and($log->properties['rows'])->toBe(3)
        ->and($log->properties['filters'])->toBe(['bidang' => 'riset'])
        ->and($log->properties['large'])->toBeFalse();
});

it('writes a watermarked, formula-safe sheet without NISN', function (): void {
    $this->nadia->user->forceFill(['name' => '=HYPERLINK("http://x","klik")'])->save();
    $evil = $this->f->lomba('@SUM(1+1)', Bidang::Sains, Level::Kota, '2026-02-01');
    $this->f->prestasi([$this->raka], $evil, ResultType::Peserta);

    $file = app(ExportAchievements::class)->handle($this->kesiswaan, AchievementFilters::fromInput([]));

    try {
        $rows = SimpleExcelReader::create($file->path)->noHeaderRow()->getRows()->all();
        $zip = new ZipArchive;
        $zip->open($file->path);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
    } finally {
        @unlink($file->path);
    }

    $cells = collect($rows)->flatten()->map(fn ($value) => (string) $value);
    $header = collect($rows)->first(fn (array $row): bool => ($row[0] ?? null) === 'No');

    expect($file->rows)->toBe(5)
        ->and($file->filename)->toStartWith('database-prestasi-')
        ->and($rows[0][0])->toBe('Database prestasi SMAGA Talent Hub')
        ->and($rows[1][0])->toContain('Diekspor oleh Ibu Dwi Rahayu')
        ->and($rows[2][0])->toBe('Filter: semua prestasi terverifikasi')
        ->and($header)->toContain('NIS')
        ->and($header)->not->toContain('NISN')
        ->and($cells)->toContain('\'=HYPERLINK("http://x","klik")')
        ->and($cells)->toContain('\'@SUM(1+1)')
        ->and($cells)->toContain('225226201')
        ->and($cells->contains(fn (string $v): bool => str_contains($v, (string) $this->nadia->nisn)))->toBeFalse()
        ->and($sheet)->not->toContain('<f>');
});

it('refuses the export without achievement.export', function (): void {
    $humas = KesiswaanFixtures::staff(RoleName::Humas, 'Humas');

    expect(fn () => app(ExportAchievements::class)->handle($humas, AchievementFilters::fromInput([])))
        ->toThrow(AuthorizationException::class);

    expect(Activity::query()->where('event', 'achievement.exported')->exists())->toBeFalse();
});

it('escapes every leading formula character', function (string $value): void {
    expect(ExportAchievements::cell($value))->toBe("'".$value)
        ->and(ExportAchievements::cell('Nadia '.$value))->toBe('Nadia '.$value);
})->with(['=1+1', '+62812', '-5', '@SUM(A1)', "\tx", "\rx", "\nx"]);
