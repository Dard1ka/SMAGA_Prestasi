<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Http\Middleware\EnsureTalentChosen;
use App\Livewire\Kesiswaan\Lomba\Form as KesiswaanLombaForm;
use App\Livewire\Siswa\Lomba\LombaPresenter;
use App\Livewire\Siswa\Lomba\Usulkan;
use App\Livewire\Siswa\Pendaftaran\Form as PendaftaranForm;
use App\Models\Competition;
use App\Models\TalentApplication;
use App\Models\TalentType;
use Database\Seeders\TalentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;
use Tests\Support\TalentGate;

/*
| FS1 integration of Paket E (talenta) with Paket B (alur lomba): the kelas X gate (D-144) holds a student without a
| talent before Siswa › Usulkan lomba like before every other page and lets one with a talent (a "Lainnya" one counts)
| in; a "Lainnya" talent without a bidang (D-143) never breaks the lomba suggestions on Beranda; the D-147 date labels
| go with a student's own lomba from Usulkan to its Detail lomba and the Periksa drawer. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    K::boot();
    $this->seed(TalentSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->hana = F::student('Hana Safira', 'X-8');
    $this->hanaUser = F::user($this->hana);
});

/** A talent choice of the student in the seeded jenis $code (Lainnya with a text, OSN with its first cabang). */
function tglChoose(object $student, TalentTypeCode $code): TalentApplication
{
    $type = TalentType::query()->where('code', $code)->sole();
    $row = new TalentApplication;
    $row->forceFill([
        'student_id' => $student->getKey(),
        'talent_type_id' => $type->getKey(),
        'talent_branch_id' => $code->requiresBranch() ? $type->branches()->value('id') : null,
        'other_text' => $code === TalentTypeCode::Lainnya ? 'Kaligrafi' : null,
        'status' => TalentStatus::Terdaftar,
    ])->save();

    return $row;
}

it('holds a kelas X student without a talent before Usulkan lomba and lets one with a talent in', function (): void {
    $this->actingAs($this->hanaUser)->get(route('siswa.lomba.usulkan'))->assertRedirect(route('siswa.talenta'));
    $this->actingAs($this->hanaUser)->getJson(route('siswa.lomba.usulkan'))->assertForbidden();
    $this->actingAs($this->hanaUser)->get(route('siswa.talenta'))->assertOk()->assertSee(EnsureTalentChosen::ALERT);

    // A "Lainnya" talent with its own text counts as a choice (D-143).
    tglChoose($this->hana, TalentTypeCode::Lainnya);

    $this->actingAs($this->hanaUser)->get(route('siswa.lomba.usulkan'))->assertOk()
        ->assertSee('Data lomba')->assertSee('Juknis / brosur / undangan');
});

it('holds the student again before Usulkan lomba once the last talent is deleted', function (): void {
    $choice = tglChoose($this->hana, TalentTypeCode::Osn);
    $this->actingAs($this->hanaUser)->get(route('siswa.lomba.usulkan'))->assertOk();

    $choice->delete();

    $this->actingAs($this->hanaUser)->get(route('siswa.lomba.usulkan'))->assertRedirect(route('siswa.talenta'));
});

it('lets a kelas XI or XII student who chose a talent open Usulkan lomba (D-153)', function (): void {
    foreach (['XI-2', 'XII-1'] as $kelas) {
        $student = F::student('Siswa '.$kelas, $kelas);
        $this->actingAs(F::user($student))->get(route('siswa.lomba.usulkan'))->assertOk()->assertSee('Data lomba');
    }
});

