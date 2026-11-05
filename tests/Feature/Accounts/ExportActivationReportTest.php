<?php

declare(strict_types=1);

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\ActivationReport;
use App\Actions\Accounts\ExportActivationReport;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 * SV6, D-185: App\Actions\Accounts\ExportActivationReport, the Excel file of Status aktivasi and Admin › Kelas. The whole
 * school starts with the sheet "Ringkasan" and has only the tingkat sheets with students; one class is one sheet named
 * after the class; no student = no file and no audit row; every cell is text (D-174). Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    Notification::fake();
    $this->kesiswaan = A::staff(RoleName::Kesiswaan);
    $this->x2 = Classroom::factory()->create(['name' => 'X-2', 'level' => 10]);
    $this->xii1 = Classroom::factory()->level(12)->create(['name' => 'XII-1']);

    $make = function (Classroom $class, string $name, array $user = []): Student {
        $student = Student::factory()->create(['classroom_id' => $class->getKey(), 'nisn' => '00'.fake()->unique()->numerify('########')]);
        $student->user->forceFill(array_merge(['name' => $name], $user))->saveQuietly();

        return $student->fresh();
    };

    $this->nadia = $make($this->x2, 'Nadia Putri Anindya', ['initial_password_expires_at' => now()->addDays(5)]);
    $this->raka = $make($this->xii1, 'Raka Pratama', ['status' => UserStatus::Archived]);
    $this->dimas = $make($this->xii1, '+Dimas Saputra', ['status' => UserStatus::Disabled]);
});

/** @return list<string> sheet names, in order */
function sv6ExportSheets(string $xlsx): array
{
    $zip = new ZipArchive;
    $zip->open($xlsx);
    $book = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
    $zip->close();
    $book->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    return array_map(fn (SimpleXMLElement $sheet): string => (string) $sheet['name'], $book->xpath('//m:sheets/m:sheet'));
}

/** @return list<string> the opening tags of every cell of every sheet */
function sv6ExportCells(string $xlsx): array
{
    $zip = new ZipArchive;
    $zip->open($xlsx);
    $cells = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);

        if (str_starts_with($name, 'xl/worksheets/sheet')) {
            preg_match_all('/<c\b[^>]*>/', (string) $zip->getFromIndex($i), $found);
            $cells = [...$cells, ...$found[0]];
        }
    }

    $zip->close();

    return $cells;
}

it('writes the whole school: Ringkasan first, then only the tingkat sheets that have students, every cell text', function (): void {
    $file = app(ExportActivationReport::class)->handle($this->kesiswaan);

    expect($file)->not->toBeNull()
        ->and($file->rows)->toBe(3)
        ->and($file->filename)->toStartWith('status-aktivasi-semua-')
        ->and(sv6ExportSheets($file->path))->toBe([ActivationReport::SUMMARY_SHEET, 'X', 'XII'])
        ->and(array_column(SimpleExcelReader::create($file->path)->fromSheetName(ActivationReport::SUMMARY_SHEET)->getRows()->all(), 'Jumlah siswa'))->toBe(['1', '2', '3'])
        ->and(array_column(SimpleExcelReader::create($file->path)->fromSheetName('XII')->getRows()->all(), 'Status'))->toBe(['Nonaktif', 'Diarsipkan'])
        // Formula-safe (OWASP): the leading "+" gets an apostrophe.
        ->and(array_column(SimpleExcelReader::create($file->path)->fromSheetName('XII')->getRows()->all(), 'Nama'))->toBe(["'+Dimas Saputra", 'Raka Pratama']);

    // D-174: every cell is a text cell, the counts of Ringkasan too, so the NISN keeps its leading zeros.
    $cells = sv6ExportCells($file->path);
    expect($cells)->not->toBeEmpty()
        ->and(collect($cells)->reject(fn (string $cell): bool => str_contains($cell, 't="s"') || str_contains($cell, 't="inlineStr"'))->all())->toBe([])
        ->and(SimpleExcelReader::create($file->path)->fromSheetName('X')->getRows()->first()['NISN'])->toBe($this->nadia->nisn);

    @unlink($file->path);
});

