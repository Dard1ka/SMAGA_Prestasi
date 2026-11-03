<?php

declare(strict_types=1);

use App\Actions\Registrations\CreateRegistration;
use App\Enums\Bidang;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Livewire\Siswa\Beranda;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\TalentType;
use Database\Seeders\TalentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

uses(RefreshDatabase::class);

/*
 | SV1 browser check (2026-10-30): Siswa › Beranda, "Rekomendasi untukmu · Sesuai bidang minatmu: Sains", listed lomba
 | of other bidang: the list fills up to four cards with any open lomba but kept the interest subtitle. The subtitle now
 | says what the list holds. Fictional data only.
 */

beforeEach(function (): void {
    F::roles();
    $this->seed(TalentSeeder::class);
    Notification::fake();
    $this->withoutVite();

    // XI-D: the fixture already gives a "Lainnya" talent (no bidang, D-143), so OSN is the one bidang of interest.
    $this->nadia = F::student();
    ui2BerandaTalent($this->nadia, TalentTypeCode::Osn);
});

/** A talent choice in the seeded jenis $code (with its first cabang). */
function ui2BerandaTalent(Student $student, TalentTypeCode $code): void
{
    $type = TalentType::query()->where('code', $code)->sole();
    $row = new TalentApplication;
    $row->forceFill([
        'student_id' => $student->getKey(),
        'talent_type_id' => $type->getKey(),
        'talent_branch_id' => $code->requiresBranch() ? $type->branches()->value('id') : null,
        'status' => TalentStatus::Terdaftar,
    ])->save();
}

function ui2BerandaRecommendations(Student $student): array
{
    return Livewire::actingAs(F::user($student))->test(Beranda::class)->viewData('recommendations');
}

it('keeps "Sesuai bidang minatmu" when every card is of the student\'s bidang', function (): void {
    F::openCompetition(['name' => 'Olimpiade Sains Kota Fiktif 2026', 'bidang' => Bidang::Sains]);
    F::openCompetition(['name' => 'Kompetisi Matematika Fiktif 2026', 'bidang' => Bidang::Sains]);

    $recommendations = ui2BerandaRecommendations($this->nadia);

    expect($recommendations['subtitle'])->toBe('Sesuai bidang minatmu: Sains')
        ->and(array_column($recommendations['cards'], 'bidang'))->toBe(['sains', 'sains']);
});

it('says the bidang of interest come first when other open lomba fill the list (SV1)', function (): void {
    F::openCompetition(['name' => 'Olimpiade Sains Kota Fiktif 2026', 'bidang' => Bidang::Sains, 'registration_closes_at' => now()->addDays(25)]);
    F::openCompetition(['name' => 'Lomba Riset Fiktif 2026', 'bidang' => Bidang::Riset, 'registration_closes_at' => now()->addDays(5)]);
    F::openCompetition(['name' => 'Festival Tari Fiktif 2026', 'bidang' => Bidang::SeniBudaya, 'registration_closes_at' => now()->addDays(8)]);

    $recommendations = ui2BerandaRecommendations($this->nadia);

    // The Sains lomba first (although it closes last), then the others: the subtitle no longer claims all are Sains.
    expect(array_column($recommendations['cards'], 'bidang'))->toBe(['sains', 'riset', 'seni_budaya'])
        ->and($recommendations['subtitle'])->toBe('Lomba bidang minatmu (Sains) ditampilkan lebih dulu');

    Livewire::actingAs(F::user($this->nadia))->test(Beranda::class)
        ->assertSee('Lomba bidang minatmu (Sains) ditampilkan lebih dulu')
        ->assertDontSee('Sesuai bidang minatmu');
});

it('says so when no lomba of the student\'s bidang is open and the list shows others (SV1)', function (): void {
    ui2BerandaTalent($this->nadia, TalentTypeCode::O2sn);
    F::openCompetition(['name' => 'Lomba Riset Fiktif 2026', 'bidang' => Bidang::Riset]);

    $recommendations = ui2BerandaRecommendations($this->nadia);

    expect(array_column($recommendations['cards'], 'bidang'))->toBe(['riset'])
        ->and($recommendations['subtitle'])->toBe('Belum ada lomba bidang minatmu (Sains dan Olahraga) yang dibuka. Ini pendaftaran lain yang sedang dibuka.');
});

it('says the student is in every open lomba of the bidang when only other lomba are left to show (SV1 follow-up)', function (): void {
    $osn = F::openCompetition(['name' => 'Olimpiade Sains Kota Fiktif 2026', 'bidang' => Bidang::Sains]);
    F::openCompetition(['name' => 'Lomba Riset Fiktif 2026', 'bidang' => Bidang::Riset]);

    // A running registration for the open Sains lomba: openFor() leaves it out of the list, so "belum ada … yang
    // dibuka" would be wrong.
    $registration = app(CreateRegistration::class)->handle(F::user($this->nadia), $osn, RegistrationType::Individu, [], F::staff(RoleName::Guru)->id);
    expect($registration->status)->toBe(RegistrationStatus::MenungguKepsek);

    $recommendations = ui2BerandaRecommendations($this->nadia);

    expect(array_column($recommendations['cards'], 'bidang'))->toBe(['riset'])
        ->and($recommendations['subtitle'])->toBe('Kamu sudah terdaftar di semua lomba bidang minatmu (Sains) yang dibuka. Ini pendaftaran lain yang sedang dibuka.');

    Livewire::actingAs(F::user($this->nadia))->test(Beranda::class)
        ->assertSee('Kamu sudah terdaftar di semua lomba bidang minatmu (Sains) yang dibuka.')
        ->assertDontSee('Belum ada lomba bidang minatmu');

    // A second bidang with nothing open: every open lomba of the two still has her in it.
    ui2BerandaTalent($this->nadia, TalentTypeCode::O2sn);

    expect(ui2BerandaRecommendations($this->nadia)['subtitle'])
        ->toBe('Kamu sudah terdaftar di semua lomba bidang minatmu (Sains dan Olahraga) yang dibuka. Ini pendaftaran lain yang sedang dibuka.');

    // Cancelled, the registration no longer counts: the Sains lomba is recommended again, first.
    $registration->forceFill(['status' => RegistrationStatus::Dibatalkan])->save();
    $recommendations = ui2BerandaRecommendations($this->nadia);

    expect(array_column($recommendations['cards'], 'bidang'))->toBe(['sains', 'riset'])
        ->and($recommendations['subtitle'])->toBe('Lomba bidang minatmu (Sains dan Olahraga) ditampilkan lebih dulu');
});

it('keeps the general subtitle for a student whose talents have no bidang', function (): void {
    $kirana = F::student('Kirana Ayu Lestari', 'XI-B'); // only the "Lainnya" talent of the fixture
    F::openCompetition(['name' => 'Lomba Riset Fiktif 2026', 'bidang' => Bidang::Riset]);

    expect(ui2BerandaRecommendations($kirana)['subtitle'])->toBe('Pendaftaran yang sedang dibuka');
});
