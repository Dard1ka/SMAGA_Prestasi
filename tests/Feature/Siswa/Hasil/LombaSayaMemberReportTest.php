<?php

declare(strict_types=1);

use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Livewire\Siswa\LombaSaya\Index;
use App\Models\Registration;
use App\Models\Report;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

/*
 * SV1 (second browser check of 2026-10-30): Lomba saya showed a team member the orange "Segera laporkan" chip and the
 * tracker's "perlu tindakan" at Laporan hasil, although only the ketua reports (ReportPolicy::create). A member now reads
 * a neutral chip ("Menunggu laporan ketua", "Menunggu perbaikan ketua" while the ketua revises) and the tracker takes a
 * state override (`state="current"`), so "perlu tindakan" is the ketua's only. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    F::boot();
    F::today('2026-10-01');
    $this->withoutVite();

    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho');
    $this->mentor = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $this->wice = F::registration($this->nadia, RegistrationStatus::MenungguLaporan, members: [$this->dimas], mentor: $this->mentor);
});

afterEach(fn () => F::resetToday());

/** @return array{page: Testable, card: array<string, mixed>, detail: array<string, mixed>} */
function lsmrView(Student $viewer, Registration $registration): array
{
    $page = Livewire::actingAs($viewer->user)->test(Index::class, ['registration' => $registration]);

    return [
        'page' => $page,
        'card' => collect($page->viewData('cards'))->firstWhere('id', (string) $registration->getKey()),
        'detail' => $page->viewData('detail'),
    ];
}

it('shows a team member a neutral chip and no "perlu tindakan" while the ketua reports', function (): void {
    ['page' => $page, 'card' => $card, 'detail' => $detail] = lsmrView($this->dimas, $this->wice);

    expect([$card['chip'], $card['tone']])->toBe([Index::MEMBER_REPORT_CHIP['chip'], 'gray'])
        ->and($card['trackerState'])->toBe('current')
        ->and([$detail['chip'], $detail['tone']])->toBe([Index::MEMBER_REPORT_CHIP['chip'], 'gray'])
        ->and($detail['reportChip'])->toBe(Index::MEMBER_REPORT_CHIP)
        ->and($detail['trackerState'])->toBe('current')
        ->and($detail['canReport'])->toBeFalse();

    $page->assertSee('Menunggu laporan ketua')
        ->assertSee('Ketua tim mengirim laporan hasil untuk seluruh tim.')
        ->assertDontSee('Segera laporkan')
        ->assertDontSee('perlu tindakan')
        // The compact tracker of the list card and the full tracker of the detail: Laporan hasil is the current phase.
        ->assertSeeHtml('sg sg-cur')
        ->assertSeeHtml('class="trk-step s-cur"')
        ->assertDontSeeHtml('sg sg-act')
        ->assertDontSeeHtml('class="trk-step s-act"');
});

it('keeps the orange request and "perlu tindakan" for the ketua who reports (D-151)', function (): void {
    ['page' => $page, 'card' => $card, 'detail' => $detail] = lsmrView($this->nadia, $this->wice);

    expect([$card['chip'], $card['tone']])->toBe(['Segera laporkan', 'orange'])
        ->and($card['trackerState'])->toBeNull()
        ->and($detail['reportChip'])->toBe(['chip' => 'Segera laporkan', 'tone' => 'orange'])
        ->and($detail['trackerState'])->toBeNull()
        ->and($detail['canReport'])->toBeTrue();

    $page->assertSee('Segera laporkan')
        ->assertSee('perlu tindakan')
        ->assertSeeHtml('sg sg-act')
        ->assertSeeHtml('class="trk-step s-act"')
        ->assertDontSee('Menunggu laporan ketua');
});

it('tells a member the ketua revises the report, and the ketua that the report needs a revision', function (): void {
    $report = Report::factory()->create([
        'registration_id' => $this->wice->getKey(),
        'claimed_result' => ResultType::Finalis,
        'submitted_by' => $this->nadia->user->getKey(),
    ]);
    $report->forceFill(['status' => ReportStatus::PerluRevisi, 'note' => 'Sertifikat buram, upload ulang.'])->save();

    ['page' => $member, 'card' => $memberCard] = lsmrView($this->dimas, $this->wice->fresh());
    expect([$memberCard['chip'], $memberCard['tone']])->toBe([Index::MEMBER_REVISION_CHIP['chip'], 'gray'])
        ->and($memberCard['trackerState'])->toBe('current');
    $member->assertSee('Menunggu perbaikan ketua')->assertDontSee('Perlu revisi')->assertDontSee('perlu tindakan');

    ['page' => $leader, 'card' => $leaderCard] = lsmrView($this->nadia, $this->wice->fresh());
    expect([$leaderCard['chip'], $leaderCard['tone']])->toBe(['Perlu revisi', 'orange']);
    $leader->assertSee('Perlu revisi')->assertSee('perlu tindakan')->assertDontSee('Menunggu perbaikan ketua');
});

it('keeps "perlu tindakan" for an individu registration, whose student is the ketua', function (): void {
    $solo = F::registration($this->nadia, RegistrationStatus::MenungguLaporan, F::competition(['name' => 'LPB 2026']));
    ['card' => $card, 'detail' => $detail] = lsmrView($this->nadia, $solo);

    expect($card['chip'])->toBe('Segera laporkan')
        ->and($card['trackerState'])->toBeNull()
        ->and($detail['trackerState'])->toBeNull();
});

it('lets a given tracker state win over the one the status gives, and keeps the defaults without one', function (): void {
    // Without a state the status decides: menunggu_laporan needs action.
    $this->blade('<x-sth.tracker :status="$s" compact />', ['s' => RegistrationStatus::MenungguLaporan])
        ->assertSee('sg sg-act', false)
        ->assertSee('perlu tindakan');
    $this->blade('<x-sth.tracker :status="$s" :state="$st" compact />', ['s' => RegistrationStatus::MenungguLaporan, 'st' => null])
        ->assertSee('sg sg-act', false);

    // The override: the same phase, current instead of action (a team member, SV1).
    $this->blade('<x-sth.tracker :status="$s" state="current" compact />', ['s' => RegistrationStatus::MenungguLaporan])
        ->assertSee('Laporan hasil')
        ->assertSee('sg sg-cur', false)
        ->assertDontSee('sg sg-act', false)
        ->assertDontSee('perlu tindakan');
    $this->blade('<x-sth.tracker :status="$s" state="current" />', ['s' => RegistrationStatus::MenungguLaporan])
        ->assertSee('class="trk-step s-cur"', false)
        ->assertDontSee('class="trk-step s-act"', false);

    // Without a status the phase keeps its default state (current), and a given one still applies.
    $this->blade('<x-sth.tracker :phase="3" />')->assertSee('class="trk-step s-cur"', false);
    $this->blade('<x-sth.tracker :phase="5" state="action" compact />')->assertSee('sg sg-act', false);
});
