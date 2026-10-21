<?php

declare(strict_types=1);

use App\Actions\Reports\RejectReport;
use App\Actions\Reports\RequestReportRevision;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Kesiswaan\VerifikasiHasil;
use App\Models\Achievement;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Notifications\Verification\BinaanReportDecided;
use App\Notifications\Verification\ReportRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| D-140 (FS1, 12 Okt 2026): Verifikasi hasil offers only 'Verifikasi dan beri poin' and 'Minta revisi'. RejectReport,
| ReportStatus::Ditolak and its notices stay for old data. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
});

/** The account of a student that can no longer log in (disabled by default). */
function d128Freeze(object $student, UserStatus $status = UserStatus::Disabled): void
{
    $student->user->forceFill(['status' => $status])->save();
}

// The Actions: one check for the reason, one answer to "who is told" ------------------------------------------------

it('checks the reason of a rejection in one place, for the screen and the Action (D-128)', function (): void {
    $report = F::pendingReport();
    $message = fn (Closure $call): string => (function () use ($call): string {
        try {
            $call();
        } catch (ValidationException $e) {
            return (string) $e->errors()['note'][0];
        }

        return '';
    })();

    expect(RejectReport::reason("  Sertifikat bukan atas nama siswa.\n "))->toBe('Sertifikat bukan atas nama siswa.')
        ->and($message(fn () => RejectReport::reason('   ')))->toBe('Tulis alasan penolakan untuk siswa.')
        ->and($message(fn () => RejectReport::reason(str_repeat('a', 1001))))->toBe('Catatan maksimal 1.000 karakter.')
        ->and($message(fn () => app(RejectReport::class)->handle($this->kesiswaan, $report, '')))->toBe(RejectReport::NOTE_REQUIRED)
        ->and($message(fn () => app(RejectReport::class)->handle($this->kesiswaan, $report, str_repeat('a', 1001))))->toBe('Catatan maksimal 1.000 karakter.')
        ->and($report->fresh()->status)->toBe(ReportStatus::Dikirim);
});

it('says who hears about a decision on a report (D-109, D-110)', function (): void {
    $leader = F::student('Nadia Putri Anindya', 'XI-D');
    $dimas = F::student('Dimas Arya Nugroho', 'XI-D');
    $keisha = F::student('Keisha Larasati', 'XI-F');
    $solo = F::pendingReport(F::student('Raka Pradipta', 'XII-B'));
    $team = F::pendingReport($leader, [$dimas, $keisha], mentor: $this->guru);

    expect(RequestReportRevision::audience($solo))->toBe(['told' => 1, 'of' => 1, 'mentor' => false])
        ->and(RequestReportRevision::audience($team))->toBe(['told' => 3, 'of' => 3, 'mentor' => true]);

    // A member who declined is not in the team; a disabled account is in the team but is not told.
    RegistrationMember::query()->where('registration_id', $team->registration_id)->where('student_id', $keisha->getKey())
        ->firstOrFail()->forceFill(['confirmed_at' => null, 'declined_at' => now()])->save();
    d128Freeze($dimas);

    expect(RequestReportRevision::audience($team))->toBe(['told' => 1, 'of' => 2, 'mentor' => true]);

    // A guru pembimbing who is no longer a guru hears nothing (mentorUser()).
    $this->guru->syncRoles([RoleName::Humas->value]);

    expect(RequestReportRevision::audience($team))->toBe(['told' => 1, 'of' => 2, 'mentor' => false]);
});

it('says whether the report can be sent again: only while the ketua can log in (D-109)', function (): void {
    $leader = F::student('Nadia Putri Anindya', 'XI-D');
    $dimas = F::student('Dimas Arya Nugroho', 'XI-D');
    $solo = F::pendingReport(F::student('Raka Pradipta', 'XII-B'));
    $team = F::pendingReport($leader, [$dimas]);

    expect(RequestReportRevision::leaderCanResend($solo))->toBeTrue()
        ->and(RequestReportRevision::leaderCanResend($team))->toBeTrue();

    // An anggota who cannot log in changes nothing: the ketua sends the report (ReportPolicy::create).
    d128Freeze($dimas);

    expect(RequestReportRevision::leaderCanResend($team))->toBeTrue();

    // The ketua cannot log in: nobody may send it again, although an anggota is still told (audience()).
    $dimas->user->forceFill(['status' => UserStatus::Active])->save();
    d128Freeze($leader, UserStatus::Archived);

    expect(RequestReportRevision::leaderCanResend($team))->toBeFalse()
        ->and(RequestReportRevision::audience($team)['told'])->toBe(1);
});

// The screen: two decisions only ---------------------------------------------------------------------------------------

