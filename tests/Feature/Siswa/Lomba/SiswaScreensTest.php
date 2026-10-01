<?php

declare(strict_types=1);

use App\Actions\Registrations\CreateRegistration;
use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Siswa\KonfirmasiTim;
use App\Livewire\Siswa\Lomba\Katalog;
use App\Livewire\Siswa\Pendaftaran\Form;
use App\Livewire\Siswa\TurMenu;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->withoutVite();
    $this->guru = F::staff(RoleName::Guru);
    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $this->keisha = F::student('Keisha Larasati', 'XI-6');
    User::query()->whereKey([$this->nadia->user_id, $this->dimas->user_id, $this->keisha->user_id])->update(['tour_seen_at' => now()]);
});

// --- access ------------------------------------------------------------------------------------

it('sends guests to login and blocks other roles from the siswa area', function (string $route): void {
    $lomba = F::openCompetition();
    $url = route($route, $route === 'siswa.lomba.show' ? $lomba : []);

    $this->get($url)->assertRedirect(route('login'));
    $this->actingAs(F::staff(RoleName::Kesiswaan))->get($url)->assertForbidden();
})->with(['siswa.beranda', 'siswa.lomba.index', 'siswa.lomba.show', 'siswa.bantuan']);

it('sends a student who has not finished activation to aktivasi', function (): void {
    $pending = F::student('Hana Safira', 'X-2', ['status' => UserStatus::PendingActivation, 'must_change_password' => true]);

    $this->actingAs(F::user($pending))->get(route('siswa.beranda'))->assertRedirect(route('aktivasi'));
});

it('renders every W2-A1 siswa page', function (): void {
    $lomba = F::openCompetition();

    $this->actingAs(F::user($this->nadia));
    $this->get(route('siswa.beranda'))->assertOk()->assertSee('Halo, Nadia');
    $this->get(route('siswa.lomba.index'))->assertOk()->assertSee($lomba->name)->assertSee('Usulkan lomba');
    $this->get(route('siswa.lomba.show', $lomba))->assertOk()->assertSee('Daftar lomba')->assertSee('Kirim pendaftaran');
    $this->get(route('siswa.bantuan'))->assertOk()->assertSee('Tur singkat menu');
});

// --- katalog -----------------------------------------------------------------------------------

it('lists only published lomba in the catalog (D-27)', function (): void {
    $published = F::openCompetition(['name' => 'OSN Tingkat Kota Semarang 2026']);
    F::pendingCompetition(F::user($this->nadia), ['name' => 'Usulan Nadia']);
    Competition::factory()->rejected()->create(['name' => 'Lomba Ditolak']);

    $this->actingAs(F::user($this->nadia));
    Livewire::test(Katalog::class)
        ->assertSee($published->name)
        ->assertDontSee('Usulan Nadia')
        ->assertDontSee('Lomba Ditolak');
});

it('filters the catalog by bidang, tingkat, status and name', function (): void {
    F::openCompetition(['name' => 'OSN Kota 2026', 'bidang' => Bidang::Sains, 'level' => Level::Kota]);
    F::openCompetition(['name' => 'FLS3N Kota 2026', 'bidang' => Bidang::SeniBudaya, 'level' => Level::Kota]);
    F::openCompetition(['name' => 'KRENOVA 2027', 'bidang' => Bidang::Riset, 'level' => Level::Kota, 'registration_opens_at' => now()->addMonth(), 'registration_closes_at' => now()->addMonths(2)]);
    F::openCompetition(['name' => 'Pencak Silat Jateng', 'bidang' => Bidang::Olahraga, 'level' => Level::Provinsi, 'registration_closes_at' => now()->subDay()]);

    $this->actingAs(F::user($this->nadia));
    Livewire::test(Katalog::class)
        ->call('pilihBidang', 'sains')->assertSee('OSN Kota 2026')->assertDontSee('FLS3N Kota 2026')
        ->call('hapusFilter')->set('tingkat', 'provinsi')->assertSee('Pencak Silat Jateng')->assertDontSee('OSN Kota 2026')
        ->call('hapusFilter')->set('status', 'segera')->assertSee('KRENOVA 2027')->assertSee('Segera dibuka')->assertDontSee('OSN Kota 2026')
        ->call('hapusFilter')->set('status', 'ditutup')->assertSee('Pencak Silat Jateng')->assertDontSee('KRENOVA 2027')
        ->call('hapusFilter')->set('q', 'fls3n')->assertSee('FLS3N Kota 2026')->assertDontSee('OSN Kota 2026')
        ->call('hapusFilter')->set('q', 'tidak ada')->assertSee('Belum ada lomba yang cocok')
        ->set('bidang', 'bukan-bidang')->assertSet('bidang', '');
});

