<?php

declare(strict_types=1);

use App\Enums\FilePurpose;
use App\Enums\ReportFileKind;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Humas\ArsipFoto;
use App\Models\Achievement;
use App\Models\File;
use App\Models\ReportFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures as H;

/*
 * D-156 (lead, 2026-10-16): Humas › Arsip foto lists the photos students attached to their verified laporan hasil, to
 * download for the flyer Humas designs itself. Only teams whose every member may be named (NamedStudent: no disabled or
 * deleted account); no consent since D-162 (every photo may be used). Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    $this->humas = H::humas();
    // staff.2fa: a staff account reaches its area only with a confirmed TOTP.
    $this->humas->forceFill(['two_factor_confirmed_at' => now()])->save();
});

/** One photo (a tiny PNG on the private disk) attached to the report of $achievement as kind `foto`. */
function d156Photo(Achievement $achievement, string $name = 'dokumentasi-final.png'): File
{
    $path = 'laporan/'.Str::lower((string) Str::ulid()).'.png';
    $bytes = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    Storage::disk('local')->put($path, $bytes);

    $file = new File;
    $file->forceFill([
        'disk' => 'local',
        'path' => $path,
        'original_name' => $name,
        'mime' => 'image/png',
        'size' => strlen($bytes),
        'sha256' => hash('sha256', $bytes),
        'purpose' => FilePurpose::ReportEvidence,
        'owner_user_id' => $achievement->student->user_id,
    ])->save();

    ReportFile::factory()->create(['report_id' => $achievement->report_id, 'kind' => ReportFileKind::Foto, 'file_id' => $file->getKey()]);

    return $file->fresh();
}

it('lists the photos of verified reports with the team, the lomba and a preview and download for each', function (): void {
    $achievement = H::achievement(competitionName: 'OPSI 2026');
    // The verified result is the one shown (the factory picks a random one).
    $achievement->report->forceFill(['final_result' => ResultType::Juara2])->save();
    H::teammate($achievement, 'Raka Pradipta', 'XII-B');
    $photo = d156Photo($achievement, 'foto-penyerahan-medali.png');
    // a certificate is evidence, not a photo
    ReportFile::factory()->create(['report_id' => $achievement->report_id, 'kind' => ReportFileKind::Sertifikat]);

    $this->actingAs($this->humas)
        ->get(route('humas.foto'))
        ->assertOk()
        ->assertSee('Arsip foto')
        ->assertSee('OPSI 2026')
        ->assertSee('Juara II')
        ->assertSee('Nadia Putri Anindya (XI-D), Raka Pradipta (XII-B)')
        ->assertSee('foto-penyerahan-medali.png')
        ->assertSee('1</span> laporan dengan foto', false)
        ->assertSee('href="'.route('files.show', $photo).'"', false)
        ->assertSee('data-sth-preview="'.e(route('files.show', ['file' => $photo, 'tampil' => 1])).'"', false)
        ->assertSee('data-sth-preview-type="image"', false)
        // the menu item
        ->assertSee('href="'.route('humas.foto').'"', false);
});

it('lists a team whatever the old consent columns of its members say (D-162)', function (array $state): void {
    $team = H::achievement(competitionName: 'OPSI 2026', studentState: $state);
    H::teammate($team, 'Raka Pradipta', 'XII-B', $state);
    d156Photo($team, 'dokumentasi-tim.png');

    Livewire::actingAs($this->humas)->test(ArsipFoto::class)
        ->assertSee('OPSI 2026')
        ->assertSee('dokumentasi-tim.png')
        ->assertSee('Raka Pradipta')
        ->assertSee('Nadia Putri Anindya')
        ->assertDontSee('laporan tidak ditampilkan')
        ->assertDontSee('mengizinkan');
})->with([
    'old photo column false' => [['photo_consent' => false]],
    'old publish column false' => [['publish_consent' => false]],
    'both false' => [['photo_consent' => false, 'publish_consent' => false]],
]);

it('leaves out a team with a disabled teammate, and only counts it in one neutral sentence', function (): void {
    $shown = H::achievement(studentName: 'Hana Safira', competitionName: 'FLS3N 2026');
    d156Photo($shown);

    $hidden = H::achievement(competitionName: 'OPSI 2026');
    H::teammate($hidden, 'Raka Pradipta', 'XII-B', disabled: true);
    d156Photo($hidden, 'rahasia.png');

    Livewire::actingAs($this->humas)->test(ArsipFoto::class)
        ->assertSee('FLS3N 2026')
        ->assertDontSee('OPSI 2026')
        ->assertDontSee('rahasia.png')
        ->assertDontSee('Raka Pradipta')
        ->assertSee('1 laporan tidak ditampilkan karena akun salah satu anggotanya nonaktif.');
});

it('leaves out a team with a disabled member, and a report that is not verified', function (): void {
    $disabled = H::achievement(competitionName: 'OPSI 2026');
    d156Photo($disabled);
    $disabled->student->user->forceFill(['status' => UserStatus::Disabled])->save();

    $waiting = H::achievement(studentName: 'Hana Safira', competitionName: 'FLS3N 2026');
    d156Photo($waiting);
    $waiting->report->forceFill(['status' => ReportStatus::Dikirim])->save();

    Livewire::actingAs($this->humas)->test(ArsipFoto::class)
        ->assertDontSee('OPSI 2026')
        ->assertDontSee('FLS3N 2026')
        ->assertSee('Belum ada foto dokumentasi')
        ->assertSee('1 laporan tidak ditampilkan');
});

it('finds a report by lomba or student name and says when nothing matches', function (): void {
    d156Photo(H::achievement(competitionName: 'OPSI 2026'));
    d156Photo(H::achievement(studentName: 'Hana Safira', competitionName: 'FLS3N 2026'));

    Livewire::actingAs($this->humas)->test(ArsipFoto::class)
        ->set('q', 'hana ')
        ->assertSet('q', 'hana ')
        ->assertSee('FLS3N 2026')
        ->assertDontSee('OPSI 2026')
        ->set('q', 'opsi')
        ->assertSee('OPSI 2026')
        ->assertDontSee('FLS3N 2026')
        ->set('q', 'tidak ada')
        ->assertSee('Tidak ada foto yang cocok')
        ->call('clearSearch')
        ->assertSee('OPSI 2026')
        ->assertSee('FLS3N 2026');
});

it('shows 12 reports a page', function (): void {
    foreach (range(1, 13) as $i) {
        d156Photo(H::achievement(studentName: 'Siswa Contoh '.$i, competitionName: 'Lomba Contoh '.$i));
    }

    Livewire::actingAs($this->humas)->test(ArsipFoto::class)
        ->assertSee('13</span> laporan dengan foto', false)
        ->assertSee('Halaman 1 dari 2')
        ->call('nextPage')
        ->assertSee('Halaman 2 dari 2');
});

it('lets Humas download a photo, audited', function (): void {
    $photo = d156Photo(H::achievement());

    $this->actingAs($this->humas)
        ->get(route('files.show', $photo))
        ->assertOk()
        ->assertDownload('dokumentasi-final.png');

    expect(Activity::query()->where('event', 'file.downloaded')->where('causer_id', $this->humas->getKey())->exists())->toBeTrue();
});

it('is for Humas only', function (): void {
    $this->get(route('humas.foto'))->assertRedirect(route('login'));

    foreach ([RoleName::Kesiswaan, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Admin, RoleName::Guru] as $role) {
        $staff = AuthTestHelpers::staff($role);
        $staff->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->actingAs($staff)->get(route('humas.foto'))->assertForbidden();
    }
});