it('writes one tingkat as one sheet and one class as one sheet named after the class (a class wins over a tingkat)', function (): void {
    $level = app(ExportActivationReport::class)->handle($this->kesiswaan, level: 12);
    $class = app(ExportActivationReport::class)->handle($this->kesiswaan, (string) $this->xii1->getKey(), level: 10);

    expect(sv6ExportSheets($level->path))->toBe(['XII'])
        ->and($level->filename)->toStartWith('status-aktivasi-xii-')
        ->and(sv6ExportSheets($class->path))->toBe(['XII-1'])
        ->and($class->filename)->toStartWith('status-aktivasi-kelas-xii-1-')
        ->and($class->rows)->toBe(2);
});

it('narrows by status and search, and audits them without the search text or any name', function (): void {
    $file = app(ExportActivationReport::class)->handle($this->kesiswaan, state: AccountControls::STATE_ARCHIVED, search: 'Raka');
    $log = Activity::query()->where('event', ExportActivationReport::EVENT)->sole();

    expect($file->rows)->toBe(1)
        ->and($log->causer_id)->toBe($this->kesiswaan->getKey())
        ->and($log->log_name)->toBe('account')
        ->and($log->properties['filters'])->toBe(['status' => AccountControls::STATE_ARCHIVED, 'q' => 'ya'])
        ->and($log->properties['tingkat'])->toBe('semua')
        ->and($log->properties['rows'])->toBe(1)
        ->and($log->properties['source'])->toBe(ExportActivationReport::SOURCE_ACTIVATION)
        ->and(str_contains((string) json_encode($log->properties), 'Raka'))->toBeFalse()
        ->and(str_contains((string) json_encode($log->properties), (string) $this->raka->nisn))->toBeFalse();
});

it('writes no file and no audit row when no student matches', function (array $arguments): void {
    $empty = Classroom::factory()->level(11)->create(['name' => 'XI-7']);
    $arguments = array_map(fn (mixed $value): mixed => $value === 'kosong' ? (string) $empty->getKey() : $value, $arguments);

    expect(app(ExportActivationReport::class)->handle($this->kesiswaan, ...$arguments))->toBeNull()
        ->and(Activity::query()->where('event', ExportActivationReport::EVENT)->exists())->toBeFalse();
})->with([
    'an empty tingkat' => [['level' => 11]],
    'an empty class' => [['classroomId' => 'kosong']],
    'a status nobody is in' => [['state' => AccountControls::STATE_FROZEN]],
    'a search without match' => [['search' => 'Tidak Ada Siswa Ini']],
]);

it('writes no file when every student is in the trash', function (): void {
    foreach ([$this->nadia, $this->raka, $this->dimas] as $student) {
        $student->delete();
        $student->user->delete();
    }

    expect(app(ExportActivationReport::class)->handle(A::admin()))->toBeNull();
});

it('refuses every role without student.view and a student', function (RoleName $role): void {
    $actor = $role === RoleName::Siswa ? User::query()->findOrFail($this->nadia->user_id) : A::staff($role);

    expect(fn () => app(ExportActivationReport::class)->handle($actor))->toThrow(AuthorizationException::class)
        ->and(Activity::query()->where('event', ExportActivationReport::EVENT)->exists())->toBeFalse();
})->with([RoleName::Humas, RoleName::Guru, RoleName::Siswa]);

it('refuses an unknown tingkat, status, class or screen', function (array $arguments): void {
    expect(fn () => app(ExportActivationReport::class)->handle($this->kesiswaan, ...$arguments))->toThrow(InvalidArgumentException::class);
})->with([
    'tingkat' => [['level' => 9]],
    'status' => [['state' => 'apa-saja']],
    'class' => [['classroomId' => 'tidak-ada']],
    'screen' => [['source' => 'lain']],
]);

it('gives a class name a valid sheet name', function (string $name, string $sheet): void {
    expect(ActivationReport::sheetName($name))->toBe($sheet);
})->with([
    ['XI-IPA 1', 'XI-IPA 1'],
    ['X/1:[2]*?', 'X 1 2'],
    ['', 'Kelas'],
    [str_repeat('A', 40), str_repeat('A', 31)],
]);
