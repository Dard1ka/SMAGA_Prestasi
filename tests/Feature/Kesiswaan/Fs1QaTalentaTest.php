<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Livewire\Auth\Activation;
use App\Livewire\Guru\Binaan;
use App\Livewire\Kesiswaan\Talenta;
use App\Livewire\Siswa\Beranda;
use App\Livewire\Siswa\ProfilTalenta;
use App\Mail\OtpCodeMail;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\TalentBranch;
use App\Models\TalentType;
use App\Models\User;
use App\Support\Navigation;
use Database\Seeders\TalentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Guru\GuruFixtures;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;
use Tests\Feature\Siswa\Lomba\LombaFixtures as L;

/*
| FS1 QA round 1 (track QA3), talent screens: Database talenta siswa at 1366 and 390 px (fit, a panel that comes into
| view, the "Belum mengisi" tab without filters it ignores), one label per talent everywhere, row buttons that name the
| talent, Guru › Talenta and the jenis tiles of Profil talenta on a phone, the recommendation list on Beranda, and the
| kelas X gate saying what it holds (activation, menus, Bantuan). Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();
    $this->seed(TalentSeeder::class);
    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
});

/** A talent choice as ApplyTalent leaves it (`other_text` is not fillable). */
function fs1qaTalent(Student $student, TalentTypeCode $code, ?string $branch = null, ?string $other = null, ?User $mentor = null): TalentApplication
{
    $type = TalentType::query()->where('code', $code)->sole();
    $row = new TalentApplication;
    $row->forceFill([
        'student_id' => $student->getKey(),
        'talent_type_id' => $type->getKey(),
        'talent_branch_id' => $branch !== null ? TalentBranch::query()->where('talent_type_id', $type->getKey())->where('name', $branch)->value('id') : null,
        'other_text' => $other,
        'mentor_id' => $mentor?->getKey(),
        'status' => TalentStatus::Terdaftar,
    ])->save();

    return $row->fresh();
}

/** The rendered page without Livewire's block markers. */
function fs1qaTalentPlain(string $html): string
{
    return (string) preg_replace('/<!--\[if (?:END)?BLOCK\]><!\[endif\]-->/', '', $html);
}

it('writes a talent with free text one way in the table, the panel and the row buttons (BUG-05, BUG-10)', function (): void {
    $kirana = F::student('Kirana Ayu Lestari', 'X-B');
    $lainnya = fs1qaTalent($kirana, TalentTypeCode::Lainnya, other: 'Menulis cerpen');
    $osn = fs1qaTalent($kirana, TalentTypeCode::Osn, 'Lainnya', 'Olimpiade astrofisika');

    $html = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $lainnya->getKey())
        ->html();

    expect($html)
        ->toContain('Lainnya (Menulis cerpen)')
        ->toContain('OSN · Lainnya (Olimpiade astrofisika)')
        ->not->toContain('Lainnya · Menulis cerpen')
        // Every row button names the talent, not only the student.
        ->toContain('aria-label="Lihat Kirana Ayu Lestari – Lainnya (Menulis cerpen)"')
        ->toContain('aria-label="Lihat Kirana Ayu Lestari – OSN · Lainnya (Olimpiade astrofisika)"');

    expect($osn->title())->toBe('OSN · Lainnya (Olimpiade astrofisika)');
});

it('fits the table beside the panel at 1366 px and keeps the eye button on a phone (BUG-02, BUG-03)', function (): void {
    fs1qaTalent(F::student('Kirana Ayu Lestari', 'X-B'), TalentTypeCode::Osn, 'Kimia');

    $html = fs1qaTalentPlain(Livewire::actingAs($this->kesiswaan)->test(Talenta::class)->html());

    expect($html)
        // 600 px fits the 692 px card at 1366 px (it was 720); no floor below md.
        ->toContain('table-fixed md:min-w-[600px]')->not->toContain('min-w-[720px]')
        // Below md only Siswa and the button are columns; the talent stands under the name there.
        ->toContain('<th scope="col" class="hidden w-44 md:table-cell 2xl:w-52">Talenta</th>')
        ->toContain('<th scope="col" class="hidden w-20 md:table-cell">Prestasi</th>')
        ->toContain('<th scope="col" class="hidden w-36 md:table-cell 2xl:w-44">Guru pembina</th>')
        ->toContain('<span class="text-sm font-semibold [overflow-wrap:anywhere] md:hidden">OSN · Kimia</span>');
});