it('offers only "Verifikasi dan beri poin" and "Minta revisi" (D-140)', function (): void {
    F::pendingReport();

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->assertSee('Verifikasi dan beri poin')
        ->assertSee('Minta revisi')
        ->assertSee('Wajib untuk minta revisi.')
        ->assertDontSee('Wajib untuk minta revisi atau tolak.')
        ->assertDontSeeHtml('askReject')
        ->assertDontSeeHtml('wire:submit="reject"')
        ->assertDontSeeHtml('id="tolak-laporan"')
        ->assertDontSeeHtml('>Tolak<')
        ->assertDontSee('Tolak laporan ini?');

    expect(method_exists(VerifikasiHasil::class, 'askReject'))->toBeFalse()
        ->and(method_exists(VerifikasiHasil::class, 'reject'))->toBeFalse()
        ->and(defined(VerifikasiHasil::class.'::REJECT_DIALOG'))->toBeFalse()
        ->and(property_exists(VerifikasiHasil::class, 'rejectingId'))->toBeFalse()
        ->and((string) file_get_contents(app_path('Livewire/Kesiswaan/VerifikasiHasil.php')))->not->toContain('RejectReport');
});

it('still closes a report through RejectReport for old data: ditolak, selesai without points, team and guru told (D-140)', function (): void {
    $leader = F::student('Nadia Putri Anindya', 'XI-D');
    $report = F::pendingReport($leader, mentor: $this->guru);

    app(RejectReport::class)->handle($this->kesiswaan, $report, 'Sertifikat bukan atas nama siswa.');

    expect($report->fresh()->status)->toBe(ReportStatus::Ditolak)
        ->and(Registration::query()->findOrFail($report->registration_id)->status)->toBe(RegistrationStatus::Selesai)
        ->and(Achievement::query()->count())->toBe(0);
    Notification::assertSentToTimes($leader->user, ReportRejected::class, 1);
    Notification::assertSentToTimes($this->guru, BinaanReportDecided::class, 1);
});

// Forbidden cases ----------------------------------------------------------------------------------------------------

it('refuses a decision to the report\'s own guru pembimbing, who sees no decision button', function (): void {
    // SECURITY §3c-10: a verifier never decides a report of the own binaan.
    $report = F::pendingReport(mentor: $this->kesiswaan);

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->assertSet('selectedId', $report->getKey())
        ->assertSee('Laporan binaan sendiri diverifikasi petugas lain.')
        ->assertDontSeeHtml('wire:click="requestRevision"')
        ->set('note', 'Sertifikat buram.')
        ->call('requestRevision')
        ->assertForbidden();

    expect($report->fresh()->status)->toBe(ReportStatus::Dikirim);
    Notification::assertNothingSent();
});

it('keeps every other role away from the question (D-128)', function (): void {
    $report = F::pendingReport();
    $roles = [RoleName::Guru, RoleName::KepalaSekolah, RoleName::TataUsaha];

    foreach ($roles as $role) {
        Livewire::actingAs(F::staff($role, 'Staf '.$role->value))->test(VerifikasiHasil::class)->assertForbidden();
    }

    Livewire::actingAs(F::student('Raka Pradipta', 'XII-B')->user)->test(VerifikasiHasil::class)->assertForbidden();

    expect($report->fresh()->status)->toBe(ReportStatus::Dikirim);
});

// Honest result banners (D-109) ------------------------------------------------------------------------------------

it('names who got the note in the banner after "Minta revisi" (D-109)', function (string $case, string $text): void {
    $leader = F::student('Nadia Putri Anindya', 'XI-D');
    $dimas = F::student('Dimas Arya Nugroho', 'XI-D');

    $report = match ($case) {
        'solo' => F::pendingReport($leader),
        'team with guru' => F::pendingReport($leader, [$dimas], mentor: $this->guru),
        // Only the ketua may send the report again (ReportPolicy::create): the banner says so only while that
        // account can log in, whoever else of the team was told.
        'team, ketua cannot log in' => tap(F::pendingReport($leader, [$dimas], mentor: $this->guru), fn () => d128Freeze($leader, UserStatus::Archived)),
        'team, anggota cannot log in' => tap(F::pendingReport($leader, [$dimas], mentor: $this->guru), fn () => d128Freeze($dimas)),
        'guru only' => tap(F::pendingReport($leader, mentor: $this->guru), fn () => d128Freeze($leader)),
        'nobody' => tap(F::pendingReport($leader), fn () => d128Freeze($leader, UserStatus::Archived)),
    };

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->set('note', 'Foto sertifikat buram, kirim ulang.')
        ->call('requestRevision')
        ->assertHasNoErrors()
        ->assertSet('decision', ['tone' => 'orange', 'title' => 'Revisi diminta', 'text' => $text])
        ->assertDontSee('Siswa menerima catatan revisi dan bisa mengirim ulang.');

    expect($report->fresh()->status)->toBe(ReportStatus::PerluRevisi);
})->with([
    'solo' => ['solo', 'Siswa menerima catatan revisi. Siswa bisa mengirim ulang laporan.'],
    'team with guru' => ['team with guru', 'Siswa dan guru pembimbing menerima catatan revisi. Ketua bisa mengirim ulang laporan.'],
    'team, ketua cannot log in' => ['team, ketua cannot log in', 'Siswa yang akunnya aktif dan guru pembimbing menerima catatan revisi.'],
    'team, anggota cannot log in' => ['team, anggota cannot log in', 'Siswa yang akunnya aktif dan guru pembimbing menerima catatan revisi. Ketua bisa mengirim ulang laporan.'],
    'guru only' => ['guru only', 'Guru pembimbing menerima catatan revisi.'],
    'nobody' => ['nobody', 'Akun siswa tidak aktif, jadi tidak ada notifikasi.'],
]);
