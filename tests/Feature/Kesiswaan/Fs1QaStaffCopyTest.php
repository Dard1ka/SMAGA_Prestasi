<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Enums\WeightCategory;
use App\Livewire\Admin\Pengaturan;
use App\Livewire\Admin\Pengaturan\BatasArsip;
use App\Livewire\Admin\Pengaturan\Bobot;
use App\Livewire\Admin\Pengaturan\Pengguna;
use App\Livewire\Kesiswaan\Lomba\Form;
use App\Livewire\Kesiswaan\Lomba\Index;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;
use Tests\Feature\Siswa\Lomba\LombaFixtures as L;

/*
| FS1 QA round 1, staff copy that no longer matched the flow: the Kesiswaan queue hint after D-145, category B with
| BUMN/BUMD after D-146, the Admin settings tab after D-151 took the report deadline away, and the empty staff list
| that the top bar search "Cari nama atau NISN" lands on. Staff copy has no pronoun (D-119). Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();
    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
});

it('no longer says Kesiswaan uploads lomba in the queue hint (LOMBA-02)', function (): void {
    Livewire::actingAs($this->kesiswaan)->test(Index::class)
        ->set('tab', 'verifikasi')
        ->assertSee('Setiap lomba baru, dari usulan siswa atau dari Admin, diperiksa di sini sebelum tampil di katalog.')
        ->assertDontSee('termasuk dari Kesiswaan sendiri');
});

it('names BUMN/BUMD under category B in Pengaturan and in the drawer legend (LOMBA-12)', function (): void {
    expect(WeightCategory::B->hint())->toBe('Pemerintah pusat/daerah, BUMN/BUMD');

    $admin = A::admin();
    $this->actingAs($admin);
    session(A::passwordConfirmed());
    Livewire::test(Bobot::class)->assertSee('Pemerintah pusat/daerah, BUMN/BUMD');

    $lomba = L::pendingCompetition(L::user(L::student()), L::usulanAttributes(['name' => 'Usulan BUMN Fiktif']));

    Livewire::actingAs($this->kesiswaan)->test(Form::class)->call('open', $lomba->getKey())
        ->assertSee('A Puspresnas · B Pemerintah/BUMN · C Federasi/PT · D Swasta · E Lainnya');
});

it('names the settings tab after what is left in it, the archive (LOMBA-11)', function (): void {
    expect(Pengaturan::TABS['batas']['label'])->toBe('Arsip akun');

    $admin = A::admin();
    $this->actingAs($admin);
    session(A::passwordConfirmed());

    Livewire::test(BatasArsip::class)
        ->assertSee('Arsip akun')->assertSee('Kapan akun siswa diarsipkan.')
        ->assertSee('Simpan')
        ->assertDontSee('Batas &amp; arsip', false)->assertDontSee('Simpan batas');
});

it('tells where a student is found when the staff search finds no name (QA-DC-02)', function (): void {
    $admin = A::admin();
    $this->actingAs($admin);
    session(A::passwordConfirmed());

    Livewire::test(Pengguna::class)
        ->set('search', 'Citra Wulandari')
        ->assertSee('Tidak ada akun yang cocok')
        ->assertSee('Nama hanya mencari akun staf; akun siswa dicari dengan NISN lengkap di Bantuan akun siswa di bawah.');
});
