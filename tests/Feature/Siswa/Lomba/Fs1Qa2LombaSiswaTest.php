<?php

declare(strict_types=1);

use App\Actions\Registrations\CreateRegistration;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Livewire\Siswa\KonfirmasiTim;
use App\Livewire\Siswa\LombaSaya\Index as LombaSaya;
use App\Livewire\Siswa\Pendaftaran\Form as PendaftaranForm;
use App\Models\Registration;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| FS1 QA round 2 (tracks QA1 and QA3), student screens: the Detail lomba tab row at 390 px, the Tim card of a team that
| waits for its lomba, no team role on an individu registration, no registration-deadline chip once the report is
| awaited, the invitee's own Data peserta on Undangan tim, and the tab bar of a kelas X student held on Profil talenta.
| Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    K::boot();
    Notification::fake();
    $this->withoutVite();
    $this->guru = F::staff(RoleName::Guru);
    $this->nadia = F::student();
    $this->nadiaUser = F::user($this->nadia);
});

/** The rendered page without Livewire's block markers, so a tag and its text can be matched together. */
function fs1qa2Plain(string $html): string
{
    return (string) preg_replace('/<!--\[if (?:END)?BLOCK\]><!\[endif\]-->/', '', $html);
}

it('fits the four Detail lomba tabs into a 390 px card (BUG-LOMBA-14)', function (): void {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    // Below sm the row is narrower than the four labels with 28px gaps (359 px in a 317 px card): the gaps shrink and
    // the tabs lose their 2px side padding there, so the row (about 295 px) fits without scrolling.
    preg_match('/@media \(max-width: 39\.99rem\) \{\s*\.stabs \{(.*?)\}\s*\.stab \{(.*?)\}/s', $css, $narrow);

    expect($narrow[1] ?? '')->toContain('gap: 12px;')
        ->and($narrow[2] ?? '')->toContain('padding: 0;');
});

it('says a member of a team that waits for its lomba is invited after the verification (BUG-LOMBA-15)', function (): void {
    $own = F::pendingCompetition($this->nadiaUser, F::usulanAttributes([
        'name' => 'Lomba Tim Usulan Fiktif', 'participation' => Participation::Tim, 'max_team_size' => 3,
    ]));
    $cahaya = F::student('Cahaya Lestari', 'XI-B');
    $registration = app(CreateRegistration::class)
        ->handle($this->nadiaUser, $own, RegistrationType::Tim, [$cahaya->getKey()], $this->guru->getKey());

    expect($registration->status)->toBe(RegistrationStatus::MenungguLomba);

    $html = fs1qa2Plain(Livewire::actingAs($this->nadiaUser)->test(LombaSaya::class, ['registration' => $registration])->html());

    expect($html)->toContain('Cahaya Lestari')
        ->toMatch('/<span class="chip[^"]*">\s*(?:<svg.*?<\/svg>\s*)?Diundang setelah lomba diverifikasi/s')
        ->not->toContain('Menunggu konfirmasi');

    // Once the lomba is published the invitation is out, and the chip says the member still has to answer.
    $registration->forceFill(['status' => RegistrationStatus::MenungguKonfirmasi])->save();
    $registration->members()->whereNull('invited_at')->update(['invited_at' => now()]);

    Livewire::actingAs($this->nadiaUser)->test(LombaSaya::class, ['registration' => $registration])
        ->assertSee('Menunggu konfirmasi')
        ->assertDontSee('Diundang setelah lomba diverifikasi');
});

it('shows the sole participant of an individu registration without the team role (BUG-LOMBA-16, QA-DC-03)', function (): void {
    $cahaya = F::student('Cahaya Lestari', 'XI-B');
    $registration = Registration::factory()->create([
        'competition_id' => F::openCompetition(['name' => 'Lomba Sains Individu Fiktif 2026'])->getKey(),
        'leader_student_id' => $cahaya->getKey(),
        'type' => RegistrationType::Individu,
        'status' => RegistrationStatus::MenungguLaporan,
    ]);

    $html = fs1qa2Plain(Livewire::actingAs(F::user($cahaya))->test(LombaSaya::class, ['registration' => $registration])->html());

    expect($html)->toContain('<span class="muted text-xs">XI-B</span>')
        ->not->toContain('XI-B · Ketua');

    // A team keeps the roles.
    $tim = Registration::factory()->create([
        'competition_id' => F::teamCompetition(3, ['name' => 'Lomba Tim Fiktif 2026'])->getKey(),
        'leader_student_id' => $cahaya->getKey(),
        'type' => RegistrationType::Tim,
        'status' => RegistrationStatus::MenungguLaporan,
    ]);

    Livewire::actingAs(F::user($cahaya))->test(LombaSaya::class, ['registration' => $tim])
        ->assertSee('XI-B · Ketua');
});

