<?php

declare(strict_types=1);

use App\Actions\Reports\ExportPeriod;
use App\Actions\Reports\ExportPrestasiData;
use App\Actions\Reports\ExportPrestasiReport;
use App\Domain\Archive\SchoolYear;
use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Livewire\Kepsek\Laporan;
use App\Models\Achievement;
use App\Models\Competition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Kepsek\KepsekFixtures as F;

/*
 * QA10: Kepala Sekolah › Laporan.
 * - The PDF had no row limit: every achievement of the period went through dompdf inside the web request (production
 *   limits 30 s / 256 MB), so "Semua tahun" of a school with thousands of results ended in an error page. Above the
 *   cap the PDF is refused with a message that points to the Excel file (which has no such cost).
 * - Every redraw loaded all achievement rows twice: once for the period and once more, without a period, only to
 *   list the school years of the "Periode" select.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::seed();
    Notification::fake();
    $this->withoutVite();
    $this->travelTo('2026-10-01 10:00:00');
    $this->kepsek = F::kepsek();
});

/**
 * $count extra verified achievements in the lomba of one real achievement (final $finalOn), one fictional student
 * each, written in bulk so a test can pass the PDF cap without building thousands of registrations.
 */
function qa10LombaReportRows(int $count, string $finalOn = '2026-09-24'): Achievement
{
    $template = F::achievement(F::student('Nadia Putri Anindya'), Bidang::Sains, Level::Kota, ResultType::Peserta, '4.00', $finalOn, 'Lomba Cerdas Cermat Fiktif 2026');
    $classroomId = $template->student->classroom_id;
    $now = now()->toDateTimeString();

    foreach (array_chunk(range(1, $count), 200) as $chunk) {
        DB::table('users')->insert(array_map(fn (int $i): array => [
            'name' => 'Siswa Contoh '.$i, 'username' => 'qa10-siswa-'.$i, 'password' => 'not-a-login', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ], $chunk));
        $userIds = DB::table('users')->whereIn('username', array_map(fn (int $i): string => 'qa10-siswa-'.$i, $chunk))->pluck('id', 'username');

        $students = array_map(fn (int $i): array => [
            'id' => (string) Str::ulid(), 'user_id' => $userIds['qa10-siswa-'.$i], 'nis' => (string) (900000000 + $i), 'nisn' => (string) (9000000000 + $i),
            'birth_date' => '2010-01-01', 'classroom_id' => $classroomId, 'import_school_year' => '2026/2027', 'created_at' => $now, 'updated_at' => $now,
        ], $chunk);
        DB::table('students')->insert($students);

        DB::table('achievements')->insert(array_map(fn (array $student): array => [
            'id' => (string) Str::ulid(), 'report_id' => $template->report_id, 'student_id' => $student['id'], 'competition_id' => $template->competition_id,
            'result' => 'peserta', 'level' => 'kota', 'weight_category' => 'A', 'w_level' => '40.00', 'w_result' => '0.10', 'w_organizer' => '1.00',
            'points' => '4.00', 'weight_version_id' => $template->weight_version_id, 'verified_at' => $finalOn.' 10:00:00', 'created_at' => $now, 'updated_at' => $now,
        ], $students));
    }

    return $template;
}

it('refuses a PDF above the row cap and points to the Excel file (QA10)', function (): void {
    qa10LombaReportRows(250); // + the template row = 251 rows in 2026/2027

    try {
        app(ExportPrestasiReport::class)->handle($this->kepsek, 'pdf', ExportPeriod::schoolYear(SchoolYear::fromString('2026/2027')));
        $this->fail('A PDF above the cap was rendered.');
    } catch (ValidationException $e) {
        expect($e->errors()['export'][0])->toBe('PDF memuat paling banyak 250 prestasi, periode ini punya 251. Download Excel untuk daftar lengkap, atau persempit periode atau bidang.');
    }

    expect(ExportPrestasiReport::MAX_PDF_ROWS)->toBe(250)
        ->and(Activity::query()->where('event', 'school_report.exported')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('still downloads the Excel file of the same period (QA10)', function (): void {
    qa10LombaReportRows(250);

    $result = app(ExportPrestasiReport::class)->handle($this->kepsek, 'xlsx', ExportPeriod::schoolYear(SchoolYear::fromString('2026/2027')));
    @unlink($result['path']);

    expect($result['rows'])->toBe(251)
        ->and(Activity::query()->where('event', 'school_report.exported')->sole()->properties['format'])->toBe('xlsx');
});

it('says on the screen that the period is too large for a PDF and shows the refusal (QA10)', function (): void {
    qa10LombaReportRows(250);
    $this->actingAs($this->kepsek);
    session(A::passwordConfirmed());

    Livewire::test(Laporan::class)
        ->assertSee('PDF memuat paling banyak 250 prestasi. Download Excel untuk daftar lengkap.')
        ->call('export', 'pdf')
        ->assertHasErrors('export')
        ->assertSee('periode ini punya 251');

    expect(Activity::query()->where('event', 'school_report.exported')->exists())->toBeFalse();
});

it('loads the achievement rows once per redraw and lists the school years with a small query (QA10)', function (): void {
    F::achievement(F::student('Raka Pradipta', 'XII-B'), finalOn: '2025-03-05', lomba: 'FIKSI 2025');
    F::achievement(F::student('Salsabila Kirana'), finalOn: '2026-09-24');
    $this->actingAs($this->kepsek);

    DB::enableQueryLog();
    $component = Livewire::test(Laporan::class);
    $rowQueries = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'u_name'))->count();
    DB::disableQueryLog();

    expect($rowQueries)->toBe(1)
        ->and(array_keys($component->viewData('yearOptions')))->toBe(['2026/2027', '2024/2025', 'semua', 'rentang']);
});

it('lists the school years with achievements plus the current one, newest first (QA10)', function (): void {
    F::achievement(F::student('Raka Pradipta', 'XII-B'), finalOn: '2025-03-05', lomba: 'FIKSI 2025');
    $noFinal = F::achievement(F::student('Salsabila Kirana'), finalOn: '2023-08-10');
    Competition::query()->whereKey($noFinal->competition_id)->update(['final_on' => null]); // falls back to verified_at

    expect(app(ExportPrestasiData::class)->schoolYears())->toBe(['2026/2027', '2024/2025', '2023/2024']);
});
