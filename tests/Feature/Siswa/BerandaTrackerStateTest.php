<?php

declare(strict_types=1);

use App\Actions\Registrations\ConfirmMembership;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Livewire\Siswa\Beranda;
use App\Livewire\Siswa\LombaSaya\Index as LombaSaya;
use App\Models\Registration;
use App\Models\Report;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

/*
| SV1 review (round 3 had fixed Lomba saya only): Siswa › Beranda › Lomba saya showed a team MEMBER the orange
| "Menunggu laporan" chip and the tracker's "perlu tindakan" at Laporan hasil, although only the ketua reports
| (ReportPolicy::create). A member now gets the neutral chip and tracker state of Lomba saya (LombaSaya::MEMBER_REPORT_CHIP,
| MEMBER_REVISION_CHIP, LombaSaya::trackerStateFor()); the ketua keeps the request. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    F::boot();
    F::today('2026-10-01');
    $this->withoutVite();

    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho');
    User::query()->whereKey([$this->nadia->user_id, $this->dimas->user_id])->update(['tour_seen_at' => now()]);
    $this->wice = F::registration($this->nadia, RegistrationStatus::MenungguLaporan, members: [$this->dimas]);
});

afterEach(fn () => F::resetToday());

/** @return array{0: Testable, 1: array<string, mixed>} the Beranda of $viewer and its Lomba saya item of $registration */
function btsBeranda(Student $viewer, Registration $registration): array
{
    $page = Livewire::actingAs($viewer->user)->test(Beranda::class);

    return [$page, collect($page->viewData('mine'))->firstWhere('id', $registration->getKey())];
}

it('shows a team member Laporan hasil as the current phase with a neutral chip while the ketua reports', function (): void {
    [$page, $item] = btsBeranda($this->dimas, $this->wice);

    expect($item['trackerState'])->toBe('current')
        ->and([$item['label'], $item['tone']])->toBe([LombaSaya::MEMBER_REPORT_CHIP['chip'], 'gray']);

    $page->assertSee('Menunggu laporan ketua')
        ->assertSee('Laporan hasil')
        ->assertSeeHtml('sg sg-cur')
        ->assertDontSeeHtml('sg sg-act')
        ->assertDontSee('perlu tindakan')
        // Only the ketua has the "Perlu tindakan" card with "Laporkan hasil".
        ->assertDontSee('Perlu tindakan')
        ->assertDontSee('Laporkan hasil');
});

it('keeps the orange request and "perlu tindakan" for the ketua who reports (D-151)', function (): void {
    [$page, $item] = btsBeranda($this->nadia, $this->wice);

    expect($item['trackerState'])->toBeNull()
        ->and([$item['label'], $item['tone']])->toBe([RegistrationStatus::MenungguLaporan->label(), 'orange']);

    $page->assertSeeHtml('sg sg-act')
        ->assertSee('perlu tindakan')
        ->assertSee('Laporkan hasil')
        ->assertDontSee('Menunggu laporan ketua');
});

it('tells a team member the ketua revises the report', function (): void {
    $report = Report::factory()->create([
        'registration_id' => $this->wice->getKey(),
        'claimed_result' => ResultType::Finalis,
        'submitted_by' => $this->nadia->user->getKey(),
    ]);
    $report->forceFill(['status' => ReportStatus::PerluRevisi, 'note' => 'Sertifikat buram, upload ulang.'])->save();

    [$page, $item] = btsBeranda($this->dimas, $this->wice);

    expect($item['trackerState'])->toBe('current')
        ->and([$item['label'], $item['tone']])->toBe([LombaSaya::MEMBER_REVISION_CHIP['chip'], 'gray']);
    $page->assertSee('Menunggu perbaikan ketua')->assertDontSee('perlu tindakan');
});

it('keeps "perlu tindakan" for an individu registration, whose student is the ketua', function (): void {
    $solo = F::registration($this->dimas, RegistrationStatus::MenungguLaporan, F::competition(['name' => 'LPB 2026']));

    [$page, $item] = btsBeranda($this->dimas, $solo);

    expect($item['trackerState'])->toBeNull()
        ->and($item['tone'])->toBe('orange');
    $page->assertSeeHtml('sg sg-act')->assertSee('perlu tindakan');
});

it('leaves the other phases to the status for a team member', function (): void {
    $this->wice->forceFill(['status' => RegistrationStatus::Berlangsung])->save();

    [$page, $item] = btsBeranda($this->dimas, $this->wice);

    expect($item['trackerState'])->toBeNull()
        ->and([$item['label'], $item['tone']])->toBe([RegistrationStatus::Berlangsung->label(), RegistrationStatus::Berlangsung->tone()]);
    $page->assertSeeHtml('sg sg-cur')->assertDontSee('Menunggu laporan ketua');
});

