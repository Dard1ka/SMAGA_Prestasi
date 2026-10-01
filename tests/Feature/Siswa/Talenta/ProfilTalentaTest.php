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
use Livewire\Livewire;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    $this->seed(TalentSeeder::class);
    $this->withoutVite();

    $this->nadia = F::student();
    $this->osn = TalentType::query()->where('code', TalentTypeCode::Osn)->firstOrFail();
    $this->kimia = $this->osn->branches()->where('name', 'Kimia')->firstOrFail();
});

it('renders the six jenis from the DB with bidang, and the account data', function (): void {
    $this->actingAs($this->nadia->user)
        ->get(route('siswa.talenta'))
        ->assertOk()
        ->assertSee('<title>Profil talenta · SMAGA Talent Hub</title>', false)
        ->assertSeeInOrder(['OSN', 'Riset', 'FLS3N', 'O2SN', 'OLPAIN', 'Debat'])
        ->assertSee('Seni Budaya')
        ->assertSee('Nadia Putri Anindya')
        ->assertSee('NIS '.$this->nadia->nis)
        ->assertSee('Pilih jenis talenta dulu.')
        ->assertSee('Penjaringan pembinaan talenta');
});

it('registers a choice through jenis → cabang → Daftar penjaringan', function (): void {
    Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->call('pilihJenis', $this->osn->getKey())
        ->assertSee('Kimia')
        ->assertSee('Pilih satu cabang dulu.')
        ->call('pilihCabang', $this->kimia->getKey())
        ->set('bukti', F::pdf('sertifikat-osn.pdf'))
        ->call('daftar')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Terdaftar di penjaringan OSN · Kimia.', tone: 'success')
        ->assertSet('jenis', '')
        ->assertSee('OSN · Kimia')
        ->assertSee('Menunggu pemetaan Kesiswaan')
        ->assertSee('Tahap 1 dari 4');

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

it('cancels a terdaftar choice and restores it with Urungkan', function (): void {
    $application = TalentApplication::factory()->create([
        'student_id' => $this->nadia->getKey(), 'talent_type_id' => $this->osn->getKey(), 'talent_branch_id' => $this->kimia->getKey(),
    ]);

    $component = Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->assertSee('Batalkan OSN · Kimia')
        ->call('batalkan', $application->getKey())
        ->assertSee('OSN · Kimia dibatalkan.')
        ->assertSee('Urungkan');

    expect($application->fresh()->trashed())->toBeTrue();

    $component->call('urungkan')->assertSet('undo', null);
    expect($application->fresh()->trashed())->toBeFalse();
});

it('does not offer or allow cancelling a choice Kesiswaan already moved, nor someone else\'s', function (): void {
    $mapped = TalentApplication::factory()->status(TalentStatus::Pemetaan)->create([
        'student_id' => $this->nadia->getKey(), 'talent_type_id' => $this->osn->getKey(), 'talent_branch_id' => $this->kimia->getKey(),
    ]);
    $theirs = TalentApplication::factory()->create([
        'student_id' => F::student('Dimas Arya Nugroho')->getKey(),
        'talent_type_id' => $this->osn->getKey(),
        'talent_branch_id' => $this->osn->branches()->where('name', 'Fisika')->value('id'),
    ]);

    Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->assertDontSee('Batalkan OSN · Kimia')
        ->call('batalkan', $mapped->getKey())
        ->assertForbidden();

    Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->call('batalkan', $theirs->getKey())
        ->assertNotFound();

    expect($mapped->fresh()->trashed())->toBeFalse()
        ->and($theirs->fresh()->trashed())->toBeFalse();
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
