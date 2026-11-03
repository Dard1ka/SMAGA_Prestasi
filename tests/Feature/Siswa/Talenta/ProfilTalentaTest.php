<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Livewire\Siswa\ProfilTalenta;
use App\Models\TalentApplication;
use App\Models\TalentProfile;
use App\Models\TalentType;
use Database\Seeders\TalentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    $this->seed(TalentSeeder::class);
    $this->withoutVite();

    $this->nadia = F::student(talent: false);
    $this->osn = TalentType::query()->where('code', TalentTypeCode::Osn)->firstOrFail();
    $this->kimia = $this->osn->branches()->where('name', 'Kimia')->firstOrFail();
});

it('renders the seven jenis from the DB with bidang, and the account data', function (): void {
    $this->actingAs($this->nadia->user)
        ->get(route('siswa.talenta'))
        ->assertOk()
        ->assertSee('<title>Profil saya · SMAGA Talent Hub</title>', false)
        ->assertSeeInOrder(['OSN', 'Riset', 'FLS3N', 'O2SN', 'OLPAIN', 'Debat', 'Lainnya'])
        ->assertSee('Seni Budaya')
        ->assertSee('Nadia Putri Anindya')
        ->assertSee('NISN '.$this->nadia->nisn)
        ->assertSee('Pilih jenis talenta dulu.')
        ->assertSee('Tambah talenta')
        ->assertDontSee('penjaringan');
});

it('registers a choice through jenis → cabang → Tambah talenta', function (): void {
    Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->call('pilihJenis', $this->osn->getKey())
        ->assertSee('Kimia')
        ->assertSee('Pilih satu cabang dulu.')
        ->call('pilihCabang', $this->kimia->getKey())
        ->set('bukti', F::pdf('sertifikat-osn.pdf'))
        ->call('daftar')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'OSN · Kimia ditambahkan.', tone: 'success')
        ->assertSet('jenis', '')
        ->assertSee('OSN · Kimia')
        ->assertSee('Ditambahkan '.now()->locale('id')->translatedFormat('j M Y'))
        ->assertDontSee('Tahap');

    $application = TalentApplication::query()->where('student_id', $this->nadia->getKey())->firstOrFail();
    expect($application->talent_branch_id)->toBe($this->kimia->getKey())
        ->and($application->evidence_file_id)->not->toBeNull();

    // The same cabang is marked and cannot be sent again.
    Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->call('pilihJenis', $this->osn->getKey())
        ->call('pilihCabang', $this->kimia->getKey())
        ->assertSee('Sudah dipilih')
        ->assertSee('Sudah dipilih. Pilih cabang lain.')
        ->call('daftar')
        ->assertHasErrors('cabang');
});

it('registers OLPAIN without a cabang', function (): void {
    $olpain = TalentType::query()->where('code', TalentTypeCode::Olpain)->firstOrFail();

    Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->call('pilihJenis', $olpain->getKey())
        ->assertSee('Tidak perlu memilih cabang.')
        ->call('daftar')
        ->assertHasNoErrors()
        ->assertSee('OLPAIN');

    expect(TalentApplication::query()->where('talent_type_id', $olpain->getKey())->whereNull('talent_branch_id')->count())->toBe(1);
});

it('ignores a cabang of another jenis sent by the browser', function (): void {
    $renang = TalentType::query()->where('code', TalentTypeCode::O2sn)->firstOrFail()->branches()->where('name', 'Renang')->firstOrFail();

    Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->call('pilihJenis', $this->osn->getKey())
        ->call('pilihCabang', $renang->getKey())
        ->assertSet('cabang', '')
        ->set('cabang', $renang->getKey())
        ->call('daftar')
        ->assertHasErrors('cabang');

    expect(TalentApplication::query()->count())->toBe(0);
});

