<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Enums\UserStatus;
use App\Livewire\Kesiswaan\Beranda as KesiswaanBeranda;
use App\Livewire\Kesiswaan\Talenta;
use App\Models\Classroom;
use App\Models\TalentApplication;
use App\Models\TalentBranch;
use App\Models\TalentProfile;
use App\Models\TalentType;
use Database\Seeders\TalentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| FS1-E (D-142), Kesiswaan › Database talenta siswa without stages: Kesiswaan only records. Two tabs ("Semua" and
| "Belum mengisi (kelas X)"), filters, a panel with an optional guru pembina and the note for him, and an export that
| follows the screen filters. Old rows of any status are plain data. Nobody is notified. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();
    $this->seed(TalentSeeder::class);

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
});

/** A row as the Actions leave it (`other_text` is not mass-assignable). @param array<string, mixed> $attributes */
function fs1eTalent(TalentTypeCode $code, ?string $branch, array $attributes = []): TalentApplication
{
    $type = TalentType::query()->where('code', $code)->sole();
    $row = new TalentApplication;
    $row->forceFill(array_merge([
        'talent_type_id' => $type->getKey(),
        'talent_branch_id' => $branch !== null ? TalentBranch::query()->where('talent_type_id', $type->getKey())->where('name', $branch)->value('id') : null,
        'status' => TalentStatus::Terdaftar,
    ], $attributes))->save();

    return $row->fresh();
}

/** @return list<array<int, mixed>> the rows of the downloaded file (the reader skips the empty 4th row) */
function fs1eExportRows(Testable $component): array
{
    $path = sys_get_temp_dir().'/talenta-fs1e-'.uniqid().'.xlsx';
    file_put_contents($path, base64_decode((string) $component->effects['download']['content']));
    $rows = SimpleExcelReader::create($path)->noHeaderRow()->getRows()->all();
    @unlink($path);

    return array_values($rows);
}

it('lists old rows of every status as plain data, with no stage control', function (): void {
    F::application(F::student('Hana Safira', 'X-8'), TalentStatus::Terdaftar, 'Biologi');
    F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Pemetaan, 'Kimia');
    F::application(F::student('Bima Satria Wicaksono', 'XII-7'), TalentStatus::Seleksi, 'Fisika');
    F::application(F::student('Nadia Putri Anindya', 'XI-1'), TalentStatus::Pembinaan, 'Astronomi');
    $rejected = F::application(F::student('Citra Ayu Lestari', 'XI-2'), TalentStatus::TidakLolos, 'Matematika');
    $rejected->forceFill(['rejected_from' => TalentStatus::Seleksi, 'review_requested_at' => now(), 'review_message' => 'Mohon ditinjau.'])->save();

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertViewHas('rows', fn ($rows) => $rows->count() === 5)
        ->assertViewHas('counts', fn (array $c) => $c['semua'] === 5)
        ->assertSee(['Hana Safira', 'Citra Ayu Lestari', '· Matematika'])
        ->assertDontSee(['Tidak lolos', 'Pemetaan', 'Seleksi/asesmen', 'Peserta pembinaan', 'Pulihkan', 'Minta ditinjau ulang', 'Pindahkan', 'Mohon ditinjau.', 'Jadwal asesmen', 'Catatan untuk siswa'])
        ->assertDontSeeHtml('wire:click="askRestore')->assertDontSeeHtml('wire:click="advanceRow')
        ->assertSee('Semua')->assertSee('Belum mengisi (kelas X)')
        ->assertSee('Talenta tercatat')
        ->call('showPanel', $rejected->getKey())
        ->assertSee('Guru pembina')
        ->assertDontSee(['Tidak lolos', 'Lolos ke', 'Mohon ditinjau.']);
});

