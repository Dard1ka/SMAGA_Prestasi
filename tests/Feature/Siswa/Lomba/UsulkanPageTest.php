<?php

declare(strict_types=1);

use App\Enums\CompetitionStatus;
use App\Enums\RoleName;
use App\Enums\WeightCategory;
use App\Livewire\Siswa\Lomba\Usulkan;
use App\Livewire\Siswa\Pendaftaran\Form as PendaftaranForm;
use App\Models\Competition;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| Siswa › Usulkan lomba (`siswa.lomba.usulkan`, D-145): a full page instead of the old sheet. Step 1 takes the whole
| lomba (shared CompetitionFields, same groups as the Kesiswaan drawer), step 2 the registration panel of that lomba.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    K::boot();
    Notification::fake();
    $this->withoutVite();
    $this->nadiaUser = F::user(F::student());
});

it('opens for students only (role × route)', function (RoleName $role): void {
    $this->actingAs(K::staff($role, 'Staf '.$role->value))->get('/siswa/lomba/usulkan')->assertForbidden();
})->with([RoleName::Guru, RoleName::Kesiswaan, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas, RoleName::Admin]);

it('sends guests to the login page', fn () => $this->get('/siswa/lomba/usulkan')->assertRedirect(route('login')));

it('renders step 1 with the grouped fields', function (): void {
    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba.usulkan'))->assertOk()
        ->assertSee('Usulkan lomba')->assertSee('Data lomba')
        ->assertSee('Penyelenggara')->assertSee('Pendaftaran ditutup')->assertSee('Pelaksanaan')->assertSee('Berkas')
        ->assertSee('Ajang Puspresnas')->assertSee('Durasi lomba')->assertSee('Juknis / brosur / undangan')
        ->assertSee('Salin dari kolom alamat browser, diawali https://. Kesiswaan memeriksa link ini dulu.')
        ->assertDontSee('Kategori bobot')->assertDontSee('Status ajang')->assertDontSee('Guru pembimbing default');
});

it('shows the computed category and the estimate as soon as the fields allow', function (): void {
    Livewire::actingAs($this->nadiaUser)->test(Usulkan::class)
        ->set('form.organizer_type', 'bumn_bumd')->assertSee('Kategori B · bobot 0,80')->assertDontSee('Perkiraan poin jika Juara I')
        ->assertSet('form.weight_category', 'B')
        ->set('form.level', 'nasional')->assertSee('Perkiraan poin jika Juara I')
        ->set('form.puspresnas', true)->assertSee('Kategori A · bobot 1,00');
});

it('saves and moves on to the registration form of that lomba', function (): void {
    $t = Livewire::actingAs($this->nadiaUser)->test(Usulkan::class);

    foreach (F::usulan() as $k => $v) {
        $t->set("form.$k", $v);
    }

    $t->set('form.juknis', K::pdf())->call('simpan')->assertHasNoErrors()
        ->assertSee('Pendaftaran lomba')->assertSee('Nanti saja')
        ->assertSeeLivewire(PendaftaranForm::class)
        ->assertDispatched('sth-toast', message: 'Usulan lomba tersimpan dan menunggu verifikasi Kesiswaan.', tone: 'success');

    $c = Competition::query()->sole();
    $t->assertSet('competitionId', $c->getKey())->assertSeeHtml('href="'.route('siswa.lomba.show', $c).'"');

    expect($c->status)->toBe(CompetitionStatus::MenungguVerifikasi)
        ->and($c->weight_category)->toBe(WeightCategory::C)
        ->and($c->juknis_file_id)->not->toBeNull();

    // A second click on the saved page sends nothing more.
    $t->call('simpan');
    expect(Competition::query()->count())->toBe(1);
});

it('shows Action errors on their fields, the daily limit included', function (): void {
    Competition::factory()->count(5)->create(['submitted_by' => $this->nadiaUser->getKey()]);
    $t = Livewire::actingAs($this->nadiaUser)->test(Usulkan::class);

    foreach (F::usulan() as $k => $v) {
        $t->set("form.$k", $v);
    }

    $t->set('form.juknis', K::pdf())->call('simpan')
        ->assertHasErrors('form.name')
        ->assertSee('Sudah 5 kali mengirim hari ini. Coba lagi besok.')
        ->assertDispatched('sth-form-invalid', form: 'usulkan')
        ->assertSet('competitionId', null);
});

it('names every missing field at once, the juknis included', function (): void {
    Livewire::actingAs($this->nadiaUser)->test(Usulkan::class)
        ->set('form.name', 'ISPO 2027')
        ->call('simpan')
        ->assertHasErrors(['form.juknis', 'form.bidang', 'form.level', 'form.organizer_type', 'form.registration_closes_at', 'form.final_on', 'form.website_url'])
        ->assertSee('Upload juknis, brosur, atau undangan.');

    expect(Competition::query()->count())->toBe(0);
});

it('refuses a juknis that is no PDF or picture as soon as it is chosen', function (): void {
    Livewire::actingAs($this->nadiaUser)->test(Usulkan::class)
        ->set('form.juknis', UploadedFile::fake()->create('juknis.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
        ->assertHasErrors('form.juknis')
        ->assertSee('Pilih PDF, JPG, PNG, atau WEBP.');

    expect(File::query()->count())->toBe(0);
});

it('shows one date for a one-day lomba and two for a longer one', function (): void {
    Livewire::actingAs($this->nadiaUser)->test(Usulkan::class)
        ->assertSee('Tanggal lomba')->assertDontSee('Tanggal selesai')
        ->set('form.duration', 'lebih_dari_satu_hari')
        ->assertSee('Tanggal mulai')->assertSee('Tanggal selesai')->assertDontSee('Tanggal lomba');
});

it('links "Usulkan lomba" on Cari lomba to the new page', function (): void {
    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba.index'))
        ->assertOk()
        ->assertSee(route('siswa.lomba.usulkan'), false)
        ->assertDontSee('data-sth-open="usul"', false);
});