it('brings the panel into view after a row button (BUG-03)', function (): void {
    $row = fs1qaTalent(F::student('Kirana Ayu Lestari', 'X-B'), TalentTypeCode::Osn, 'Kimia');

    $component = Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('showPanel', $row->getKey())
        ->assertDispatched('sth-reveal', id: Talenta::PANEL_ID);

    expect($component->html())->toMatch('/<aside [^>]*id="'.Talenta::PANEL_ID.'" tabindex="-1"/');

    $script = (string) file_get_contents(resource_path('js/app.js'));
    expect($script)->toContain("window.addEventListener('sth-reveal'")
        ->toContain("target.scrollIntoView({ block: 'start', behavior: 'instant' });")
        ->toContain('target.focus({ preventScroll: true });');
});

it('leaves out the jenis and cabang filters on "Belum mengisi" and fits that list on a phone (BUG-04)', function (): void {
    F::student('Dimas Saputra Wibowo', 'X-E', nisn: '0071234567');

    $html = fs1qaTalentPlain(Livewire::actingAs($this->kesiswaan)->test(Talenta::class)->call('setTab', 'belum')->html());

    expect($html)
        ->not->toContain('aria-label="Jenis talenta"')
        ->not->toContain('Cabang talenta')
        ->not->toContain('disabled')
        ->toContain('Kelas')->toContain('Nama atau NISN')
        ->toContain('table-fixed sm:min-w-[560px]')
        ->toContain('<span class="text-xs text-muted sm:hidden">X-E · NISN <span class="num">0071234567</span></span>');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->assertSeeHtml('aria-label="Jenis talenta"')->assertSee('Cabang talenta');
});

it('writes the talent of a binaan one way and stacks it under the name on a phone (BUG-05, BUG-06)', function (): void {
    $retno = GuruFixtures::guru();
    $hana = F::student('Hana Safira', 'X-H');
    fs1qaTalent($hana, TalentTypeCode::Lainnya, other: 'Kaligrafi', mentor: $retno);
    fs1qaTalent($hana, TalentTypeCode::Fls3n, 'Lainnya', 'Fotografi jalanan', $retno);

    $html = fs1qaTalentPlain(Livewire::actingAs($retno)->test(Binaan::class)->call('setTab', 'talenta')->html());

    expect($html)
        ->toContain('Lainnya (Kaligrafi)')->not->toContain('Lainnya · Kaligrafi')
        ->toContain('FLS3N · Lainnya (Fotografi jalanan)')
        ->toContain('table-fixed sm:min-w-[480px]')
        ->toContain('<th scope="col" class="hidden w-56 sm:table-cell md:w-72">Talenta</th>')
        ->toContain('<span class="text-sm font-semibold [overflow-wrap:anywhere] sm:hidden">FLS3N · Lainnya (Fotografi jalanan)');
});

it('lets the bidang tag of a jenis tile wrap inside the tile on a phone (BUG-01)', function (): void {
    $student = L::student('Kirana Ayu Lestari', 'XI-B');

    $html = fs1qaTalentPlain(Livewire::actingAs(L::user($student))->test(ProfilTalenta::class)->html());

    expect($html)
        ->toMatch('/<span class="chip b-seni chip-sm chip-wrap">\s*Seni Budaya/')
        ->toMatch('/<span class="chip c-gray chip-sm chip-wrap">\s*Tulis sendiri/');
});

it('lists the bidang of the recommendations in plain Indonesian (BUG-09)', function (): void {
    $student = L::student('Kirana Ayu Lestari', 'XI-B');
    fs1qaTalent($student, TalentTypeCode::Osn, 'Kimia');
    fs1qaTalent($student, TalentTypeCode::Fls3n, 'Tari');
    L::openCompetition(['name' => 'Olimpiade Sains Fiktif 2026', 'bidang' => Bidang::Sains]);

    $page = fn () => Livewire::actingAs(L::user($student))->test(Beranda::class);
    $page()->assertSee('Sesuai bidang minatmu: Sains dan Seni Budaya')->assertDontSee('Sains &amp; Seni', false);

    fs1qaTalent($student, TalentTypeCode::O2sn, 'Atletik');
    $page()->assertSee('Sesuai bidang minatmu: Sains, Seni Budaya, dan Olahraga');

    expect(Bidang::Olahraga->label())->toBe('Olahraga');
});