// --- detail ------------------------------------------------------------------------------------

it('hides an unpublished lomba from everyone but its submitter (404)', function (): void {
    $usulan = F::pendingCompetition(F::user($this->nadia));

    $this->actingAs(F::user($this->dimas))->get(route('siswa.lomba.show', $usulan))->assertNotFound();
    $this->actingAs(F::user($this->nadia))->get(route('siswa.lomba.show', $usulan))->assertOk()->assertSee('Belum tampil di katalog');
});

it('shows external links with their host and safe rel attributes, never unsafe ones', function (): void {
    $lomba = F::openCompetition([
        'website_url' => 'https://nsif.example.id/info',
        'registration_url' => 'javascript:alert(1)',
    ]);

    $this->actingAs(F::user($this->nadia))->get(route('siswa.lomba.show', $lomba))
        ->assertOk()
        ->assertSee('nsif.example.id')
        ->assertSee('rel="nofollow noopener noreferrer ugc"', false)
        ->assertDontSee('javascript:alert(1)', false);
});

// --- daftar panel ------------------------------------------------------------------------------

it('registers an individu entry from the panel', function (): void {
    $lomba = F::openCompetition();
    $this->actingAs(F::user($this->nadia));

    Livewire::test(Form::class, ['competitionId' => $lomba->getKey()])
        ->set('mentorId', $this->guru->id)
        ->call('kirim')
        ->assertHasErrors(['setuju'])
        ->set('setuju', true)
        ->call('kirim')
        ->assertHasNoErrors()
        ->assertSee('Pendaftaran terkirim')
        ->assertSee('Menunggu Kepala Sekolah');

    expect(Registration::query()->sole()->status)->toBe(RegistrationStatus::MenungguKepsek);
});

it('builds a team through the member search and sends invitations', function (): void {
    $lomba = F::teamCompetition(3);
    $this->actingAs(F::user($this->nadia));

    Livewire::test(Form::class, ['competitionId' => $lomba->getKey()])
        ->assertSet('type', 'tim')
        ->set('q', 'Dim')
        ->assertSee('Dimas Arya Nugroho')
        ->call('tambah', $this->dimas->getKey())
        ->set('mentorId', $this->guru->id)
        ->set('setuju', true)
        ->call('kirim')
        ->assertHasNoErrors()
        ->assertSee('Anggota konfirmasi')
        ->assertSee('0 dari 1 anggota sudah konfirmasi');

    $registration = Registration::query()->sole();
    expect($registration->type)->toBe(RegistrationType::Tim)
        ->and($registration->members()->where('student_id', $this->dimas->getKey())->exists())->toBeTrue();
});

it('returns only name and class from the member search, with exact NIS/NISN matching (SECURITY §3m-3)', function (): void {
    $lomba = F::teamCompetition(3);
    F::student('Dimas Alumni', 'XII-1', ['status' => UserStatus::Archived]);
    $this->actingAs(F::user($this->nadia));

    $form = Livewire::test(Form::class, ['competitionId' => $lomba->getKey()]);

    $form->set('q', 'Di')->assertSet('hasil', [])->assertSee('minimal 3 huruf');

    $form->set('q', 'Dimas');
    $hasil = $form->get('hasil');
    expect($hasil)->toHaveCount(1)
        ->and(array_keys($hasil[0]))->toBe(['id', 'name', 'kelas', 'busy', 'declined']) // B3: declined = refused this team before
        ->and($hasil[0]['name'])->toBe('Dimas Arya Nugroho'); // archived student excluded

    $form->set('q', substr($this->keisha->nis, 0, 5))->assertSet('hasil', []);
    $form->set('q', $this->keisha->nis);
    expect($form->get('hasil')[0]['name'])->toBe('Keisha Larasati');
    $form->set('q', 'Nadia')->assertSet('hasil', []); // never yourself
});

it('rate-limits the member search per user', function (): void {
    $lomba = F::teamCompetition(3);
    $user = F::user($this->nadia);
    $this->actingAs($user);
    RateLimiter::increment('anggota-menit:'.$user->id, 1, 30);

    Livewire::test(Form::class, ['competitionId' => $lomba->getKey()])
        ->set('q', 'Dimas')
        ->assertSet('hasil', [])
        ->assertSee('Terlalu banyak pencarian');
});

