<?php

declare(strict_types=1);

use App\Enums\MemberRole;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Livewire\Siswa\Lomba\LombaPresenter;
use App\Models\Registration;
use App\Models\RegistrationMember;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| D-147 (FS1 Paket B, display): the read screens name the three periods of a lomba the same way: "Pendaftaran"
| (registration opens – closes), "Pelaksanaan" (start – end, one day when they are equal) and "Laporan hasil" (no
| deadline since D-151: "Segera laporkan setelah lomba selesai").
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    F::withoutStaffTwoFactor();
    $this->withoutVite();
    $this->nadia = F::student();
    $this->nadiaUser = F::user($this->nadia);
});

it('prints a period the short way', function (?string $from, ?string $to, ?string $expected): void {
    $date = fn (?string $d): ?CarbonImmutable => $d === null ? null : CarbonImmutable::parse($d);

    expect(LombaPresenter::range($date($from), $date($to)))->toBe($expected);
})->with([
    'same month' => ['2026-10-12 00:00:00', '2026-10-30 23:59:59', '12–30 Okt 2026'],
    'two months' => ['2026-09-28', '2026-10-05', '28 Sep – 5 Okt 2026'],
    'two years' => ['2026-12-28', '2027-01-05', '28 Des 2026 – 5 Jan 2027'],
    'one day' => ['2026-11-08', '2026-11-08', '8 Nov 2026'],
    'only the end' => [null, '2026-11-08', '8 Nov 2026'],
    'only the start' => ['2026-11-08', null, '8 Nov 2026'],
    'nothing' => [null, null, null],
]);

it('prints Pendaftaran, Pelaksanaan and Laporan hasil on Detail lomba', function (): void {
    $c = F::openCompetition([
        'registration_opens_at' => '2026-10-12 00:00:00', 'registration_closes_at' => '2026-10-30 23:59:59',
        'starts_on' => '2026-11-08', 'final_on' => '2026-11-10',
    ]);

    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba.show', $c))
        ->assertOk()
        ->assertSee('Pendaftaran')->assertSee('12–30 Okt 2026')
        ->assertSee('Pelaksanaan')->assertSee('8–10 Nov 2026')
        ->assertSee('Laporan hasil')->assertSee(LombaPresenter::REPORT_LINE)
        ->assertSee('Tempat')
        ->assertDontSee('Batas laporan hasil')->assertDontSee('Final dan pengumuman')
        ->assertDontSee('Batas daftar')->assertDontSee('Batas pendaftaran');
});

it('prints one day for a one-day lomba', function (): void {
    $c = F::openCompetition([
        'registration_opens_at' => '2026-10-12 00:00:00', 'registration_closes_at' => '2026-10-30 23:59:59',
        'starts_on' => '2026-11-08', 'final_on' => '2026-11-08',
    ]);

    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba.show', $c))
        ->assertOk()
        ->assertSee('8 Nov 2026')
        ->assertDontSee('8–8 Nov');
});

it('uses the same labels in the guru drawer', function (): void {
    $guru = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $c = F::openCompetition([
        'registration_opens_at' => '2026-10-12 00:00:00', 'registration_closes_at' => '2026-10-30 23:59:59',
        'starts_on' => '2026-11-08', 'final_on' => '2026-11-10',
    ]);

    $this->actingAs($guru)->get(route('guru.lomba.index', ['lihat' => $c->getKey()]))
        ->assertOk()
        ->assertSee('Pendaftaran')->assertSee('12–30 Okt 2026')
        ->assertSee('Pelaksanaan')->assertSee('8–10 Nov 2026')
        ->assertSee('Laporan hasil')->assertSee(LombaPresenter::REPORT_LINE)
        ->assertDontSee('Batas daftar')->assertDontSee('Tanggal lomba');
});

it('uses the same labels on the team invitation', function (): void {
    $c = F::teamCompetition(3, [
        'registration_opens_at' => '2026-10-12 00:00:00', 'registration_closes_at' => '2026-10-30 23:59:59',
        'starts_on' => '2026-11-08', 'final_on' => '2026-11-10', 'participation' => Participation::Tim,
    ]);
    $leader = F::student('Dimas Arya Nugroho', 'XI-4');
    $registration = Registration::factory()->create([
        'competition_id' => $c->getKey(),
        'leader_student_id' => $leader->getKey(),
        'type' => RegistrationType::Tim,
        'status' => RegistrationStatus::MenungguKonfirmasi,
    ]);
    $member = new RegistrationMember(['registration_id' => $registration->getKey(), 'student_id' => $this->nadia->getKey(), 'invited_at' => now()]);
    $member->forceFill(['role' => MemberRole::Anggota])->save();

    $this->actingAs($this->nadiaUser)->get(route('siswa.undangan.show', $registration))
        ->assertOk()
        ->assertSee('Pendaftaran')->assertSee('12–30 Okt 2026')
        ->assertSee('Pelaksanaan')->assertSee('8–10 Nov 2026')
        ->assertSee('Laporan hasil')
        ->assertDontSee('Batas daftar')->assertDontSee('Tanggal lomba');
});

it('names the date of a lomba card "Pelaksanaan" for screen readers', function (): void {
    F::openCompetition(['starts_on' => '2026-11-08', 'final_on' => '2026-11-10']);

    $this->actingAs($this->nadiaUser)->get(route('siswa.lomba.index'))
        ->assertOk()
        ->assertSee('<span class="sr-only">Pelaksanaan:</span>', false)
        ->assertDontSee('<span class="sr-only">Tanggal lomba:</span>', false);
});
