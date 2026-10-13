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
use App\Notifications\Verification\ReportRevisionRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| D-128 (lead, 2026-10-10): Kesiswaan › Verifikasi hasil › "Tolak" asks first. A rejected report ends the registration
| without points and nothing takes it back; before, one click on the button next to "Minta revisi" did it. askReject()
| checks the reason and opens the dialog "tolak-laporan"; reject() is the confirm step and does nothing without that
| question. The dialog and the result banners of "Tolak" and "Minta revisi" name only the people who are told (D-109).
| Fictional data only.
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
    $leader = F::student('Nadia Putri Anindya', 'XI-4');
    $dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $keisha = F::student('Keisha Larasati', 'XI-6');
    $solo = F::pendingReport(F::student('Raka Pradipta', 'XII-2'));
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
    $leader = F::student('Nadia Putri Anindya', 'XI-4');
    $dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $solo = F::pendingReport(F::student('Raka Pradipta', 'XII-2'));
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

// The screen: ask first ----------------------------------------------------------------------------------------------

it('has a "Tolak" button that only asks: the view never calls reject on a click (D-128)', function (): void {
    F::pendingReport();

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->assertSeeHtml('wire:click="askReject"')
        ->assertSeeHtml('wire:submit="reject"')
        ->assertDontSeeHtml('wire:click="reject"')
        ->assertSeeHtml('id="tolak-laporan"')
        // The <dialog> keeps its own attributes (wire:ignore.self): the description is there from the first render.
        ->assertSeeHtml('aria-describedby="tolak-laporan-desc"')
        // Nothing is asked yet: the dialog has no "Tolak laporan" submit button.
        ->assertSee('Tidak ada laporan yang dipilih. Tutup lalu coba lagi.')
        ->assertDontSeeHtml('form="tolak-laporan-form"');
});

it('stops at the reason field before it opens the dialog (D-128)', function (string $note, string $message): void {
    $report = F::pendingReport();

    $component = Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->set('note', $note)
        ->call('askReject')
        ->assertHasErrors('note')
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('rejectingId', null);

    expect($component->errors()->first('note'))->toBe($message)
        ->and($report->fresh()->status)->toBe(ReportStatus::Dikirim);
    Notification::assertNothingSent();
})->with([
    'no reason' => ['   ', 'Tulis alasan penolakan untuk siswa.'],
    'too long' => [str_repeat('a', 1001), 'Catatan maksimal 1.000 karakter.'],
]);

it('opens the dialog with the lomba, the student, the reason and who is told, and changes nothing yet (D-128)', function (): void {
    $report = F::pendingReport(F::student('Nadia Putri Anindya', 'XI-4'), mentor: $this->guru);

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->set('note', "  Sertifikat bukan atas nama siswa.\nNama di piagam berbeda. ")
        ->call('askReject')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-open', id: 'tolak-laporan')
        ->assertSet('rejectingId', $report->getKey())
        ->assertViewHas('rejecting', [
            'name' => 'Olimpiade Kimia Nasional Universitas Diponegoro 2026',
            'who' => 'Nadia Putri Anindya · XI-4',
            'team' => false,
            'note' => "Sertifikat bukan atas nama siswa.\nNama di piagam berbeda.",
            'notice' => 'Siswa dan guru pembimbing langsung diberi tahu.',
        ])
        ->assertSee('Tolak laporan ini?')
        ->assertSee('Siswa dan guru pembimbing langsung diberi tahu.')
        ->assertSee('Pendaftaran ini selesai tanpa poin. Keputusan ini tidak bisa diubah.')
        ->assertSee('Alasan untuk siswa')
        ->assertSee('Nama di piagam berbeda.')
        ->assertSee('Tolak laporan')
        ->assertDontSee('Tidak ada laporan yang dipilih.');

    expect($report->fresh()->status)->toBe(ReportStatus::Dikirim)
        ->and($report->registration->fresh()->status)->toBe(RegistrationStatus::LaporanDikirim);
    Notification::assertNothingSent();
});