it('shows a held kelas X student the closed menus as locked (BUG-08)', function (): void {
    $student = L::student('Kirana Ayu Lestari', 'X-B');
    $user = L::user($student);

    $nav = Navigation::for($user, 'siswa', [], 'siswa.talenta');
    $items = collect($nav['sections'])->flatMap(fn (array $s) => $s['items'])->keyBy('key');
    $tabs = collect($nav['tabbar'])->keyBy('key');

    expect($items['beranda']['locked'])->toBeTrue()
        ->and($items['cari-lomba']['locked'])->toBeTrue()
        ->and($items['lomba-saya']['locked'])->toBeTrue()
        ->and($items['portofolio']['locked'])->toBeTrue()
        ->and($items['bantuan']['locked'])->toBeFalse()
        ->and($items['notifikasi']['locked'])->toBeFalse()
        // D-152: Profil saya (tabs Data diri + Keamanan akun) stays open.
        ->and($items['profil-saya']['locked'])->toBeFalse()
        ->and($tabs['beranda']['locked'])->toBeTrue()
        ->and($tabs['lomba']['locked'])->toBeTrue()
        // The tab that holds Profil talenta leads there while the gate holds.
        ->and($tabs['profil']['locked'])->toBeFalse()
        ->and($tabs['profil']['href'])->toBe(route('siswa.talenta'));

    $html = fs1qaTalentPlain((string) $this->actingAs($user)->get(route('siswa.talenta'))->assertOk()->getContent());
    // The sidebar item "Lomba saya" and the tab "Lomba" have no link while locked, and say why.
    expect($html)->toContain('(pilih talenta dulu)')
        ->not->toContain('href="'.route('siswa.lomba-saya.index').'"')
        ->not->toContain('href="'.route('siswa.lomba.index').'"')
        ->toContain('href="'.route('siswa.talenta').'"');

    // With one talent nothing is locked any more.
    fs1qaTalent($student, TalentTypeCode::Lainnya, other: 'Membaca');
    $nav = Navigation::for($user->fresh(), 'siswa', [], 'siswa.talenta');
    expect(collect($nav['sections'])->flatMap(fn (array $s) => $s['items'])->every(fn (array $i): bool => $i['locked'] === false))->toBeTrue()
        ->and(collect($nav['tabbar'])->keyBy('key')['profil']['href'])->toBe(route('siswa.portofolio'));
});

it('never locks the menus of a student who chose a talent, above kelas X too (D-153)', function (): void {
    $user = L::user(L::student('Nadia Putri Anindya', 'XI-D'));

    $nav = Navigation::for($user, 'siswa', [], 'siswa.beranda');

    expect(collect($nav['sections'])->flatMap(fn (array $s) => $s['items'])->contains(fn (array $i): bool => $i['locked']))->toBeFalse();
});

it('tells a held kelas X student about the talent in Bantuan instead of a tour that bounces (BUG-08)', function (): void {
    $student = L::student('Kirana Ayu Lestari', 'X-B');

    $this->actingAs(L::user($student))->get(route('siswa.bantuan'))->assertOk()
        ->assertSee('Pilih talenta')
        ->assertSee('Tour bisa dibuka setelah kamu memilih minimal satu talenta di Talenta saya.')
        ->assertDontSee('href="'.route('siswa.tour').'"', false);

    fs1qaTalent($student, TalentTypeCode::Lainnya, other: 'Membaca');

    $this->actingAs(L::user($student))->get(route('siswa.bantuan'))->assertOk()
        ->assertSee('Buka tour')
        ->assertSee('href="'.route('siswa.tour').'"', false);
});

it('says on the activation done screen that a kelas X student picks a talent first (BUG-08)', function (): void {
    Mail::fake();
    config(['fortify.sth.otp_test_mode' => false]);
    $email = 'kirana.lestari@example.test';
    $kelasX = AuthTestHelpers::pendingStudent([
        'nis' => '225226301',
        'nisn' => '0081234567',
        'classroom_id' => Classroom::factory()->level(10)->create(['name' => 'X-G'])->getKey(),
    ]);

    $this->actingAs($kelasX->user);
    $component = Livewire::test(Activation::class)->set('email', $email)->call('sendCode')->assertHasNoErrors();

    $code = null;
    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use ($email, &$code): bool {
        $code = $mail->hasTo($email) ? $mail->code : $code;

        return true;
    });

    $component->set('code', (string) $code)->call('verifyCode')->assertHasNoErrors()
        // D-152: Data diri before the password.
        ->assertSet('step', Activation::STEP_PROFILE)
        ->set('phone', '081234567890')->call('saveProfile')->assertHasNoErrors()
        ->set('password', 'Pelangi-senja-2026')->set('password_confirmation', 'Pelangi-senja-2026')
        ->call('savePassword')->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_DONE)
        ->assertSee('Pilih minimal satu talenta dulu di Talenta saya (menu Profil saya). Setelah itu kamu bisa daftar lomba dan pantau prestasimu.')
        ->assertDontSee('Sekarang kamu bisa daftar lomba');
});
