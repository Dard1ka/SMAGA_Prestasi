<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Livewire\Siswa\ProfilTalenta;
use App\Models\TalentApplication;
use App\Models\TalentProfile;
use App\Models\TalentType;
use App\Notifications\Talent\TalentReviewRequested;
use Database\Seeders\TalentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

it('renders the seven jenis from the DB with bidang, and the account data', function (): void {
    $this->actingAs($this->nadia->user)
        ->get(route('siswa.talenta'))
        ->assertOk()
        ->assertSee('<title>Profil talenta · SMAGA Talent Hub</title>', false)
        ->assertSeeInOrder(['OSN', 'Riset', 'FLS3N', 'O2SN', 'OLPAIN', 'Debat', 'Lainnya'])
        ->assertSee('Seni Budaya')
        ->assertSee('Nadia Putri Anindya')
        ->assertSee('NISN '.$this->nadia->nisn)
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
        // QA9: the own choice of a stale tab (or a forged call) gets a toast, not a 403 page; nothing is cancelled.
        ->assertDispatched('sth-toast', message: 'Talenta ini sudah diproses Kesiswaan dan tidak bisa dibatalkan.', tone: 'warning');

    Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->call('batalkan', $theirs->getKey())
        ->assertNotFound();

    expect($mapped->fresh()->trashed())->toBeFalse()
        ->and($theirs->fresh()->trashed())->toBeFalse();
});

// Catatan untuk siswa + Minta ditinjau ulang (lead, 2026-10-04) ----------------------------------

it('shows the note for the student next to the choice, never the note for the guru pembina', function (): void {
    TalentApplication::factory()->status(TalentStatus::Pemetaan)->create([
        'student_id' => $this->nadia->getKey(), 'talent_type_id' => $this->osn->getKey(), 'talent_branch_id' => $this->kimia->getKey(),
        'note' => 'Internal: tanya wali kelas dulu.',
        'student_note' => 'Siapkan rapor semester 1.',
    ]);

    Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->assertSee('Catatan dari Kesiswaan:')
        ->assertSee('Siapkan rapor semester 1.')
        ->assertDontSee('tanya wali kelas')
        ->assertDontSee('Minta ditinjau ulang');
});

// M2-B (QA Q2-14): a note Kesiswaan only saved with "Simpan" is a draft. The student reads the sent note only.
it('never shows the student a note that is still a draft', function (): void {
    TalentApplication::factory()->status(TalentStatus::Pemetaan)->create([
        'student_id' => $this->nadia->getKey(), 'talent_type_id' => $this->osn->getKey(), 'talent_branch_id' => $this->kimia->getKey(),
        'student_note' => 'Siapkan rapor semester 1.',
        'student_note_draft' => 'Draf: nilai asesmen belum cukup.',
    ]);
    TalentApplication::factory()->status(TalentStatus::Terdaftar)->create([
        'student_id' => $this->nadia->getKey(), 'talent_type_id' => $this->osn->getKey(),
        'talent_branch_id' => $this->osn->branches()->where('name', '!=', 'Kimia')->firstOrFail()->getKey(),
        'student_note_draft' => 'Draf kedua yang belum terkirim.',
    ]);

    Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->assertSee('Siapkan rapor semester 1.')
        ->assertDontSee('nilai asesmen belum cukup')
        ->assertDontSee('Draf kedua');

    $this->actingAs($this->nadia->user)
        ->get(route('siswa.talenta'))
        ->assertOk()
        ->assertDontSee('nilai asesmen belum cukup')
        ->assertDontSee('Draf kedua');
});

it('asks for a second look at a rejected choice, once per rejection', function (): void {
    Notification::fake();
    $kesiswaan = F::staff(RoleName::Kesiswaan, 'Ibu Dwi Rahayu');
    $application = TalentApplication::factory()->rejected(TalentStatus::Seleksi, 'Nilai asesmen kurang.')->create([
        'student_id' => $this->nadia->getKey(), 'talent_type_id' => $this->osn->getKey(), 'talent_branch_id' => $this->kimia->getKey(),
    ]);

    $component = Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->assertSee('Belum lolos tahun ini')
        ->assertSee('Nilai asesmen kurang.')
        ->assertSeeHtml('aria-label="Minta ditinjau ulang OSN · Kimia"')
        ->call('mintaTinjau', $application->getKey())
        ->assertSet('tinjauId', $application->getKey())
        ->assertDispatched('sth-modal-open', id: 'tinjau-ulang')
        ->set('pesanTinjau', str_repeat('a', 501))
        ->call('kirimTinjau')
        ->assertHasErrors('pesanTinjau');

    expect($application->fresh()->review_requested_at)->toBeNull();

    $component->set('pesanTinjau', 'Saya sakit saat asesmen, mohon dijadwalkan ulang.')
        ->call('kirimTinjau')
        ->assertHasNoErrors()
        ->assertSet('tinjauId', null)
        ->assertDispatched('sth-modal-close', id: 'tinjau-ulang')
        ->assertDispatched('sth-toast', message: 'Permintaan tinjau ulang OSN · Kimia terkirim ke Kesiswaan.', tone: 'success')
        ->assertSee('Menunggu tinjauan ulang')
        ->assertSee('permintaan tinjau ulang terkirim')
        ->assertDontSeeHtml('aria-label="Minta ditinjau ulang OSN · Kimia"');

    $fresh = $application->fresh();
    expect($fresh->status)->toBe(TalentStatus::TidakLolos)
        ->and($fresh->review_requested_at)->not->toBeNull()
        ->and($fresh->review_message)->toBe('Saya sakit saat asesmen, mohon dijadwalkan ulang.');
    Notification::assertSentTo($kesiswaan, TalentReviewRequested::class);

    // A second request for the same rejection is refused (no button; a stale or forged call gets a toast, QA9).
    Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->call('mintaTinjau', $application->getKey())
        ->assertDispatched('sth-toast', message: 'Permintaanmu sudah terkirim. Tunggu kabar dari Kesiswaan.', tone: 'warning')
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('tinjauId', null);

    Notification::assertSentToTimes($kesiswaan, TalentReviewRequested::class, 1);
});

