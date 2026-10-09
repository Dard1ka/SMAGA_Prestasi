<?php

declare(strict_types=1);

use App\Actions\Competitions\CloseCompetition;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\WithdrawInvitation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Livewire\Siswa\KonfirmasiTim;
use App\Livewire\Siswa\LombaSaya\Index as LombaSaya;
use App\Livewire\Siswa\Pendaftaran\Form;
use App\Models\Registration;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Siswa\Hasil\HasilFixtures;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * QA9: student screens open while someone else changed the record. The ketua withdrew an invitation (the member row is
 * deleted) → Undangan tim answered 404 on "Ikut tim", "Tolak", any re-render and a reload; Kesiswaan closed the lomba →
 * "Kirim pendaftaran" answered 404; the report was sent in another tab → "Laporkan hasil" on Lomba saya answered 403.
 * The screens now say what happened. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    $this->withoutVite();
});

afterEach(fn () => HasilFixtures::resetToday());

function qa9SiswaBoot(object $t): void
{
    F::roles();
    $t->guru = F::staff(RoleName::Guru);
    $t->nadia = F::student();
    $t->dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $t->keisha = F::student('Keisha Larasati', 'XI-6');
    User::query()->whereKey([$t->nadia->user_id, $t->dimas->user_id, $t->keisha->user_id])->update(['tour_seen_at' => now()]);
}

/** Nadia (ketua) invites Dimas to a team. */
function qa9SiswaInvite(object $t): Registration
{
    return app(CreateRegistration::class)->handle(F::user($t->nadia), F::teamCompetition(3), RegistrationType::Tim, [$t->dimas->getKey()], $t->guru->id);
}

// Undangan tim ------------------------------------------------------------------------------------------------------

it('tells the invitee that the ketua withdrew the invitation instead of a 404 (QA9)', function (string $method): void {
    qa9SiswaBoot($this);
    $registration = qa9SiswaInvite($this);
    $this->actingAs(F::user($this->dimas));
    $component = Livewire::test(KonfirmasiTim::class, ['registration' => $registration])->assertSee('Ikut tim ini?');

    app(WithdrawInvitation::class)->handle(F::user($this->nadia), $registration, $this->dimas->getKey());

    $component->call($method)
        ->assertOk()
        ->assertSee('Undangan ini sudah dibatalkan ketua tim.')
        ->assertDontSee('Ikut tim ini?')
        ->assertDontSee('Nadia Putri Anindya');

    if ($method !== '$refresh') {
        $component->assertDispatched('sth-toast', message: 'Undangan ini sudah dibatalkan ketua tim.', tone: 'warning');
    }

    expect($registration->members()->where('student_id', $this->dimas->getKey())->exists())->toBeFalse();
})->with(['ikut', 'tolak', '$refresh']);

it('opens a withdrawn invitation again without a 404, and without the team (QA9)', function (): void {
    qa9SiswaBoot($this);
    $registration = qa9SiswaInvite($this);
    app(WithdrawInvitation::class)->handle(F::user($this->nadia), $registration, $this->dimas->getKey());

    $this->actingAs(F::user($this->dimas))->get(route('siswa.undangan.show', $registration))
        ->assertOk()
        ->assertSee('Undangan ini sudah dibatalkan ketua tim.')
        ->assertDontSee('Nadia Putri Anindya')
        ->assertDontSee('Ikut tim ini?');

    // Someone who was never invited still gets the 404.
    $this->actingAs(F::user($this->keisha))->get(route('siswa.undangan.show', $registration))->assertNotFound();
});

// Daftar lomba --------------------------------------------------------------------------------------------------------

it('says the lomba was closed instead of a 404 when the registration is sent (QA9)', function (): void {
    qa9SiswaBoot($this);
    $lomba = F::openCompetition();
    $this->actingAs(F::user($this->nadia));
    $component = Livewire::test(Form::class, ['competitionId' => $lomba->getKey()])
        ->set('mentorId', $this->guru->id)
        ->set('setuju', true);

    app(CloseCompetition::class)->handle(F::staff(RoleName::Kesiswaan, 'Ibu Dwi Rahayu'), $lomba);

    $component->call('kirim')
        ->assertOk()
        ->assertHasErrors(['competition'])
        ->assertSee('Pendaftaran lomba ini sudah ditutup.');

    expect(Registration::query()->count())->toBe(0);
});

// Lomba saya ----------------------------------------------------------------------------------------------------------

it('tells the ketua that the report was already sent in another tab instead of a 403 (QA9)', function (): void {
    HasilFixtures::boot();
    HasilFixtures::today('2026-10-01');
    $nadia = HasilFixtures::student();
    $dimas = HasilFixtures::student('Dimas Arya Nugroho');
    $registration = HasilFixtures::registration($nadia, RegistrationStatus::MenungguLaporan, members: [$dimas]);

    $component = Livewire::actingAs($nadia->user)->test(LombaSaya::class, ['registration' => $registration]);

    // The other tab sent the report (SubmitReport: report dikirim, registration laporan_dikirim).
    $report = new Report(['registration_id' => $registration->getKey(), 'claimed_result' => ResultType::Juara2]);
    $report->forceFill(['status' => ReportStatus::Dikirim, 'submitted_by' => $nadia->user_id, 'submitted_at' => now()])->save();
    $registration->forceFill(['status' => RegistrationStatus::LaporanDikirim])->save();

    $component->call('openLapor')
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Laporan hasil sudah dikirim. Halaman sudah diperbarui.', tone: 'warning')
        ->assertSet('lapor', false);

    // A teammate never had the button: still refused.
    Livewire::actingAs($dimas->user)->test(LombaSaya::class, ['registration' => $registration])
        ->call('openLapor')
        ->assertForbidden();
});
