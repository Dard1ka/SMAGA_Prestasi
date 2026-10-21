<?php

declare(strict_types=1);

use App\Actions\Exports\AchievementFilters;
use App\Actions\Exports\ExportAchievements;
use App\Enums\RoleName;
use App\Livewire\Akun\StatusAktivasi;
use App\Models\Achievement;
use App\Models\Classroom;
use App\Notifications\Admin\LargeExportNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 * QA10: two large exports only wrote an audit row. SECURITY §3m-9 asks for an alert to every Admin when one export
 * holds more than LargeExportNotice::LIMIT rows, as the Talenta, Laporan prestasi and audit log exports already do:
 * Kesiswaan › Database prestasi (Excel, up to 10.000 rows) and Status aktivasi (CSV of the whole school).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();

    $this->admin = A::admin(['name' => 'Operator STH', 'username' => 'operator']);
    $this->kesiswaan = A::staff(RoleName::Kesiswaan, ['name' => 'Ibu Dwi Rahayu']);
});

/**
 * $count fictional students written in bulk (users + students), each with one verified achievement when
 * $withAchievement is true (all in one lomba of one verified report).
 */
function qa10LombaExportRows(int $count, bool $withAchievement): void
{
    $template = $withAchievement ? Achievement::factory()->create() : null;
    $classroomId = $template?->student->classroom_id ?? Classroom::factory()->level(11)->create(['name' => 'XI-D'])->getKey();
    $now = now()->toDateTimeString();

    foreach (array_chunk(range(1, $count), 200) as $chunk) {
        DB::table('users')->insert(array_map(fn (int $i): array => [
            'name' => 'Siswa Contoh '.$i, 'username' => 'qa10-ekspor-'.$i, 'password' => 'not-a-login', 'status' => 'pending_activation',
            'created_at' => $now, 'updated_at' => $now,
        ], $chunk));
        $userIds = DB::table('users')->whereIn('username', array_map(fn (int $i): string => 'qa10-ekspor-'.$i, $chunk))->pluck('id', 'username');

        $students = array_map(fn (int $i): array => [
            'id' => (string) Str::ulid(), 'user_id' => $userIds['qa10-ekspor-'.$i], 'nis' => (string) (800000000 + $i), 'nisn' => (string) (8000000000 + $i),
            'birth_date' => '2010-01-01', 'classroom_id' => $classroomId, 'import_school_year' => '2026/2027', 'created_at' => $now, 'updated_at' => $now,
        ], $chunk);
        DB::table('students')->insert($students);

        if ($template !== null) {
            DB::table('achievements')->insert(array_map(fn (array $student): array => [
                'id' => (string) Str::ulid(), 'report_id' => $template->report_id, 'student_id' => $student['id'], 'competition_id' => $template->competition_id,
                'result' => 'juara_1', 'level' => 'nasional', 'weight_category' => 'A', 'w_level' => '80.00', 'w_result' => '1.00', 'w_organizer' => '1.00',
                'points' => '80.00', 'weight_version_id' => $template->weight_version_id, 'verified_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ], $students));
        }
    }
}

it('alerts every Admin when the Database prestasi export holds more than 500 rows (QA10)', function (): void {
    qa10LombaExportRows(LargeExportNotice::LIMIT, true); // + the template = 501 rows

    $file = app(ExportAchievements::class)->handle($this->kesiswaan, AchievementFilters::fromInput([]));
    @unlink($file->path);

    expect($file->rows)->toBe(501);
    Notification::assertSentTo($this->admin, LargeExportNotice::class, function (LargeExportNotice $notice): bool {
        return str_contains(implode(' ', $notice->lines()), 'Ibu Dwi Rahayu men-download database prestasi berisi 501 baris');
    });
    Notification::assertNotSentTo($this->kesiswaan, LargeExportNotice::class);
});

it('sends no alert for a Database prestasi export of 500 rows or fewer (QA10)', function (): void {
    qa10LombaExportRows(LargeExportNotice::LIMIT - 1, true); // + the template = 500 rows

    $file = app(ExportAchievements::class)->handle($this->kesiswaan, AchievementFilters::fromInput([]));
    @unlink($file->path);

    expect($file->rows)->toBe(500);
    Notification::assertNothingSentTo($this->admin);
});

it('alerts every Admin when the Status aktivasi CSV holds more than 500 rows (QA10)', function (): void {
    qa10LombaExportRows(LargeExportNotice::LIMIT + 1, false);
    $this->actingAs($this->kesiswaan);
    session(A::passwordConfirmed());

    $component = Livewire::test(StatusAktivasi::class)->call('export');

    expect($component->effects['download'] ?? null)->not->toBeNull();
    Notification::assertSentTo($this->admin, LargeExportNotice::class, function (LargeExportNotice $notice): bool {
        return str_contains(implode(' ', $notice->lines()), 'Ibu Dwi Rahayu men-download laporan status aktivasi berisi 501 baris');
    });
});

it('sends no alert for a Status aktivasi CSV of 500 rows or fewer (QA10)', function (): void {
    qa10LombaExportRows(LargeExportNotice::LIMIT, false);
    $this->actingAs($this->kesiswaan);
    session(A::passwordConfirmed());

    Livewire::test(StatusAktivasi::class)->call('export');

    Notification::assertNothingSentTo($this->admin);
});
