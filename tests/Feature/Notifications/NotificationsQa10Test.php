<?php

declare(strict_types=1);

use App\Actions\Reports\RequestReportRevision;
use App\Actions\Reports\VerifyReport;
use App\Actions\Roles\ApplyRoleChange;
use App\Actions\Roles\ApproveRoleChange;
use App\Actions\Roles\CancelRoleChange;
use App\Actions\Users\ApplyPrincipalReplacement;
use App\Actions\Users\RequestPrincipalReplacement;
use App\Enums\RegistrationStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\File;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\Admin\AdminNotice;
use App\Notifications\Admin\LetterRevokedNotice;
use App\Notifications\Admin\PrincipalReplacementNotice;
use App\Notifications\Admin\RoleChangeNotice;
use App\Notifications\Admin\SignatureReplaced;
use App\Notifications\Letters\LetterRevised;
use App\Notifications\Letters\LettersIssued;
use App\Notifications\Verification\AchievementReadyForPublication;
use App\Notifications\Verification\ReportRevisionRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;

/*
 * QA10: notices that pointed to the wrong place, told the wrong people or said the wrong thing. Letter links pointed
 * to one file version (404 after TU's "Koreksi"); Humas was told about an empty publication queue; the letter
 * revocation notice (lomba + TU's reason) reached an unverified Kepala Sekolah address (D-68 allows account notices
 * only); a Kepala Sekolah was told to cancel in an Admin-only menu and never heard how a delayed change ended;
 * every member was asked to "Perbaiki laporan" although only the ketua may. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    Notification::fake();
});

/** Body text of a notice as the given account receives it (bell entry). */
function qa10NoticeBody(object $notice, User $to): string
{
    return (string) $notice->toArray($to)['body'];
}

/** The lines of a mail as the reader sees them (PlainMailMessage escapes every character that Markdown could use). */
function qa10NoticeMailText(MailMessage $mail): string
{
    return html_entity_decode(implode(' ', array_map(fn ($line): string => (string) $line, $mail->introLines)), ENT_QUOTES | ENT_HTML5);
}

// Letters: link to the screen with the current letters (item 2) ---------------------------------------------------

it('links the letter notices to the screen that lists the current letters, not to one file version (QA10)', function (): void {
    $guru = A::staff(RoleName::Guru, ['name' => 'Retno Wulandari, S.Pd.']);
    $registration = Registration::factory()->status(RegistrationStatus::SuratTerbit)->create(['mentor_id' => $guru->getKey()]);
    $student = $registration->leader->user;
    $siswaFile = File::factory()->create();
    Letter::factory()->create(['registration_id' => $registration->getKey(), 'file_id' => $siswaFile->getKey()]);
    Letter::factory()->guru()->create(['registration_id' => $registration->getKey(), 'file_id' => File::factory()->create()->getKey()]);

    $siswaUrl = route('siswa.lomba-saya.show', ['registration' => $registration->getKey()]);
    $guruUrl = route('guru.binaan.show', ['registration' => $registration->getKey()]);

    $notices = [
        [LettersIssued::for($registration, 'siswa'), $student, $siswaUrl],
        [LettersIssued::for($registration, 'guru'), $guru, $guruUrl],
        [new LetterRevised($registration, 'siswa', '400.3.8/313/MRD/X/2026', '400.3.8/320/MRD/X/2026', (string) $siswaFile->getKey()), $student, $siswaUrl],
        [new LetterRevised($registration, 'guru', '400.3.8.6/1681/2026', '400.3.8.6/1690/2026', (string) $siswaFile->getKey()), $guru, $guruUrl],
    ];

    foreach ($notices as [$notice, $to, $url]) {
        $mail = $notice->toMail($to);

        expect($notice->toArray($to)['url'])->toBe($url)
            ->and($mail->actionUrl)->toBe($url)
            ->and($mail->actionText)->not->toContain('Download');
    }
});

// Humas: no notice about an empty queue (item 3) ------------------------------------------------------------------

it('does not tell Humas about a verified report when no member may be named (QA10)', function (): void {
    K::boot();
    $humas = K::staff(RoleName::Humas, 'Mas Galih Prasetyo');
    $leader = K::student('Nadia Putri Anindya', 'XI-D');
    $member = K::student('Dimas Arya Nugroho', 'XI-D');
    $report = K::pendingReport($leader, [$member], ResultType::Juara1);
    $leader->user->forceFill(['status' => UserStatus::Disabled])->save();
    $member->user->forceFill(['status' => UserStatus::Disabled])->save();

    app(VerifyReport::class)->handle(K::staff(RoleName::Kesiswaan), $report, ResultType::Juara1, K::allChecks());

    Notification::assertNotSentTo($humas, AchievementReadyForPublication::class);
});