it('sends a review request without a message', function (): void {
    Notification::fake();
    $application = TalentApplication::factory()->rejected()->create([
        'student_id' => $this->nadia->getKey(), 'talent_type_id' => $this->osn->getKey(), 'talent_branch_id' => $this->kimia->getKey(),
    ]);

    Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->call('mintaTinjau', $application->getKey())
        ->call('kirimTinjau')
        ->assertHasNoErrors();

    expect($application->fresh()->review_requested_at)->not->toBeNull()
        ->and($application->fresh()->review_message)->toBeNull();
});

it('refuses a review request for someone else\'s choice (404) and for a choice that is not rejected (toast, QA9)', function (): void {
    Notification::fake();
    $mine = TalentApplication::factory()->status(TalentStatus::Seleksi)->create([
        'student_id' => $this->nadia->getKey(), 'talent_type_id' => $this->osn->getKey(), 'talent_branch_id' => $this->kimia->getKey(),
    ]);
    $theirs = TalentApplication::factory()->rejected()->create([
        'student_id' => F::student('Dimas Arya Nugroho')->getKey(),
        'talent_type_id' => $this->osn->getKey(),
        'talent_branch_id' => $this->osn->branches()->where('name', 'Fisika')->value('id'),
    ]);

    Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class)
        ->assertDontSee('Minta ditinjau ulang')
        ->call('mintaTinjau', $mine->getKey())
        ->assertDispatched('sth-toast', message: 'Pilihan ini sudah dibuka kembali.', tone: 'warning')
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('tinjauId', null);

    Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class)
        ->call('mintaTinjau', $theirs->getKey())
        ->assertNotFound();

    // The id in the dialog is locked: the browser cannot swap in another student's choice.
    expect(fn () => Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class)->set('tinjauId', $theirs->getKey()))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect($theirs->fresh()->review_requested_at)->toBeNull()
        ->and($mine->fresh()->review_requested_at)->toBeNull();
    Notification::assertNothingSent();
});

it('says so when the choice was restored while the dialog was open', function (): void {
    Notification::fake();
    $application = TalentApplication::factory()->rejected(TalentStatus::Pemetaan)->create([
        'student_id' => $this->nadia->getKey(), 'talent_type_id' => $this->osn->getKey(), 'talent_branch_id' => $this->kimia->getKey(),
    ]);

    $component = Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class)->call('mintaTinjau', $application->getKey());

    $application->forceFill(['status' => TalentStatus::Pemetaan, 'rejected_from' => null])->save();

    $component->call('kirimTinjau')
        ->assertDispatched('sth-toast', message: 'Permintaan sudah terkirim atau pilihan ini sudah dibuka kembali.', tone: 'error')
        ->assertSee('Sedang dipetakan Kesiswaan');

    expect($application->fresh()->review_requested_at)->toBeNull();
    Notification::assertNothingSent();
});

it('limits how many review requests one student sends per hour', function (): void {
    Notification::fake();
    $application = TalentApplication::factory()->rejected()->create([
        'student_id' => $this->nadia->getKey(), 'talent_type_id' => $this->osn->getKey(), 'talent_branch_id' => $this->kimia->getKey(),
    ]);

    foreach (range(1, ProfilTalenta::REVIEWS_PER_HOUR) as $i) {
        RateLimiter::hit('talenta-tinjau:'.$this->nadia->user->getKey(), 3600);
    }

    Livewire::actingAs($this->nadia->user)->test(ProfilTalenta::class)
        ->call('mintaTinjau', $application->getKey())
        ->call('kirimTinjau')
        ->assertHasErrors('pesanTinjau');

    expect($application->fresh()->review_requested_at)->toBeNull();
    RateLimiter::clear('talenta-tinjau:'.$this->nadia->user->getKey());
});

it('points a rejected student to "Minta ditinjau ulang" instead of a second application', function (): void {
    TalentApplication::factory()->rejected()->create([
        'student_id' => $this->nadia->getKey(), 'talent_type_id' => $this->osn->getKey(), 'talent_branch_id' => $this->kimia->getKey(),
    ]);

    Livewire::actingAs($this->nadia->user)
        ->test(ProfilTalenta::class)
        ->call('pilihJenis', $this->osn->getKey())
        ->call('pilihCabang', $this->kimia->getKey())
        ->assertSee('Pilihan ini belum lolos. Pakai "Minta ditinjau ulang" di daftar talenta pilihanmu.')
        ->assertDontSee('Sudah dipilih. Pilih cabang lain.')
        ->call('daftar')
        ->assertHasErrors('cabang');

    expect(TalentApplication::withTrashed()->where('student_id', $this->nadia->getKey())->count())->toBe(1);
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