it('lets the leader cancel from the panel', function (): void {
    $lomba = F::teamCompetition(3);
    app(CreateRegistration::class)->handle(F::user($this->nadia), $lomba, RegistrationType::Tim, [$this->dimas->getKey()], $this->guru->id);
    $this->actingAs(F::user($this->nadia));

    Livewire::test(Form::class, ['competitionId' => $lomba->getKey()])
        ->assertSee('Batalkan pendaftaran')
        ->call('batalkan')
        ->assertSee('Kirim pendaftaran');

    expect(Registration::query()->sole()->status)->toBe(RegistrationStatus::Dibatalkan);
});

// --- konfirmasi tim ----------------------------------------------------------------------------

it('lets the invitee confirm on the invitation page and hides it from outsiders', function (): void {
    $registration = app(CreateRegistration::class)->handle(F::user($this->nadia), F::teamCompetition(3), RegistrationType::Tim, [$this->dimas->getKey()], $this->guru->id);

    $this->actingAs(F::user($this->keisha))->get(route('siswa.undangan.show', $registration))->assertNotFound();
    $this->actingAs(F::user($this->nadia))->get(route('siswa.undangan.show', $registration))->assertOk()->assertDontSee('Ikut tim ini?');

    $this->actingAs(F::user($this->dimas));
    Livewire::test(KonfirmasiTim::class, ['registration' => $registration])
        ->assertSee('Ikut tim ini?')
        ->call('ikut')
        ->assertRedirect(route('siswa.undangan.show', $registration));

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
});

it('lets the invitee decline', function (): void {
    $registration = app(CreateRegistration::class)->handle(F::user($this->nadia), F::teamCompetition(3), RegistrationType::Tim, [$this->dimas->getKey()], $this->guru->id);
    $this->actingAs(F::user($this->dimas));

    Livewire::test(KonfirmasiTim::class, ['registration' => $registration])->call('tolak')->assertRedirect(route('siswa.beranda'));

    expect($registration->members()->where('student_id', $this->dimas->getKey())->value('declined_at'))->not->toBeNull();
});

// --- beranda + tur -----------------------------------------------------------------------------

it('shows invitations, own registrations and deadlines on the beranda', function (): void {
    $lomba = F::teamCompetition(3, ['name' => 'Lomba Peneliti Belia (LPB) 2026']);
    app(CreateRegistration::class)->handle(F::user($this->nadia), $lomba, RegistrationType::Tim, [$this->dimas->getKey()], $this->guru->id);

    $this->actingAs(F::user($this->dimas))->get(route('siswa.beranda'))
        ->assertOk()
        ->assertSee('Perlu tindakan')
        ->assertSee('Undangan tim · Lomba Peneliti Belia (LPB) 2026')
        ->assertSee('Batas daftar terdekat');

    $this->actingAs(F::user($this->nadia))->get(route('siswa.beranda'))
        ->assertOk()
        ->assertSee('Lomba Peneliti Belia (LPB) 2026')
        ->assertSee('Menunggu konfirmasi anggota')
        ->assertSee('Tahap');
});

it('shows the tour until it is skipped, and again from /siswa/tur', function (): void {
    $user = F::user($this->nadia);
    $user->forceFill(['tour_seen_at' => null])->save();
    $this->actingAs($user);

    $this->get(route('siswa.beranda'))->assertSee('Tur singkat menu')->assertSee('Mulai tur');

    Livewire::test(TurMenu::class)
        ->assertSet('show', true)
        ->call('mulai')->assertSet('step', 1)->assertSee('Cari lomba dari sekolah')
        ->call('lanjut', 5)->call('lanjut', 5)->call('lanjut', 5)->call('lanjut', 5)->assertSet('step', 5)
        ->call('lanjut', 5)->assertSet('step', TurMenu::DONE)->assertSee('Siap dipakai!')
        ->call('kembali', 4)->assertSet('step', 4)
        ->call('selesai')->assertSet('show', false);

    expect($user->fresh()->tour_seen_at)->not->toBeNull();
    $this->get(route('siswa.beranda'))->assertDontSee('Mulai tur');

    $this->get(route('siswa.tour'))->assertRedirect(route('siswa.beranda', ['tur' => 1]));
    $this->get(route('siswa.beranda', ['tur' => 1]))->assertSee('Mulai tur');
});