it('tells Humas about every member that may be named, whatever the old consent columns say (D-162)', function (): void {
    K::boot();
    $humas = K::staff(RoleName::Humas, 'Mas Galih Prasetyo');
    $leader = K::student('Nadia Putri Anindya', 'XI-D');
    $leader->forceFill(['publish_consent' => false, 'photo_consent' => false])->save();
    $report = K::pendingReport($leader, [K::student('Dimas Arya Nugroho', 'XI-D'), K::student('Keisha Larasati', 'XI-F')], ResultType::Juara1);

    app(VerifyReport::class)->handle(K::staff(RoleName::Kesiswaan), $report, ResultType::Juara1, K::allChecks());

    Notification::assertSentTo($humas, AchievementReadyForPublication::class, function (AchievementReadyForPublication $n) use ($humas): bool {
        $body = qa10NoticeBody($n, $humas);

        return str_starts_with($body, 'Tim 3 siswa meraih') && ! str_contains($body, 'persetujuan publikasi');
    });
});

it('counts only the members Humas may name (QA10)', function (): void {
    K::boot();
    $humas = K::staff(RoleName::Humas, 'Mas Galih Prasetyo');
    $leader = K::student('Nadia Putri Anindya', 'XI-D');
    $dimas = K::student('Dimas Arya Nugroho', 'XI-D');
    $keisha = K::student('Keisha Larasati', 'XI-F');
    $report = K::pendingReport($leader, [$dimas, $keisha], ResultType::Juara1);
    $dimas->user->forceFill(['status' => UserStatus::Disabled])->save();
    $keisha->user->forceFill(['status' => UserStatus::Disabled])->save();

    app(VerifyReport::class)->handle(K::staff(RoleName::Kesiswaan), $report, ResultType::Juara1, K::allChecks());

    Notification::assertSentTo($humas, AchievementReadyForPublication::class, fn (AchievementReadyForPublication $n): bool => str_starts_with(qa10NoticeBody($n, $humas), 'Satu siswa meraih'));
});

// Letter revocation: not an account notice (item 5) ---------------------------------------------------------------

it('does not mail the letter revocation to an unverified Kepala Sekolah address, but keeps the bell entry (QA10)', function (): void {
    $unverified = A::staff(RoleName::KepalaSekolah, ['email_verified_at' => null]);
    $verified = A::staff(RoleName::KepalaSekolah, ['name' => 'Dra. Sri Wahyuni, M.Pd.']);
    $notice = new LetterRevokedNotice('Sri Handayani', 'surat tugas siswa', '400.3.8/313/MRD/X/2026', '400.3.8/320/MRD/X/2026', 'OPSI 2026', 'Tanggal pelaksanaan salah ketik.');

    expect($notice)->not->toBeInstanceOf(AdminNotice::class)
        ->and($unverified->routeNotificationForMail($notice))->toBeNull()
        ->and($notice->via($unverified))->toContain('database')
        ->and($verified->routeNotificationForMail($notice))->toBe($verified->email)
        // An account notice still reaches the unverified address (D-68).
        ->and($unverified->routeNotificationForMail(new SignatureReplaced('Operator Sekolah')))->toBe($unverified->email);
});

// Role changes: wording and recipients for the Kepala Sekolah (item 6) --------------------------------------------

it('asks a non-Admin to have an Admin cancel the Kepala Sekolah replacement, and tells an Admin where (QA10)', function (): void {
    $admin = A::admin();
    $kepsek = A::staff(RoleName::KepalaSekolah);
    $notices = [
        new PrincipalReplacementNotice(PrincipalReplacementNotice::REQUESTED, 'Drs. Bambang Hartono, M.Pd.', 'Dra. Sri Wahyuni, M.Pd.', '10 Oktober 2026 09:00'),
    ];

    foreach ($notices as $notice) {
        $forKepsek = qa10NoticeBody($notice, $kepsek);
        $mail = qa10NoticeMailText($notice->toMail($kepsek));

        expect($forKepsek)->not->toContain('Pengguna & peran')->toContain('minta Admin membatalkannya')
            ->and($mail)->not->toContain('Pengguna & peran')->toContain('minta Admin membatalkannya')
            ->and(qa10NoticeBody($notice, $admin))->toContain('batalkan di menu Pengguna & peran');
    }
});

