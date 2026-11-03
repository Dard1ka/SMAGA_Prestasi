<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Livewire\Admin\Pengaturan\Pengguna;
use App\Livewire\Kesiswaan\Lomba\Form;
use App\Livewire\Kesiswaan\Talenta;
use App\Livewire\Siswa\Lomba\Usulkan;
use App\Models\Classroom;
use App\Models\TalentApplication;
use App\Models\TalentType;
use Database\Seeders\TalentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Auth\AuthTestHelpers as H;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;
use Tests\Feature\Siswa\Lomba\LombaFixtures as L;

/*
| FS1 QA round 2 (tracks QA1, QA2 and QA3), staff screens: the link field named the same in the Kesiswaan drawer as on
| the student's Usulkan lomba page, category B naming BUMD in the drawer legend, the Kelas filter of "Belum mengisi
| (kelas X)" offering only kelas X, the eye button of Database talenta siswa inside its cell, and the Admin user page
| saying where a student found by a full NISN in the top bar search stands. Staff copy has no pronoun (D-119).
| Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();
    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
});

/** The rendered page without Livewire's block markers. */
function fs1qa2StaffPlain(string $html): string
{
    return (string) preg_replace('/<!--\[if (?:END)?BLOCK\]><!\[endif\]-->/', '', $html);
}

it('names the official link "Link resmi" in the Kesiswaan drawer, as on Usulkan lomba (BUG-LOMBA-09)', function (): void {
    $student = L::student();
    $lomba = L::pendingCompetition(L::user($student), L::usulanAttributes(['name' => 'Usulan Link Fiktif']));

    Livewire::actingAs($this->kesiswaan)->test(Form::class)->call('open', $lomba->getKey())
        ->assertSee('Link resmi')
        ->assertDontSee('Website resmi')
        ->assertSee('Link registrasi');

    Livewire::actingAs(L::user($student))->test(Usulkan::class)
        ->assertSee('Link resmi')
        ->assertDontSee('Website resmi');
});

it('names BUMD under category B in the drawer legend (BUG-LOMBA-12)', function (): void {
    $lomba = L::pendingCompetition(L::user(L::student()), L::usulanAttributes(['name' => 'Usulan BUMD Fiktif']));

    Livewire::actingAs($this->kesiswaan)->test(Form::class)->call('open', $lomba->getKey())
        ->assertSee('A Puspresnas · B Pemerintah pusat/daerah, BUMN/BUMD · C Federasi, perguruan tinggi, organisasi profesi · D Swasta, yayasan, lembaga · E Lainnya/umum')
        ->assertDontSee('B Pemerintah/BUMN ·');
});

it('offers only the kelas X classes on "Belum mengisi (kelas X)" (BUG-N2)', function (): void {
    $this->seed(TalentSeeder::class);
    F::student('Gita Permata', 'X-B');
    $xi = Classroom::query()->where('name', 'XI-B')->first() ?? Classroom::factory()->level(11)->create(['name' => 'XI-B']);
    Classroom::query()->where('name', 'XII-A')->first() ?? Classroom::factory()->level(12)->create(['name' => 'XII-A']);

    $semua = Livewire::actingAs($this->kesiswaan)->test(Talenta::class);
    expect(array_keys($semua->viewData('classes')))->toContain('Kelas X', 'Kelas XI', 'Kelas XII');

    $belum = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)->call('setTab', 'belum');
    expect(array_keys($belum->viewData('classes')))->toBe(['Kelas X']);

    $html = fs1qa2StaffPlain($belum->html());
    expect($html)->toContain('X-B')->not->toContain('XI-B')->not->toContain('XII-A');

    // A class outside kelas X can never match this tab: it is dropped, not shown as an empty result.
    $belum->set('kelas', (string) $xi->getKey())
        ->assertSet('kelas', '')
        ->assertSee('Gita Permata')
        ->assertDontSee('Tidak ada siswa di sini');

    // On "Semua" the XI class stays a valid filter.
    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('kelas', (string) $xi->getKey())
        ->assertSet('kelas', (string) $xi->getKey());

    // The same address opened directly (?tab=belum&kelas=<XI>) drops it too.
    Livewire::withQueryParams(['tab' => 'belum', 'kelas' => (string) $xi->getKey()])
        ->actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertSet('kelas', '')
        ->assertSee('Gita Permata');
});

it('gives the eye button of Database talenta siswa a cell it fits in (BUG-N3)', function (): void {
    $this->seed(TalentSeeder::class);
    $student = F::student('Kirana Ayu Lestari', 'X-B');
    $row = new TalentApplication;
    $row->forceFill([
        'student_id' => $student->getKey(),
        'talent_type_id' => TalentType::query()->where('code', TalentTypeCode::Lainnya)->sole()->getKey(),
        'other_text' => 'Menulis cerpen',
        'status' => TalentStatus::Terdaftar,
    ])->save();

    $html = fs1qa2StaffPlain(Livewire::actingAs($this->kesiswaan)->test(Talenta::class)->html());

    // 12px left + 44px button (touch size) + 18px right = 74px: the column is 80px and the button sits at its right
    // edge, 18px inside the card like every last cell (it was a 56px column the button overflowed).
    expect($html)->toContain('<th scope="col" class="w-20"><span class="sr-only">Aksi</span></th>')
        ->not->toContain('<th scope="col" class="w-14">')
        ->toMatch('/<td class="text-right">\s*<button[^>]*aria-label="Lihat Kirana Ayu Lestari/s');
});

it('says on the first screen where the student found by a full NISN stands (QA-DC-05)', function (): void {
    $admin = A::admin();
    $this->actingAs($admin);
    session(A::passwordConfirmed());
    $student = H::activeStudent();
    $name = $student->user->name;

    $html = fs1qa2StaffPlain(Livewire::withQueryParams(['q' => H::NISN])->test(Pengguna::class)
        ->assertSet('studentUserId', $student->user_id)
        ->html());

    // The note stands above the staff table and links to the result in "Bantuan akun siswa".
    expect($html)->toMatch('/Hasil NISN '.H::NISN.'.*Akun siswa '.preg_quote($name, '/').' ada di Bantuan akun siswa\..*href="#h-bantuan-siswa".*Akun staf per peran.*id="h-bantuan-siswa"/s');

    // A NISN nobody has says so there too.
    Livewire::withQueryParams(['q' => '0011223344'])->test(Pengguna::class)
        ->assertSet('studentUserId', null)
        ->assertSee('Tidak ada siswa dengan NISN 0011223344.');

    // A name search or no search shows no such note.
    Livewire::withQueryParams(['q' => 'Hendra'])->test(Pengguna::class)->assertDontSee('Hasil NISN');
    Livewire::withQueryParams([])->test(Pengguna::class)->assertDontSee('Hasil NISN');
});
