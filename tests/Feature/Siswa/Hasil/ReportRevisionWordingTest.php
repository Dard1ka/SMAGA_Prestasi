<?php

declare(strict_types=1);

use App\Domain\Deadlines\ReportFollowUp;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Livewire\Siswa\Beranda;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

/*
 | Third browser check (2026-11-01), wording of the report request:
 |  - while the report was sent back (perlu revisi), the ketua's "Perlu tindakan" card on the Beranda still said
 |    "… · laporan hasil / Segera laporkan hasil lombamu. / Laporkan hasil" (Beranda::actions() did not load the report);
 |    it now asks for the revision ("… · perbaiki laporan", Beranda::REVISION_NOTE, "Perbaiki laporan");
 |  - the status card of Lomba saya titled a team MEMBER's card "Laporkan hasil" / "Perbaiki laporan", a request to
 |    someone who cannot act; a member reads the neutral "Laporan hasil", the ketua keeps the request.
 | Fictional data only.
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

function rrwSendBack(object $test): void
{
    $report = new Report(['registration_id' => $test->wice->getKey(), 'claimed_result' => ResultType::Finalis]);
    $report->forceFill([
        'status' => ReportStatus::PerluRevisi,
        'note' => 'Sertifikat buram, upload ulang.',
        'submitted_by' => $test->nadia->user_id,
        'submitted_at' => now(),
    ])->save();
}

/** The title of the report card of Lomba saya in $html. */
function rrwCardTitle(string $html): string
{
    expect(preg_match('~<h3 id="lapor-judul" class="title-card">(.*?)</h3>~s', $html, $match))->toBe(1);

    return trim($match[1]);
}

it('asks the ketua on the Beranda to report while no report was sent (D-151)', function (): void {
    $page = Livewire::actingAs($this->nadia->user)->test(Beranda::class);
    $action = collect($page->viewData('actions'))->firstWhere('key', 'report-'.$this->wice->getKey());

    expect($action['title'])->toBe('WICE 2026 · laporan hasil')
        ->and($action['note'])->toBe(ReportFollowUp::PROMPT)
        ->and($action['button'])->toBe('Laporkan hasil');
    $page->assertSee('Laporkan hasil')->assertDontSee('Perbaiki laporan');
});

it('asks the ketua on the Beranda for the revision while the report is sent back', function (): void {
    rrwSendBack($this);

    $page = Livewire::actingAs($this->nadia->user)->test(Beranda::class);
    $action = collect($page->viewData('actions'))->firstWhere('key', 'report-'.$this->wice->getKey());

    expect($action['title'])->toBe('WICE 2026 · perbaiki laporan')
        ->and($action['note'])->toBe(Beranda::REVISION_NOTE)
        ->and($action['button'])->toBe('Perbaiki laporan')
        // Still the one place to report: the yellow card of Lomba saya, opened.
        ->and($action['href'])->toBe(route('siswa.lomba-saya.show', ['registration' => $this->wice->getKey(), 'lapor' => 1]).'#lapor-judul');

    $page->assertSee('WICE 2026 · perbaiki laporan')
        ->assertSee(Beranda::REVISION_NOTE)
        ->assertSee('Perbaiki laporan')
        ->assertDontSee('Laporkan hasil')
        ->assertDontSee(ReportFollowUp::PROMPT);
});

it('gives a team member no report action on the Beranda, also during a revision', function (): void {
    rrwSendBack($this);

    $page = Livewire::actingAs($this->dimas->user)->test(Beranda::class);

    expect(collect($page->viewData('actions'))->firstWhere('key', 'report-'.$this->wice->getKey()))->toBeNull();
    $page->assertDontSee('Perbaiki laporan')->assertDontSee('Laporkan hasil');
});

it('titles the ketua\'s report card on Lomba saya as a request', function (bool $revision, string $title): void {
    if ($revision) {
        rrwSendBack($this);
    }

    $html = $this->actingAs($this->nadia->user)
        ->get(route('siswa.lomba-saya.show', $this->wice))
        ->assertOk()
        ->assertSeeHtml('wire:click="openLapor"')
        ->getContent();

    expect(rrwCardTitle($html))->toBe($title);
})->with([
    'no report yet' => [false, 'Laporkan hasil'],
    'perlu revisi' => [true, 'Perbaiki laporan'],
]);

it('titles a team member\'s report card on Lomba saya neutrally: only the ketua can act', function (bool $revision): void {
    if ($revision) {
        rrwSendBack($this);
    }

    $html = $this->actingAs($this->dimas->user)
        ->get(route('siswa.lomba-saya.show', $this->wice))
        ->assertOk()
        ->assertSee('Ketua tim mengirim laporan hasil untuk seluruh tim.')
        ->assertDontSeeHtml('wire:click="openLapor"')
        ->getContent();

    expect(rrwCardTitle($html))->toBe('Laporan hasil')
        ->and($html)->not->toContain('Laporkan hasil')
        ->not->toContain('Perbaiki laporan');
})->with([
    'no report yet' => [false],
    'perlu revisi' => [true],
]);