it('deletes a choice and brings it back with Urungkan, guru pembina included', function (): void {
    $pembina = F::staff(RoleName::Guru, 'Retno Wulandari');
    $application = TalentApplication::factory()->create([
        'student_id' => $this->nadia->getKey(), 'talent_type_id' => $this->osn->getKey(), 'talent_branch_id' => $this->kimia->getKey(),
        'mentor_id' => $pembina->getKey(), 'note' => 'Latihan soal tiap Kamis.',
    ]);

    $component = Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->assertSee('Hapus OSN · Kimia')
        ->assertSee('Guru pembina Retno Wulandari')
        ->call('batalkan', $application->getKey())
        ->assertDispatched('sth-toast', message: 'OSN · Kimia dihapus.', tone: 'info')
        ->assertSee('OSN · Kimia dihapus.')
        ->assertSee('Urungkan');

    expect($application->fresh()->trashed())->toBeTrue();

    $component->call('urungkan')->assertSet('undo', null)
        ->assertDispatched('sth-toast', message: 'OSN · Kimia dipulihkan.', tone: 'success');

    // Urungkan is an undo: the row comes back as Kesiswaan left it (D-142).
    expect($application->fresh()->trashed())->toBeFalse()
        ->and($application->fresh()->mentor_id)->toBe($pembina->getKey())
        ->and($application->fresh()->note)->toBe('Latihan soal tiap Kamis.');
});

it('asks "Sebutkan" for a Lainnya cabang and registers the typed talent', function (): void {
    $lainnya = $this->osn->branches()->where('name', 'Lainnya')->firstOrFail();

    Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class)
        ->call('pilihJenis', $this->osn->getKey())
        ->assertDontSee('Sebutkan')
        ->call('pilihCabang', $lainnya->getKey())
        ->assertSee('Sebutkan')
        ->assertSee('Tulis talentamu di kolom Sebutkan.')
        ->call('daftar')->assertHasErrors(['lainnya'])
        ->set('lainnya', 'Robotika')->call('daftar')->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'OSN · Lainnya (Robotika) ditambahkan.', tone: 'success')
        ->assertSet('lainnya', '')
        ->assertSee('OSN · Lainnya (Robotika)')
        // A second text on the same cabang is a new talent: the cabang is never marked as taken.
        ->call('pilihJenis', $this->osn->getKey())->call('pilihCabang', $lainnya->getKey())
        ->assertDontSee('Sudah dipilih')
        ->set('lainnya', '  robotika ')->call('daftar')
        ->assertHasErrors(['lainnya' => 'Talenta ini sudah kamu pilih.'])
        ->set('lainnya', 'Desain grafis')->call('daftar')->assertHasNoErrors()
        ->assertSee('OSN · Lainnya (Desain grafis)');

    expect(TalentApplication::query()->where('talent_branch_id', $lainnya->getKey())->pluck('other_text')->sort()->values()->all())
        ->toBe(['Desain grafis', 'Robotika']);
});

it('asks "Sebutkan" for the jenis Lainnya without a cabang and clears the text with another jenis', function (): void {
    $jenis = TalentType::query()->where('code', TalentTypeCode::Lainnya)->sole();

    Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class)
        ->call('pilihJenis', $jenis->getKey())
        ->assertSee('Tidak perlu memilih cabang.')
        ->assertSee('Sebutkan')
        ->set('lainnya', 'Kaligrafi')
        ->call('pilihJenis', $this->osn->getKey())
        ->assertSet('lainnya', '')
        ->call('pilihJenis', $jenis->getKey())
        ->set('lainnya', str_repeat('Kaligrafi', 7))->call('daftar')
        ->assertHasErrors(['lainnya' => 'Tulis 3 sampai 60 karakter.'])
        ->set('lainnya', 'Kaligrafi')->call('daftar')->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Lainnya (Kaligrafi) ditambahkan.', tone: 'success')
        ->assertSee('Lainnya (Kaligrafi)');
});