it('shows a Lainnya choice with its text and the guru pembina on the row', function (): void {
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $hana = F::student('Hana Safira', 'X-8');
    fs1eTalent(TalentTypeCode::Lainnya, null, ['student_id' => $hana->getKey(), 'other_text' => 'Kaligrafi', 'mentor_id' => $guru->getKey()]);
    fs1eTalent(TalentTypeCode::Osn, 'Lainnya', ['student_id' => $hana->getKey(), 'other_text' => 'Robotika']);

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertSee('· Kaligrafi')
        ->assertSee('· Lainnya (Robotika)')
        ->assertViewHas('rows', fn ($rows) => $rows->firstWhere('branch', 'Kaligrafi')['mentorName'] === 'Pak Hendra Kusuma'
            && $rows->firstWhere('branch', 'Kaligrafi')['type'] === 'Lainnya'
            && $rows->firstWhere('branch', 'Kaligrafi')['bidang'] === null)
        // The panel lists every choice of the student by its title.
        ->assertSee('Lainnya (Kaligrafi)')
        ->assertSee('OSN · Lainnya (Robotika)')
        ->call('setJenis', 'lainnya')
        ->assertSet('cabang', '')
        ->assertViewHas('branches', [])
        ->assertViewHas('rows', fn ($rows) => $rows->pluck('branch')->all() === ['Kaligrafi']);
});

it('lists active kelas X students without a talent in "Belum mengisi (kelas X)"', function (): void {
    $hana = F::student('Hana Safira', 'X-8', nisn: '0103456714');
    $aurelia = F::student('Aurelia Maharani', 'X-3', nisn: '0101234557');
    $galang = F::student('Galang Putra Mahendra', 'X-1');
    F::student('Rizky Maulana', 'XI-1');
    $alumnus = F::student('Dimas Arya Nugroho', 'X-5');
    $alumnus->user->forceFill(['status' => UserStatus::Archived])->save();

    // Aurelia deleted her only talent (soft delete): she has none. Galang keeps an old "tidak lolos" row: he has one.
    F::application($aurelia, TalentStatus::Terdaftar, 'Kimia')->delete();
    F::application($galang, TalentStatus::TidakLolos, 'Fisika');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertViewHas('counts', fn (array $c) => $c['belum'] === 2 && $c['semua'] === 1)
        ->call('setTab', 'belum')->assertSet('tab', 'belum')
        ->assertViewHas('counts', fn (array $c) => $c['belum'] === 2)
        ->assertSee('Hana Safira')->assertSee('0103456714')->assertSee('X-8')
        ->assertSee('Aurelia Maharani')
        ->assertDontSee(['Galang Putra Mahendra', 'Rizky Maulana', 'Dimas Arya Nugroho'])
        // Search by NISN or name, and the kelas filter, work here too.
        ->set('q', '0103456714')->assertSee('Hana Safira')->assertDontSee('Aurelia Maharani')
        ->set('q', 'aurelia')->assertSee('Aurelia Maharani')->assertDontSee('Hana Safira')
        ->set('q', '')
        ->set('kelas', (string) Classroom::query()->where('name', 'X-8')->value('id'))
        ->assertSee('Hana Safira')->assertDontSee('Aurelia Maharani')
        ->set('q', 'tidak ada')
        ->assertSee('Tidak ada siswa di sini')
        ->call('setTab', 'nonsense')->assertSet('tab', 'semua');

    $this->actingAs($this->kesiswaan)->get(route('kesiswaan.talenta.index', ['tab' => 'belum']))->assertOk()
        ->assertSee('Hana Safira')->assertSee('Aurelia Maharani');
});

it('saves an optional guru pembina and the note for the guru without notifying anyone', function (): void {
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $row = F::application(F::student('Hana Safira', 'X-8'), TalentStatus::Seleksi);

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $row->getKey())
        ->assertSee('Tanpa guru pembina')
        ->set('mentorId', (string) $guru->getKey())
        ->set('panelNote', 'Latihan soal tiap Kamis.')
        ->call('savePanel')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Data talenta tersimpan.', tone: 'success');

    expect((int) $row->fresh()->mentor_id)->toBe((int) $guru->getKey())
        ->and($row->fresh()->note)->toBe('Latihan soal tiap Kamis.')
        ->and($row->fresh()->status)->toBe(TalentStatus::Seleksi);

    $component->set('mentorId', '')->call('savePanel')->assertHasNoErrors();
    expect($row->fresh()->mentor_id)->toBeNull();

    // Only an active guru: a Kesiswaan account is refused under the field.
    $component->set('mentorId', (string) $this->kesiswaan->getKey())->call('savePanel')->assertHasErrors(['mentorId']);
    expect($row->fresh()->mentor_id)->toBeNull();

    Notification::assertNothingSent();
    expect(Activity::query()->where('event', 'talent.assessment_updated')->count())->toBe(2);
});