it('names the team and its remaining members in the dialog (D-128)', function (): void {
    $leader = F::student('Nadia Putri Anindya', 'XI-4');
    $dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $keisha = F::student('Keisha Larasati', 'XI-6');
    $left = F::student('Bima Satria Wicaksono', 'XI-4');
    $report = F::pendingReport($leader, [$dimas, $keisha, $left]);
    RegistrationMember::query()->where('registration_id', $report->registration_id)->where('student_id', $left->getKey())
        ->firstOrFail()->forceFill(['confirmed_at' => null, 'declined_at' => now()])->save();

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->set('note', 'Sertifikat tidak sesuai dengan lomba.')
        ->call('askReject')
        ->assertViewHas('rejecting', fn (array $rejecting): bool => $rejecting['team'] === true
            && $rejecting['who'] === 'Tim 3 siswa: Nadia Putri Anindya, Dimas Arya Nugroho, Keisha Larasati'
            && $rejecting['notice'] === 'Siswa langsung diberi tahu.')
        ->assertSee('Pendaftaran tim ini selesai tanpa poin untuk semua anggota. Keputusan ini tidak bisa diubah.')
        ->assertDontSee('Tim 4 siswa');
});

it('promises a notice in the dialog only to the people who get one (D-109)', function (string $case, string $notice): void {
    $leader = F::student('Nadia Putri Anindya', 'XI-4');
    $dimas = F::student('Dimas Arya Nugroho', 'XI-4');

    $report = match ($case) {
        'all, with guru' => F::pendingReport($leader, [$dimas], mentor: $this->guru),
        'all, no guru' => F::pendingReport($leader, [$dimas]),
        'some, with guru' => tap(F::pendingReport($leader, [$dimas], mentor: $this->guru), fn () => d128Freeze($dimas)),
        'some, no guru' => tap(F::pendingReport($leader, [$dimas]), fn () => d128Freeze($dimas, UserStatus::Archived)),
        'guru only' => tap(F::pendingReport($leader, mentor: $this->guru), fn () => d128Freeze($leader, UserStatus::Archived)),
        'nobody' => tap(F::pendingReport($leader), fn () => d128Freeze($leader)),
    };

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->assertSet('selectedId', $report->getKey())
        ->set('note', 'Sertifikat tidak sesuai dengan lomba.')
        ->call('askReject')
        ->assertDispatched('sth-modal-open', id: 'tolak-laporan')
        ->assertViewHas('rejecting', fn (array $rejecting): bool => $rejecting['notice'] === $notice)
        ->assertSee($notice);
})->with([
    'all, with guru' => ['all, with guru', 'Siswa dan guru pembimbing langsung diberi tahu.'],
    'all, no guru' => ['all, no guru', 'Siswa langsung diberi tahu.'],
    'some, with guru' => ['some, with guru', 'Siswa yang akunnya aktif dan guru pembimbing langsung diberi tahu.'],
    'some, no guru' => ['some, no guru', 'Siswa yang akunnya aktif langsung diberi tahu.'],
    'guru only' => ['guru only', 'Akun siswa tidak aktif. Hanya guru pembimbing yang diberi tahu.'],
    'nobody' => ['nobody', 'Akun siswa tidak aktif, jadi tidak ada notifikasi.'],
]);