it('lets the student delete any own choice, also one Kesiswaan handled, and tells nobody', function (TalentStatus $status): void {
    Notification::fake();
    $pembina = F::staff(RoleName::Guru, 'Retno Wulandari');
    $application = TalentApplication::factory()->status($status)->create([
        'student_id' => $this->nadia->getKey(), 'talent_type_id' => $this->osn->getKey(), 'talent_branch_id' => $this->kimia->getKey(),
        'mentor_id' => $pembina->getKey(),
    ]);
    $theirs = TalentApplication::factory()->status($status)->create([
        'student_id' => F::student('Dimas Arya Nugroho', talent: false)->getKey(),
        'talent_type_id' => $this->osn->getKey(),
        'talent_branch_id' => $this->osn->branches()->where('name', 'Fisika')->value('id'),
    ]);

    Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class)
        ->assertSee('Hapus OSN · Kimia')
        ->call('batalkan', $application->getKey())
        ->assertDispatched('sth-toast', message: 'OSN · Kimia dihapus.', tone: 'info')
        ->assertDontSee('Hapus OSN · Kimia');

    expect($application->fresh()->trashed())->toBeTrue();
    Notification::assertNothingSent();

    Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class)
        ->call('batalkan', $theirs->getKey())
        ->assertNotFound();

    expect($theirs->fresh()->trashed())->toBeFalse();
})->with([TalentStatus::Terdaftar, TalentStatus::Seleksi, TalentStatus::Pembinaan, TalentStatus::TidakLolos]);

it('shows no stage, no review button and no old decision note', function (): void {
    TalentApplication::factory()->rejected(TalentStatus::Seleksi, 'Catatan lama.')->create([
        'student_id' => $this->nadia->getKey(), 'talent_type_id' => $this->osn->getKey(), 'talent_branch_id' => $this->kimia->getKey(),
        'review_requested_at' => now(), 'note' => 'Catatan untuk pembina.',
    ]);

    $this->actingAs($this->nadia->user)->get(route('siswa.talenta'))->assertOk()
        ->assertSee('OSN · Kimia')
        ->assertSee('Hapus')
        ->assertDontSee(['Tahap', 'Minta ditinjau ulang', 'Belum lolos', 'Catatan lama.', 'Tidak lolos', 'Catatan untuk pembina.', 'Pemetaan', 'Menunggu tinjauan ulang']);

    // The cabang of an old "tidak lolos" row is simply taken.
    Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class)
        ->call('pilihJenis', $this->osn->getKey())->call('pilihCabang', $this->kimia->getKey())
        ->assertSee('Sudah dipilih. Pilih cabang lain.')
        ->assertDontSee('Tidak lolos');
});

it('saves the Riwayat talenta form', function (): void {
    Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->set('minatBaru', 'Penelitian')
        ->call('tambahMinat')
        ->set('minatBaru', 'Kimia')
        ->call('tambahMinat')
        ->assertSet('minat', ['Penelitian', 'Kimia'])
        ->set('dikuasai', 'Kimia dan metodologi penelitian')
        ->set('organisasi', 'KIR SMAGA, anggota sejak 2025')
        ->set('smp', 'Juara II LKTI tingkat kota, 2024')
        ->set('sertifikatPilih', F::pdf('sertifikat-lkti-2024.pdf'))
        ->assertCount('sertifikatBaru', 1)
        ->call('simpan')
        ->assertHasNoErrors()
        ->assertSet('tersimpan', true)
        ->assertSee('Tersimpan')
        ->assertSee('sertifikat-lkti-2024.pdf');

    $profile = TalentProfile::query()->where('student_id', $this->nadia->getKey())->firstOrFail();
    expect($profile->interests)->toBe(['Penelitian', 'Kimia'])
        ->and($profile->junior_high_achievements)->toBe('Juara II LKTI tingkat kota, 2024')
        ->and($profile->certificates)->toHaveCount(1);

    Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->assertSet('minat', ['Penelitian', 'Kimia'])
        ->assertSet('dikuasai', 'Kimia dan metodologi penelitian');
});

it('caps interests at four', function (): void {
    $component = Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class);

    foreach (['Penelitian', 'Kimia', 'Biologi', 'Fotografi', 'Renang'] as $interest) {
        $component->set('minatBaru', $interest)->call('tambahMinat');
    }

    $component->assertCount('minat', 4)->assertHasErrors('minat');
});

it('is only for signed-in students', function (): void {
    $this->get(route('siswa.talenta'))->assertRedirect(route('login'));
    $this->actingAs(F::staff(RoleName::Kesiswaan))->get(route('siswa.talenta'))->assertForbidden();
    $this->actingAs(F::staff(RoleName::Guru))->get(route('siswa.talenta'))->assertForbidden();
});