it('drops the registration deadline chip once the registration is past the registration step (BUG-LOMBA-17)', function (): void {
    $lomba = F::openCompetition(['name' => 'Lomba Sains Individu Fiktif 2026', 'registration_closes_at' => now()->addDays(5)->setTime(23, 59, 59)]);
    $chip = 'Batas '.$lomba->registration_closes_at->locale('id')->translatedFormat('j M');

    // Before registering, the deadline is what the form is about.
    Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => $lomba->getKey()])
        ->assertSee($chip);

    $registration = Registration::factory()->create([
        'competition_id' => $lomba->getKey(),
        'leader_student_id' => $this->nadia->getKey(),
        'type' => RegistrationType::Individu,
        'status' => RegistrationStatus::MenungguLaporan,
    ]);

    Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => $lomba->getKey()])
        ->assertSee('Laporkan hasil')
        ->assertDontSee($chip);

    // Approved or later: never a date that could be read as a report deadline (D-151).
    foreach ([RegistrationStatus::MenungguKepsek, RegistrationStatus::Disetujui, RegistrationStatus::Berlangsung, RegistrationStatus::LaporanDikirim] as $status) {
        $registration->forceFill(['status' => $status])->save();

        Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => $lomba->getKey()])
            ->assertDontSee($chip);
    }
});

it('keeps the deadline chip while a team still waits for its members', function (): void {
    $lomba = F::teamCompetition(3, ['name' => 'Lomba Tim Fiktif 2026', 'registration_closes_at' => now()->addDays(5)->setTime(23, 59, 59)]);
    $chip = 'Batas '.$lomba->registration_closes_at->locale('id')->translatedFormat('j M');
    $dimas = F::student('Dimas Arya Nugroho', 'XI-D');

    app(CreateRegistration::class)->handle($this->nadiaUser, $lomba, RegistrationType::Tim, [$dimas->getKey()], $this->guru->getKey());

    Livewire::actingAs($this->nadiaUser)->test(PendaftaranForm::class, ['competitionId' => $lomba->getKey()])
        ->assertSee('Pendaftaran terkirim')
        ->assertSee($chip);
});

it('shows the invitee the own Data peserta before "Ikut tim" (BUG-07)', function (): void {
    $this->nadia->forceFill(['birth_date' => '2010-03-14', 'phone' => '081211112222'])->save();
    $gita = F::student('Gita Permata', 'XI-B');
    $gita->forceFill(['birth_date' => '2010-11-02', 'phone' => '081298764421'])->save();
    $gita = $gita->fresh(['user', 'classroom']);

    $registration = app(CreateRegistration::class)
        ->handle($this->nadiaUser, F::teamCompetition(3), RegistrationType::Tim, [$gita->getKey()], $this->guru->getKey());

    $html = fs1qa2Plain(Livewire::actingAs(F::user($gita))->test(KonfirmasiTim::class, ['registration' => $registration])
        ->assertSee('Data peserta')
        ->assertSee('Data salah? Hubungi Kesiswaan.')
        ->assertSee($gita->nisn)
        ->assertSee('2 November 2010')
        ->assertSet('phone', '081298764421')
        // Only the invitee's own data: never the ketua's birth date, NISN or phone.
        ->assertDontSee('14 Maret 2010')
        ->assertDontSee($this->nadia->nisn)
        ->assertDontSee('081211112222')
        ->html());

    // The read-only block sits in the answer card, above the No HP field and the buttons.
    expect($html)->toMatch('/Ikut tim ini\?.*Data peserta.*Gita Permata.*XI-B.*'.$gita->nisn.'.*2 November 2010.*Data salah\? Hubungi Kesiswaan\..*No HP.*Ikut tim/s');

    // Once answered there is nothing left to check: the block goes with the question.
    $registration->members()->where('student_id', $gita->getKey())->update(['confirmed_at' => now()]);

    Livewire::actingAs(F::user($gita))->test(KonfirmasiTim::class, ['registration' => $registration])
        ->assertSee('Kamu sudah ikut tim ini.')
        ->assertDontSee('Data peserta')
        ->assertDontSee('2 November 2010');
});

it('names the tab that leads to Profil talenta after it while a kelas X student must pick a talent (BUG-N4)', function (): void {
    $elang = F::student('Elang Mahardika', 'X-C');
    $user = F::user($elang);

    $tab = collect(Navigation::for($user, 'siswa', [], 'siswa.talenta')['tabbar'])->keyBy('key')['profil'];
    $drawer = collect(Navigation::for($user, 'siswa', [], 'siswa.talenta')['sections'])->flatMap(fn (array $s) => $s['items'])->keyBy('key');

    expect($tab['href'])->toBe(route('siswa.talenta'))
        // One name per menu (D-119): the page it opens is "Talenta saya" (D-153).
        ->and($tab['label'])->toBe('Talenta saya')
        ->and($tab['icon'])->toBe('user')
        ->and($tab['locked'] ?? false)->toBeFalse()
        // The drawer agrees: Portofolio is locked there, Profil saya (with Talenta saya) is open.
        ->and($drawer['portofolio']['locked'])->toBeTrue()
        ->and($drawer['profil-saya']['locked'])->toBeFalse();

    $html = fs1qa2Plain((string) $this->actingAs($user)->get(route('siswa.talenta'))->assertOk()->getContent());
    preg_match('/<nav aria-label="Menu utama" class="tabbar[^"]*">(.*?)<\/nav>/s', $html, $tabbar);

    expect($tabbar[1] ?? '')->toContain('Talenta saya')->not->toContain('Portofolio');

    // A student who may move freely keeps the Portofolio tab.
    $free = collect(Navigation::for($this->nadiaUser, 'siswa', [], 'siswa.beranda')['tabbar'])->keyBy('key')['profil'];

    expect($free['label'])->toBe('Portofolio')
        ->and($free['icon'])->toBe('award')
        ->and($free['href'])->toBe(route('siswa.portofolio'));
});