/*
| Review fixes: the Beranda showed a team short of people (D-36) as the plain gray "Menunggu konfirmasi" and a report
| sent back to the ketua as the plain "Menunggu laporan", while Lomba saya says "Tim kurang anggota" (orange for the
| ketua) and "Perlu revisi". The Beranda now uses the same rule (LombaSaya::isShortTeam() → ConfirmMembership::
| teamIsShortWith(), from the loaded `team_size`) and the same chips.
*/

/** @return array{0: string, 1: string} the chip of the Lomba saya list card of $registration for $viewer */
function btsLombaSayaChip(Student $viewer, Registration $registration): array
{
    $card = collect(Livewire::actingAs($viewer->user)->test(LombaSaya::class, ['registration' => $registration->fresh()])->viewData('cards'))
        ->firstWhere('id', (string) $registration->getKey());

    return [$card['chip'], $card['tone']];
}

it('tells the ketua "Perlu revisi" while the report is sent back, as Lomba saya', function (): void {
    $report = Report::factory()->create([
        'registration_id' => $this->wice->getKey(),
        'claimed_result' => ResultType::Finalis,
        'submitted_by' => $this->nadia->user->getKey(),
    ]);
    $report->forceFill(['status' => ReportStatus::PerluRevisi, 'note' => 'Sertifikat buram, upload ulang.'])->save();

    [$page, $item] = btsBeranda($this->nadia, $this->wice);

    expect([$item['label'], $item['tone']])->toBe([LombaSaya::LEADER_REVISION_CHIP['chip'], 'orange'])
        ->and([$item['label'], $item['tone']])->toBe(btsLombaSayaChip($this->nadia, $this->wice))
        // The ketua acts: the tracker keeps "perlu tindakan".
        ->and($item['trackerState'])->toBeNull();
    $page->assertSee('Perlu revisi')->assertSeeHtml('sg sg-act');
});

it('tells the ketua of a team left alone that it is short, orange, as Lomba saya (D-36)', function (): void {
    $lomba = F::competition(['name' => 'OSN Biologi 2026']);
    $team = F::registration($this->nadia, RegistrationStatus::MenungguKonfirmasi, $lomba, members: [$this->dimas], confirmed: false);
    $team->members()->where('student_id', $this->dimas->getKey())->update(['declined_at' => now()]);
    $team = $team->fresh();

    expect(ConfirmMembership::teamIsShort($team))->toBeTrue();

    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        [$page, $item] = btsBeranda($this->nadia, $team);
        // The loaded `team_size` decides; no COUNT query of the members runs for a card.
        $counts = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql): bool => preg_match('/^select count\(\*\) as ["`]?aggregate["`]? from ["`]?registration_members["`]?/i', $sql) === 1)
            ->count();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect([$item['label'], $item['tone']])->toBe([LombaSaya::SHORT_TEAM_CHIP, 'orange'])
        ->and([$item['label'], $item['tone']])->toBe(btsLombaSayaChip($this->nadia, $team))
        ->and($item['meta'])->toEndWith('Tim 1')
        ->and($counts)->toBe(0);
    $page->assertSee(LombaSaya::SHORT_TEAM_CHIP);
});

it('keeps "Menunggu konfirmasi" for a team that still has enough people (D-36)', function (): void {
    $team = F::registration($this->nadia, RegistrationStatus::MenungguKonfirmasi, F::competition(['name' => 'OSN Biologi 2026']), members: [$this->dimas], confirmed: false);

    expect(ConfirmMembership::teamIsShort($team))->toBeFalse();

    [$page, $item] = btsBeranda($this->nadia, $team);

    expect([$item['label'], $item['tone']])->toBe([RegistrationStatus::MenungguKonfirmasi->label(), 'gray'])
        ->and(btsLombaSayaChip($this->nadia, $team)[0])->toBe(RegistrationStatus::MenungguKonfirmasi->label());
    $page->assertDontSee(LombaSaya::SHORT_TEAM_CHIP);
});

it('gives a member of a short team the gray chip and the ketua the orange one (SV1)', function (): void {
    expect(LombaSaya::shortTeamChip(true))->toBe(['chip' => LombaSaya::SHORT_TEAM_CHIP, 'tone' => 'orange'])
        ->and(LombaSaya::shortTeamChip(false))->toBe(['chip' => LombaSaya::SHORT_TEAM_CHIP, 'tone' => 'gray']);
});
