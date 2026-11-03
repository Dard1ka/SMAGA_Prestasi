<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Livewire\Siswa\LombaSaya\LaporHasil;
use App\Models\Registration;
use App\Models\Report;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;
use Tests\Support\TestDisks;

/*
 | Third browser check (2026-11-01), stale tab: the ketua had the report form open in tab 1 and sent the report in
 | tab 2. "Kirim laporan" in tab 1 answered the generic error "Laporan untuk lomba ini tidak bisa dikirim sekarang." and
 | kept the old form with its files. It now answers as Lomba saya answers its own button (Index::reportSentElsewhere()):
 | the warning "Laporan hasil sudah dikirim. Halaman sudah diperbarui." and the registration's page drawn again from its
 | state, inline and on the full page. Any other refusal keeps the generic error. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Two forms hold uploads at once: a folder of Livewire's test uploads of their own (see LaporHasilPilihFileTest).
    TestDisks::isolate('tmp-for-tests');
    Notification::fake();
    F::boot();
    F::today('2026-10-01');
    $this->withoutVite();

    $this->yoga = F::student('Yoga Pratama', 'XII-E');
    $this->o2sn = F::registration($this->yoga, competition: F::competition(['name' => 'O2SN Kota Semarang 2026', 'bidang' => Bidang::Olahraga]));
});

afterEach(fn () => F::resetToday());

/** A filled report form of $leader for $registration (O2SN: no riset fields). */
function lhseFilled(Student $leader, Registration $registration, bool $inline, string $photo = 'foto-podium.jpg'): Testable
{
    return Livewire::actingAs($leader->user)
        ->test(LaporHasil::class, ['registration' => $registration, 'inline' => $inline])
        ->set('hasil', ResultType::Juara1->value)
        ->set('sertifikat', F::pdf('piagam-o2sn.pdf'))
        ->set('fotoBaru', [F::jpg($photo)])
        ->set('asli', true);
}

it('tells the ketua the report was sent in another tab and draws the page again, not the generic error', function (bool $inline): void {
    $tab1 = lhseFilled($this->yoga, $this->o2sn, $inline);

    // Tab 2 sends the report first.
    lhseFilled($this->yoga, $this->o2sn, $inline, 'foto-tim.jpg')
        ->call('kirim')
        ->assertHasNoErrors()
        ->assertRedirect(route('siswa.lomba-saya.show', $this->o2sn));
    session()->forget('toast');

    $tab1->call('kirim')
        ->assertHasNoErrors()
        ->assertNotDispatched('sth-toast')
        ->assertRedirect(route('siswa.lomba-saya.show', $this->o2sn));

    expect(session('toast'))->toBe(['tone' => 'warning', 'message' => LaporHasil::MSG_SENT_ELSEWHERE])
        ->and(LaporHasil::MSG_SENT_ELSEWHERE)->toBe('Laporan hasil sudah dikirim. Halaman sudah diperbarui.')
        ->and(Report::query()->where('registration_id', $this->o2sn->getKey())->count())->toBe(1)
        ->and($this->o2sn->fresh()->status)->toBe(RegistrationStatus::LaporanDikirim);

    // The page the form goes to shows the warning once and the sent report, not the form.
    $this->actingAs($this->yoga->user)
        ->get(route('siswa.lomba-saya.show', $this->o2sn))
        ->assertOk()
        ->assertSee(LaporHasil::MSG_SENT_ELSEWHERE)
        ->assertSee('Laporan terkirim')
        ->assertDontSee('Kirim laporan');
})->with([
    'inline (Lomba saya)' => [true],
    'full page' => [false],
]);

it('answers a report that was verified meanwhile the same way', function (): void {
    $tab1 = lhseFilled($this->yoga, $this->o2sn, true);

    $report = new Report(['registration_id' => $this->o2sn->getKey(), 'claimed_result' => ResultType::Juara1]);
    $report->forceFill(['status' => ReportStatus::Diverifikasi, 'submitted_by' => $this->yoga->user_id, 'submitted_at' => now()])->save();
    $this->o2sn->forceFill(['status' => RegistrationStatus::Selesai])->save();

    $tab1->call('kirim')
        ->assertNotDispatched('sth-toast')
        ->assertRedirect(route('siswa.lomba-saya.show', $this->o2sn));
    expect(session('toast'))->toBe(['tone' => 'warning', 'message' => LaporHasil::MSG_SENT_ELSEWHERE]);
});

it('keeps the generic error for a refusal that is not a report sent elsewhere', function (): void {
    $tab1 = lhseFilled($this->yoga, $this->o2sn, true);

    // No report: the registration left "menunggu laporan" for another reason (here: back to a running lomba).
    $this->o2sn->forceFill(['status' => RegistrationStatus::Berlangsung])->save();

    $tab1->call('kirim')
        ->assertNoRedirect()
        ->assertDispatched('sth-toast', message: LaporHasil::MSG_CANNOT_SEND, tone: 'error');

    expect(session('toast'))->toBeNull()
        ->and(Report::query()->count())->toBe(0);
});

it('keeps the generic error while the report waits for its revision but cannot be sent', function (): void {
    $report = new Report(['registration_id' => $this->o2sn->getKey(), 'claimed_result' => ResultType::Juara2]);
    $report->forceFill(['status' => ReportStatus::PerluRevisi, 'note' => 'Sertifikat buram.', 'submitted_by' => $this->yoga->user_id, 'submitted_at' => now()])->save();

    $tab1 = lhseFilled($this->yoga, $this->o2sn, true);
    $this->o2sn->forceFill(['status' => RegistrationStatus::Berlangsung])->save();

    $tab1->call('kirim')
        ->assertNoRedirect()
        ->assertDispatched('sth-toast', message: LaporHasil::MSG_CANNOT_SEND, tone: 'error');
    expect(session('toast'))->toBeNull();
});