it('exports the screen filters with the FS1 columns and never an empty file', function (): void {
    session()->put('auth.password_confirmed_at', time());
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $hana = F::student('Hana Safira', 'X-8', nisn: '0103456714');
    fs1eTalent(TalentTypeCode::Osn, 'Lainnya', ['student_id' => $hana->getKey(), 'other_text' => '=1+1']);
    fs1eTalent(TalentTypeCode::Debat, 'Debat Bahasa Indonesia (LDBI)', ['student_id' => $hana->getKey(), 'mentor_id' => $guru->getKey(), 'note' => 'Catatan internal.']);
    fs1eTalent(TalentTypeCode::Lainnya, null, ['student_id' => F::student('Aurelia Maharani', 'X-3')->getKey(), 'other_text' => 'Kaligrafi']);

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('setJenis', 'osn')
        ->call('export')
        ->assertFileDownloaded();

    $rows = fs1eExportRows($component);

    expect(Talenta::EXPORT_HEADER)->toBe(['Nama', 'NISN', 'Kelas', 'Jenis', 'Cabang', 'Keterangan lainnya', 'Guru pembina', 'Didaftarkan'])
        ->and($rows[0][0])->toStartWith('Database talenta siswa')
        ->and($rows[2][0])->toBe('Filter: Jenis: OSN')
        ->and($rows[3])->toBe(Talenta::EXPORT_HEADER)
        ->and(count($rows))->toBe(5);

    $data = array_combine(Talenta::EXPORT_HEADER, $rows[4]);
    expect($data['Nama'])->toBe('Hana Safira')
        ->and($data['NISN'])->toBe('0103456714')
        ->and($data['Jenis'])->toBe('OSN')
        ->and($data['Cabang'])->toBe('Lainnya')
        ->and($data['Keterangan lainnya'])->toBe("'=1+1")
        ->and($data['Guru pembina'])->toBe('');

    $log = Activity::query()->where('event', 'talent.exported')->sole();
    expect($log->properties['filters'])->toBe(['jenis' => 'osn'])
        ->and($log->properties['rows'])->toBe(1);

    // Every jenis: the Debat row names its guru pembina, the jenis Lainnya its text, no note column.
    $all = fs1eExportRows(Livewire::actingAs($this->kesiswaan)->test(Talenta::class)->call('export')->assertFileDownloaded());
    $byName = collect(array_slice($all, 4))->map(fn (array $r): array => array_combine(Talenta::EXPORT_HEADER, $r));

    expect($all[2][0])->toBe('Filter: semua talenta')
        ->and($byName->firstWhere('Jenis', 'Debat')['Guru pembina'])->toBe('Pak Hendra Kusuma')
        ->and($byName->firstWhere('Jenis', 'Lainnya')['Keterangan lainnya'])->toBe('Kaligrafi')
        ->and($byName->firstWhere('Jenis', 'Lainnya')['Cabang'])->toBe('')
        ->and(collect($all)->flatten()->all())->not->toContain('Catatan internal.');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('q', 'tidak ada')
        ->call('export')
        ->assertNoFileDownloaded()
        ->assertDispatched('sth-toast', message: Talenta::EXPORT_NOTHING, tone: 'error');

    expect(Activity::query()->where('event', 'talent.exported')->count())->toBe(2);
});

it('hides the download button in "Belum mengisi (kelas X)"', function (): void {
    F::application(F::student('Aurelia Maharani', 'X-3'));
    F::student('Hana Safira', 'X-8');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertSeeHtml('wire:click="export"')
        ->call('setTab', 'belum')
        ->assertDontSeeHtml('wire:click="export"')
        ->assertSee('Hana Safira');
});