it('rejects only after the confirmation: report ditolak, registration selesai without points, the team and the guru are told (D-128)', function (): void {
    $leader = F::student('Nadia Putri Anindya', 'XI-4');
    $report = F::pendingReport($leader, mentor: $this->guru);
    $next = F::pendingReport(F::student('Raka Pradipta', 'XII-2'));
    $next->forceFill(['submitted_at' => now()->subHours(2)])->save(); // the queue runs oldest first

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->assertSet('selectedId', $report->getKey())
        ->set('note', 'Sertifikat bukan atas nama siswa.')
        ->call('askReject')
        ->call('reject')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-close', id: 'tolak-laporan')
        ->assertSet('rejectingId', null)
        ->assertSet('decision', ['tone' => 'red', 'title' => 'Laporan ditolak', 'text' => 'Siswa dan guru pembimbing menerima alasan penolakan.'])
        ->assertDispatched('sth-toast', message: 'Laporan ditolak.', tone: 'success')
        ->assertSee('Siswa dan guru pembimbing menerima alasan penolakan.')
        // The next report of the queue is on screen, with an empty reason field.
        ->assertSet('selectedId', $next->getKey())
        ->assertSet('note', '');

    expect($report->fresh()->status)->toBe(ReportStatus::Ditolak)
        ->and($report->fresh()->note)->toBe('Sertifikat bukan atas nama siswa.')
        ->and($report->fresh()->verified_by)->toBe($this->kesiswaan->getKey())
        ->and(Registration::query()->findOrFail($report->registration_id)->status)->toBe(RegistrationStatus::Selesai)
        ->and(Achievement::query()->count())->toBe(0)
        ->and($next->fresh()->status)->toBe(ReportStatus::Dikirim);
    Notification::assertSentToTimes($leader->user, ReportRejected::class, 1);
    Notification::assertSentToTimes($this->guru, BinaanReportDecided::class, 1);
});

// Forbidden cases ----------------------------------------------------------------------------------------------------

it('changes nothing when "Tolak laporan" arrives without the question, and shows no error page (D-128)', function (): void {
    $report = F::pendingReport();

    // A second click after the rejection went through, or a forged call: a silent no-op, never a 404.
    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->set('note', 'Sertifikat bukan atas nama siswa.')
        ->call('reject')
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSet('decision', null)
        ->assertNotDispatched('sth-toast')
        ->assertSet('selectedId', $report->getKey())
        // What was typed stays for the real question.
        ->assertSet('note', 'Sertifikat bukan atas nama siswa.');

    expect($report->fresh()->status)->toBe(ReportStatus::Dikirim)
        ->and(Registration::query()->findOrFail($report->registration_id)->status)->toBe(RegistrationStatus::LaporanDikirim);
    Notification::assertNothingSent();
});

it('rejects once when "Tolak laporan" is pressed twice (D-128)', function (): void {
    $leader = F::student('Nadia Putri Anindya', 'XI-4');
    $report = F::pendingReport($leader);
    $next = F::pendingReport(F::student('Raka Pradipta', 'XII-2'));
    $next->forceFill(['submitted_at' => now()->subHours(2)])->save();

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->set('note', 'Sertifikat bukan atas nama siswa.')
        ->call('askReject')
        ->call('reject')
        ->call('reject')
        ->assertOk()
        ->assertSet('selectedId', $next->getKey());

    // The second click found the next report on screen: it is not rejected with it.
    expect($report->fresh()->status)->toBe(ReportStatus::Ditolak)
        ->and($next->fresh()->status)->toBe(ReportStatus::Dikirim);
    Notification::assertSentToTimes($leader->user, ReportRejected::class, 1);
});

it('voids the question when another report is shown: neither one is rejected (D-128)', function (): void {
    $a = F::pendingReport();
    $b = F::pendingReport(F::student('Raka Pradipta', 'XII-2'));
    $b->forceFill(['submitted_at' => now()->subHours(2)])->save();

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->assertSet('selectedId', $a->getKey())
        ->set('note', 'Sertifikat bukan atas nama siswa.')
        ->call('askReject')
        ->assertSet('rejectingId', $a->getKey())
        ->call('select', $b->getKey())
        ->assertSet('rejectingId', null)
        ->assertViewHas('rejecting', null)
        ->set('note', 'Catatan untuk laporan kedua.')
        ->call('reject')
        ->assertOk()
        ->assertSet('decision', null);

    expect($a->fresh()->status)->toBe(ReportStatus::Dikirim)
        ->and($b->fresh()->status)->toBe(ReportStatus::Dikirim);
    Notification::assertNothingSent();
});

it('keeps the dialog state locked: the browser cannot claim a question (D-128)', function (): void {
    $report = F::pendingReport();

    expect(fn () => Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)->set('rejectingId', $report->getKey()))
        ->toThrow(Exception::class)
        ->and($report->fresh()->status)->toBe(ReportStatus::Dikirim);
});