it('lets a kelas X student with a talent save a lomba and register for it on the same page', function (): void {
    TalentGate::pass($this->hana);
    $guru = K::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $t = Livewire::actingAs($this->hanaUser)->test(Usulkan::class);

    foreach (F::usulan() as $k => $v) {
        $t->set("form.$k", $v);
    }

    $t->set('form.juknis', K::pdf())->call('simpan')->assertHasNoErrors()->assertSeeLivewire(PendaftaranForm::class);

    $lomba = Competition::query()->sole();
    Livewire::actingAs($this->hanaUser)->test(PendaftaranForm::class, ['competitionId' => $lomba->getKey()])
        ->set('mentorId', $guru->getKey())->set('setuju', true)->set('phone', '081234567890')
        ->call('kirim')->assertHasNoErrors()
        ->assertSee('Menunggu verifikasi lomba');
});

it('suggests open lomba on Beranda for a talent of jenis Lainnya, which has no bidang', function (): void {
    expect(TalentTypeCode::Lainnya->bidang())->toBeNull();
    F::openCompetition(['name' => 'Lomba Riset Fiktif 2026', 'bidang' => Bidang::Riset]);
    F::openCompetition(['name' => 'Olimpiade Sains Fiktif 2026', 'bidang' => Bidang::Sains]);
    tglChoose($this->hana, TalentTypeCode::Lainnya);

    // Only "Lainnya": no bidang to prefer, so the open lomba in general.
    $this->actingAs($this->hanaUser)->get(route('siswa.beranda'))->assertOk()
        ->assertSee('Pendaftaran yang sedang dibuka')
        ->assertSee('Lomba Riset Fiktif 2026')->assertSee('Olimpiade Sains Fiktif 2026')
        ->assertDontSee('Sesuai bidang minatmu');

    // "Lainnya" next to OSN: the bidang of OSN leads, "Lainnya" adds none.
    tglChoose($this->hana, TalentTypeCode::Osn);

    $this->actingAs($this->hanaUser)->get(route('siswa.beranda'))->assertOk()
        ->assertSee('Sesuai bidang minatmu: Sains')
        ->assertDontSee('Sesuai bidang minatmu: Sains &amp;', false)
        ->assertSee('Olimpiade Sains Fiktif 2026');
});

it('keeps the D-147 labels for the own lomba from Usulkan to Detail lomba and the Periksa drawer', function (): void {
    TalentGate::pass($this->hana);
    $t = Livewire::actingAs($this->hanaUser)->test(Usulkan::class)
        ->assertSee('Pendaftaran dibuka')->assertSee('Pendaftaran ditutup')->assertSee('Pelaksanaan')
        ->assertDontSee('Laporan hasil dibuka setelah tanggal ini.')
        ->assertDontSee('Batas daftar')->assertDontSee('Tanggal final');

    foreach (F::usulan(['starts_on' => now()->addDays(35)->format('Y-m-d'), 'final_on' => now()->addDays(37)->format('Y-m-d')]) as $k => $v) {
        $t->set("form.$k", $v);
    }

    $t->set('form.duration', 'lebih_dari_satu_hari')->assertSee('Tanggal selesai')
        ->set('form.juknis', K::pdf())->call('simpan')->assertHasNoErrors();

    $lomba = Competition::query()->sole();

    $this->actingAs($this->hanaUser)->get(route('siswa.lomba.show', $lomba))->assertOk()
        ->assertSee('Pendaftaran')->assertSee('Pelaksanaan')->assertSee('Laporan hasil')
        ->assertSee(LombaPresenter::REPORT_LINE)
        ->assertDontSee('Batas laporan hasil')->assertDontSee('Tanggal final')->assertDontSee('Final dan pengumuman');

    Livewire::actingAs(K::staff(RoleName::Kesiswaan))->test(KesiswaanLombaForm::class)->call('open', $lomba->getKey())
        ->assertSet('form.duration', 'lebih_dari_satu_hari')
        ->assertSee('Pendaftaran ditutup')->assertSee('Tanggal mulai')->assertSee('Tanggal selesai')
        ->assertSee('Laporan hasil dibuka setelah tanggal ini.')
        ->assertDontSee('Dasar batas laporan hasil.')->assertDontSee('Tanggal final');
});