it('keeps every other role away from the screen', function (): void {
    $row = F::application(F::student('Citra Ayu Lestari', 'XI-2'), TalentStatus::TidakLolos, 'Fisika');

    foreach ([F::staff(RoleName::Guru, 'Pak Hendra Kusuma'), F::staff(RoleName::Humas, 'Mas Galih'), F::staff(RoleName::TataUsaha, 'Staf TU'), F::staff(RoleName::KepalaSekolah, 'Kepala'), $row->student->user] as $user) {
        Livewire::actingAs($user)->test(Talenta::class)->assertForbidden();
        $this->actingAs($user)->get(route('kesiswaan.talenta.index'))->assertForbidden();
    }

    // Admin may: same permission as Kesiswaan.
    Livewire::actingAs(F::staff(RoleName::Admin, 'Operator STH'))->test(Talenta::class)
        ->call('showPanel', $row->getKey())
        ->assertSee('Citra Ayu Lestari');
});

it('checks the permission again inside every action', function (string $method, array $arguments): void {
    $row = F::application(F::student('Citra Ayu Lestari', 'XI-2'));
    session()->put('auth.password_confirmed_at', time());

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('panelNote', 'Tidak boleh tersimpan.');

    // The account loses the role while the screen is open.
    $this->kesiswaan->syncRoles([RoleName::Guru->value]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $arguments = array_map(fn (string $argument): string => $argument === ':id' ? $row->getKey() : $argument, $arguments);

    $component->call($method, ...$arguments)->assertForbidden();

    expect($row->fresh()->note)->toBeNull()
        ->and(Activity::query()->whereIn('event', ['talent.exported', 'talent.assessment_updated'])->exists())->toBeFalse();
})->with([
    'savePanel' => ['savePanel', []],
    'showPanel' => ['showPanel', [':id']],
    'export' => ['export', []],
    'setTab' => ['setTab', ['belum']],
]);

it('wraps one long word of the student in the detail panel instead of widening the page (QA6, #65 Q2-29)', function (): void {
    $application = F::application();
    $word = str_repeat('instagramcomsiswasmagaresmi', 3);
    TalentProfile::query()->create([
        'student_id' => $application->student_id,
        'interests' => [mb_substr($word, 0, 40)],
        'organizations' => $word,
        'junior_high_achievements' => $word,
    ]);

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $application->getKey())
        ->assertSee($word)
        // A grid column of 1fr grows to its longest word; minmax(0,1fr) plus a break inside the word keeps it in the card.
        ->assertSeeHtml('grid-cols-[110px_minmax(0,1fr)]')
        ->assertSeeHtml('<dd class="m-0 [overflow-wrap:anywhere]">'.$word.'</dd>');
});

it('names the Kesiswaan beranda tile "Talenta tercatat" and counts every talent', function (): void {
    F::application(F::student('Hana Safira', 'X-8'), TalentStatus::Terdaftar);
    F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::Seleksi, 'Kimia');
    F::application(F::student('Citra Ayu Lestari', 'XI-2'), TalentStatus::TidakLolos, 'Fisika');
    F::application(F::student('Bima Satria Wicaksono', 'XII-7'), TalentStatus::Pembinaan, 'Matematika')->delete();

    $this->actingAs($this->kesiswaan)->get(route('kesiswaan.beranda'))->assertOk()
        ->assertSee('Talenta tercatat')
        ->assertSee('pilihan siswa')
        ->assertDontSee('Talenta terdaftar')
        ->assertDontSee('belum dipetakan')
        ->assertDontSee('tahap=terdaftar', false);

    Livewire::actingAs($this->kesiswaan)->test(KesiswaanBeranda::class)
        ->assertViewHas('stats', fn (array $stats): bool => collect($stats)->firstWhere('label', 'Talenta tercatat')['value'] === '3'
            && collect($stats)->firstWhere('label', 'Talenta tercatat')['href'] === route('kesiswaan.talenta.index'));
});