it('tells the Kepala Sekolah how a delayed role change ended (QA10)', function (string $end): void {
    $admin = A::admin();
    $kepsek = A::staff(RoleName::KepalaSekolah);
    $guru = A::staff(RoleName::Guru, ['name' => 'Pak Hendra Kusuma']);
    $request = A::legacyRoleRequest($admin, $guru, RoleName::Kesiswaan, 'Kesiswaan baru.', delay: true);
    expect($request->usesDelay())->toBeTrue();

    if ($end === 'applied') {
        $this->travel(25)->hours();
        expect(app(ApplyRoleChange::class)->applyDue())->toBe(1);
    } elseif ($end === 'cancelled automatically') {
        $guru->forceFill(['status' => UserStatus::Disabled])->save();
        $this->travel(25)->hours();
        expect(app(ApplyRoleChange::class)->applyDue())->toBe(0);
    } else {
        app(CancelRoleChange::class)->handle($admin, $request, 'Salah pilih akun.');
    }

    $kind = $end === 'applied' ? 'role.applied' : 'role.cancelled';
    Notification::assertSentTo($kepsek, RoleChangeNotice::class, fn (RoleChangeNotice $n): bool => $n->kind() === $kind);
})->with(['applied', 'cancelled by an Admin', 'cancelled automatically']);

it('leaves the Kepala Sekolah out when a second Admin approves (no delay) (QA10 guard)', function (): void {
    $admin = A::admin();
    $second = A::admin(['name' => 'Ibu Ratna Sari', 'username' => 'operator2']);
    $kepsek = A::staff(RoleName::KepalaSekolah);
    $request = A::legacyRoleRequest($admin, A::staff(RoleName::Guru), RoleName::Kesiswaan, 'Kesiswaan baru.');

    app(ApproveRoleChange::class)->handle($second, $request);

    Notification::assertNotSentTo($kepsek, RoleChangeNotice::class);
});

it('tells the current Kepala Sekolah when the replacement cancels itself (QA10)', function (): void {
    $admin = A::admin();
    $old = A::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
    $new = A::staff(RoleName::Guru, ['name' => 'Dra. Sri Wahyuni, M.Pd.']);
    app(RequestPrincipalReplacement::class)->handle($admin, $new, 'SK Kepala Sekolah baru.');
    $new->forceFill(['status' => UserStatus::Disabled])->save();

    $this->travel(25)->hours();
    expect(app(ApplyPrincipalReplacement::class)->applyDue())->toBe(0);

    Notification::assertSentTo($old, PrincipalReplacementNotice::class, fn (PrincipalReplacementNotice $n): bool => $n->kind() === 'principal.cancelled');
    Notification::assertSentTo($admin, PrincipalReplacementNotice::class, fn (PrincipalReplacementNotice $n): bool => $n->kind() === 'principal.cancelled');
});

// Report revision: only the ketua fixes it (item 7) ---------------------------------------------------------------

it('asks only the ketua to fix the report; the other members are told the ketua does it (QA10)', function (): void {
    K::boot();
    $leader = K::student('Nadia Putri Anindya', 'XI-D');
    $member = K::student('Dimas Arya Nugroho', 'XI-D');
    $report = K::pendingReport($leader, [$member]);

    app(RequestReportRevision::class)->handle(K::staff(RoleName::Kesiswaan), $report, 'Upload sertifikat yang terbaca');

    Notification::assertSentTo($leader->user, ReportRevisionRequested::class, function (ReportRevisionRequested $n) use ($leader): bool {
        $mail = $n->toMail($leader->user);

        return $mail->actionText === 'Perbaiki laporan' && str_starts_with((string) $mail->subject, 'Perbaiki laporan hasil');
    });
    Notification::assertSentTo($member->user, ReportRevisionRequested::class, function (ReportRevisionRequested $n) use ($member): bool {
        $mail = $n->toMail($member->user);
        $bell = $n->toArray($member->user);

        return ! str_contains((string) $mail->actionText, 'Perbaiki')
            && ! str_starts_with((string) $mail->subject, 'Perbaiki')
            && str_contains(qa10NoticeMailText($mail), 'Ketua tim memperbaiki laporan')
            && str_contains(qa10NoticeMailText($mail), 'Upload sertifikat yang terbaca.')
            && ! str_starts_with((string) $bell['title'], 'Perbaiki')
            && str_contains((string) $bell['body'], 'Ketua tim memperbaiki laporan');
    });
});