it('refuses the question to the report\'s own guru pembimbing, who has no "Tolak" button (D-128)', function (): void {
    // SECURITY §3c-10: a verifier never decides a report of the own binaan.
    $report = F::pendingReport(mentor: $this->kesiswaan);

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->assertSet('selectedId', $report->getKey())
        ->assertSee('Laporan binaan sendiri diverifikasi petugas Kesiswaan lain.')
        ->assertDontSeeHtml('wire:click="askReject"')
        ->set('note', 'Sertifikat bukan atas nama siswa.')
        ->call('askReject')
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

    Livewire::actingAs(F::student('Raka Pradipta', 'XII-2')->user)->test(VerifikasiHasil::class)->assertForbidden();

    expect($report->fresh()->status)->toBe(ReportStatus::Dikirim);
});

it('closes the dialog and rejects nothing when a colleague decided the report while it was open (D-128)', function (): void {
    $leader = F::student('Nadia Putri Anindya', 'XI-4');
    $report = F::pendingReport($leader);
    $colleague = F::staff(RoleName::Kesiswaan, 'Pak Bayu Nugraha');

    $component = Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->set('note', 'Sertifikat bukan atas nama siswa.')
        ->call('askReject')
        ->assertDispatched('sth-modal-open', id: 'tolak-laporan');

    app(RequestReportRevision::class)->handle($colleague, $report->fresh(), 'Foto sertifikat buram, kirim ulang.');

    $component->call('reject')
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Laporan ini sudah diputuskan. Antrean sudah diperbarui.', tone: 'warning')
        ->assertDispatched('sth-modal-close', id: 'tolak-laporan')
        ->assertSet('rejectingId', null)
        ->assertSet('decision', null);

    expect($report->fresh()->status)->toBe(ReportStatus::PerluRevisi)
        ->and($report->fresh()->note)->toBe('Foto sertifikat buram, kirim ulang.')
        ->and($report->fresh()->verified_by)->toBe($colleague->getKey());
    Notification::assertNotSentTo($leader->user, ReportRejected::class);
    Notification::assertSentToTimes($leader->user, ReportRevisionRequested::class, 1);
});

it('opens no dialog for a report a colleague already decided (D-128)', function (): void {
    $report = F::pendingReport();
    $component = Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)->set('note', 'Sertifikat bukan atas nama siswa.');

    app(RejectReport::class)->handle(F::staff(RoleName::Kesiswaan, 'Pak Bayu Nugraha'), $report->fresh(), 'Bukan lomba yang didaftarkan.');

    $component->call('askReject')
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Laporan ini sudah diputuskan. Antrean sudah diperbarui.', tone: 'warning')
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('rejectingId', null);

    expect($report->fresh()->note)->toBe('Bukan lomba yang didaftarkan.');
});

// Honest result banners (D-109) ------------------------------------------------------------------------------------

it('claims no notice in the banner after rejecting a report nobody is told about (D-109)', function (): void {
    $leader = F::student('Nadia Putri Anindya', 'XI-4');
    $report = F::pendingReport($leader);
    d128Freeze($leader, UserStatus::Archived);

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->set('note', 'Sertifikat bukan atas nama siswa.')
        ->call('askReject')
        ->assertSee('Akun siswa tidak aktif, jadi tidak ada notifikasi.')
        ->call('reject')
        ->assertSet('decision', ['tone' => 'red', 'title' => 'Laporan ditolak', 'text' => 'Akun siswa tidak aktif, jadi tidak ada notifikasi.'])
        ->assertDontSee('menerima alasan penolakan');

    expect($report->fresh()->status)->toBe(ReportStatus::Ditolak);
    Notification::assertNothingSent();
});

it('names who got the note in the banner after "Minta revisi" (D-109)', function (string $case, string $text): void {
    $leader = F::student('Nadia Putri Anindya', 'XI-4');
    $dimas = F::student('Dimas Arya Nugroho', 'XI-4');

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
