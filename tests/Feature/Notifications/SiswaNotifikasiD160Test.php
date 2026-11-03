<?php

declare(strict_types=1);

use App\Actions\Publications\PublishPublication;
use App\Actions\Publications\RecordAppreciation;
use App\Actions\Reports\RequestReportRevision;
use App\Actions\Reports\SubmitReport;
use App\Actions\Reports\SubmitReportData;
use App\Actions\Reports\VerifyReport;
use App\Enums\AppreciationStatus;
use App\Enums\PublicationStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Humas\Publikasi;
use App\Livewire\Siswa\Portofolio;
use App\Models\Appreciation;
use App\Models\Publication;
use App\Models\User;
use App\Notifications\Publications\AchievementPublished;
use App\Notifications\Publications\AppreciationScheduled;
use App\Notifications\Verification\ReportRevisionRequested;
use App\Notifications\Verification\ReportSent;
use App\Notifications\Verification\ReportVerified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures as H;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;
use Tests\Feature\Siswa\Hasil\HasilFixtures as S;
use Tests\Support\TalentGate;

/*
 * D-160 (the lead, 2026-10-19: "Mulai dari mengupload hasil lomba sampai diberi jadwal apresiasi oleh humas, pastikan
 * ada notifikasi ke siswa dengan benar"): every member hears about every step, not only the one whose card was open.
 * Fictional data only.
 */

uses(RefreshDatabase::class);

/** The text a recipient reads in the bell (title + body), from the notice as it is stored. */
function d160Text(object $notice, User $to): string
{
    $data = $notice->toArray($to);

    return $data['title'].' | '.$data['body'];
}

// Laporan hasil sent ------------------------------------------------------------------------------------------------

it('tells the ketua (a receipt) and every member when the report is sent, and again after a revision', function (): void {
    Notification::fake();
    S::boot();
    S::today('2026-10-01');
    $kesiswaan = S::staff(RoleName::Kesiswaan, 'Ibu Dwi Rahayu');
    $nadia = S::student();
    $dimas = S::student('Dimas Arya Nugroho');
    $registration = S::registration($nadia, members: [$dimas]);
    $data = fn (): SubmitReportData => new SubmitReportData(
        result: ResultType::MedaliEmas,
        authentic: true,
        certificate: S::pdf(),
        photos: [S::jpg()],
        researchTitle: 'Biofilter sabut kelapa untuk air sumur payau',
        researchAbstract: 'Biofilter berbahan sabut kelapa diuji untuk menurunkan kadar garam air sumur.',
        researchReport: S::pdf('laporan-penelitian.pdf'),
    );

    $report = app(SubmitReport::class)->handle($nadia->user, $registration, $data());

    Notification::assertSentTo($nadia->user, ReportSent::class, fn (ReportSent $n): bool => d160Text($n, $nadia->user)
        === 'Laporan hasil terkirim: WICE 2026 | Laporan hasilmu sudah diterima dan menunggu verifikasi sekolah. Kamu diberi tahu lagi setelah sekolah memeriksanya.'
        && $n->toArray($nadia->user)['url'] === route('siswa.lomba-saya.show', $registration));
    Notification::assertSentTo($dimas->user, ReportSent::class, fn (ReportSent $n): bool => d160Text($n, $dimas->user)
        === 'Laporan hasil dikirim: WICE 2026 | Nadia Putri Anindya mengirim laporan hasil tim. Laporan menunggu verifikasi sekolah.');
    Notification::assertNotSentTo($kesiswaan, ReportSent::class);

    app(RequestReportRevision::class)->handle($kesiswaan, $report->fresh(), 'Sertifikat buram, kirim ulang.');
    Notification::fake();

    app(SubmitReport::class)->handle($nadia->user, $registration->fresh(), $data());

    Notification::assertSentTo($nadia->user, ReportSent::class, fn (ReportSent $n): bool => str_starts_with(d160Text($n, $nadia->user), 'Perbaikan laporan terkirim: WICE 2026 | Perbaikan laporan hasilmu sudah diterima'));
    Notification::assertSentTo($dimas->user, ReportSent::class, fn (ReportSent $n): bool => d160Text($n, $dimas->user)
        === 'Laporan hasil diperbaiki: WICE 2026 | Nadia Putri Anindya mengirim perbaikan laporan hasil tim. Laporan menunggu verifikasi sekolah.');

    S::resetToday();
});

it('does not tell a member whose account cannot log in that the report was sent', function (): void {
    Notification::fake();
    S::boot();
    S::today('2026-10-01');
    $nadia = S::student();
    $dimas = S::student('Dimas Arya Nugroho');
    $registration = S::registration($nadia, members: [$dimas]);
    $dimas->user->forceFill(['status' => UserStatus::Disabled])->save();

    app(SubmitReport::class)->handle($nadia->user, $registration, new SubmitReportData(
        result: ResultType::MedaliEmas,
        authentic: true,
        certificate: S::pdf(),
        photos: [S::jpg()],
        researchTitle: 'Biofilter sabut kelapa untuk air sumur payau',
        researchAbstract: 'Biofilter berbahan sabut kelapa diuji untuk menurunkan kadar garam air sumur.',
        researchReport: S::pdf('laporan-penelitian.pdf'),
    ));

    Notification::assertSentTo($nadia->user, ReportSent::class);
    Notification::assertNotSentTo($dimas->user, ReportSent::class);

    S::resetToday();
});

// Verification note and a revision without a ketua ------------------------------------------------------------------

it('sends the verifier\'s note to the students with the points and shows it on Lomba saya', function (): void {
    K::boot();
    Notification::fake();
    $kesiswaan = K::staff(RoleName::Kesiswaan);
    $leader = K::student('Nadia Putri Anindya', 'XI-D');
    $dimas = K::student('Dimas Arya Nugroho', 'XI-D');
    $report = K::pendingReport($leader, [$dimas], ResultType::Juara2);

    app(VerifyReport::class)->handle($kesiswaan, $report, ResultType::Juara3, K::allChecks(), 'Sertifikat menulis Juara 3, jadi capaian disesuaikan.');

    foreach ([$leader->user, $dimas->user] as $user) {
        Notification::assertSentTo($user, ReportVerified::class, fn (ReportVerified $n): bool => str_ends_with(
            $n->toArray($user)['body'],
            'Kamu mendapat 33,6 poin. Catatan Kesiswaan: Sertifikat menulis Juara 3, jadi capaian disesuaikan.',
        ));
    }

    TalentGate::pass($leader);
    $this->actingAs($leader->user)
        ->get(route('siswa.lomba-saya.show', $report->registration_id))
        ->assertOk()
        ->assertSee('Catatan Kesiswaan:')
        ->assertSee('Sertifikat menulis Juara 3, jadi capaian disesuaikan.');
});

it('verifies without a note in the notice when the verifier wrote none', function (): void {
    K::boot();
    Notification::fake();
    $leader = K::student('Nadia Putri Anindya', 'XI-D');
    $report = K::pendingReport($leader, [], ResultType::Juara3);

    app(VerifyReport::class)->handle(K::staff(RoleName::Kesiswaan), $report, ResultType::Juara3, K::allChecks(), '');

    Notification::assertSentTo($leader->user, ReportVerified::class, fn (ReportVerified $n): bool => ! str_contains($n->toArray($leader->user)['body'], 'Catatan'));
});

it('tells the members to ask Kesiswaan when the ketua cannot log in to fix the report', function (): void {
    K::boot();
    Notification::fake();
    $leader = K::student('Nadia Putri Anindya', 'XI-D');
    $dimas = K::student('Dimas Arya Nugroho', 'XI-D');
    $report = K::pendingReport($leader, [$dimas]);
    $leader->user->forceFill(['status' => UserStatus::Disabled])->save();

    app(RequestReportRevision::class)->handle(K::staff(RoleName::Kesiswaan), $report, 'Foto dokumentasi belum ada.');

    Notification::assertSentTo($dimas->user, ReportRevisionRequested::class, fn (ReportRevisionRequested $n): bool => d160Text($n, $dimas->user)
        === 'Laporan hasil perlu revisi: '.$report->registration->competition->name.' | Catatan Kesiswaan: Foto dokumentasi belum ada. Akun ketua tim tidak bisa dipakai masuk, jadi hubungi Kesiswaan untuk melanjutkan laporan ini.');
});

// Humas: a team post and a team on an appreciation event -------------------------------------------------------------

it('publishes the teammates\' waiting cards with the clicked one and tells every member', function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $humas = H::humas();
    $publication = H::publication();
    $raka = H::teammate($publication->achievement, 'Raka Pradipta');
    $rakaCard = new Publication(['achievement_id' => $raka->getKey()]);
    $rakaCard->forceFill(['status' => PublicationStatus::Terjadwal, 'channels' => ['website'], 'scheduled_at' => now()->addDay()])->save();
    // A member with a disabled account has no card Humas may publish: it stays as it is.
    $sinta = H::teammate($publication->achievement, 'Sinta Maharani', 'XII-B', disabled: true);
    $sintaCard = new Publication(['achievement_id' => $sinta->getKey()]);
    $sintaCard->forceFill(['status' => PublicationStatus::Siap, 'channels' => []])->save();

    expect(PublishPublication::teammates($humas, $publication)->modelKeys())->toBe([$rakaCard->getKey()]);

    $action = app(PublishPublication::class);
    $action->handle($humas, $publication, 'Selamat kepada tim OPSI 2026!', ['instagram']);

    expect($publication->fresh()->status)->toBe(PublicationStatus::Dipublikasikan)
        ->and($rakaCard->fresh()->status)->toBe(PublicationStatus::Dipublikasikan)
        ->and($rakaCard->fresh()->caption)->toBe('Selamat kepada tim OPSI 2026!')
        ->and($rakaCard->fresh()->channels)->toBe(['instagram'])
        ->and($sintaCard->fresh()->status)->toBe(PublicationStatus::Siap)
        ->and(array_map(fn (Publication $p): string => (string) $p->getKey(), $action->team))->toBe([(string) $rakaCard->getKey()])
        ->and(Activity::query()->where('event', 'publication.published')->where('subject_id', $rakaCard->getKey())->sole()->properties['together_with'])
        ->toBe((string) $publication->getKey());

    Notification::assertSentTo($publication->achievement->student->user, AchievementPublished::class);
    Notification::assertSentTo($raka->student->user, AchievementPublished::class);
    Notification::assertNotSentTo($sinta->student->user, AchievementPublished::class);
});

it('names the teammates in the publish dialog and counts who was told in the toast', function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $humas = H::humas();
    $publication = H::publication();
    $raka = H::teammate($publication->achievement, 'Raka Pradipta');
    (new Publication(['achievement_id' => $raka->getKey()]))->forceFill(['status' => PublicationStatus::Siap, 'channels' => []])->save();

    $this->actingAs($humas);

    Livewire::test(Publikasi::class)
        ->call('select', (string) $publication->getKey())
        ->set('channels', ['instagram'])
        ->call('askPublish')
        ->assertSee('Kartu anggota tim lain ikut ditandai terbit: Raka Pradipta.')
        ->assertSee('2 siswa langsung diberi tahu.')
        ->call('publish')
        ->assertDispatched('sth-toast', message: 'Publikasi ditandai sudah terbit untuk 2 anggota tim. 2 siswa sudah diberi tahu.');
});

it('puts the free teammates on the event with "Masukkan" in the Publikasi panel and tells each', function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $humas = H::humas();
    $publication = H::publication();
    $raka = H::teammate($publication->achievement, 'Raka Pradipta');
    $sinta = H::teammate($publication->achievement, 'Sinta Maharani', 'XII-B', disabled: true);
    $event = H::event('Upacara Senin', null, '07:00');

    expect(RecordAppreciation::withTeammates($publication->achievement)->modelKeys())
        ->toBe([$publication->achievement->getKey(), $raka->getKey()]);

    $this->actingAs($humas);

    Livewire::test(Publikasi::class)
        ->call('select', (string) $publication->getKey())
        ->assertSee('Anggota tim lain ikut dimasukkan: Raka Pradipta.')
        ->set('eventId', (string) $event->getKey())
        ->call('recordAppreciation')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: '2 anggota tim masuk daftar apresiasi. 2 siswa sudah diberi tahu.');

    expect(Appreciation::query()->where('appreciation_event_id', $event->getKey())->pluck('achievement_id')->sort()->values()->all())
        ->toBe(collect([$publication->achievement_id, $raka->getKey()])->sort()->values()->all());

    Notification::assertSentTo($publication->achievement->student->user, AppreciationScheduled::class);
    Notification::assertSentTo($raka->student->user, AppreciationScheduled::class);
    Notification::assertNotSentTo($sinta->student->user, AppreciationScheduled::class);
});

it('shows the appreciation and the publication on the student\'s Portofolio, where the notices link to', function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $publication = H::publication([], ['status' => PublicationStatus::Dipublikasikan, 'published_at' => '2026-10-15 10:00:00', 'channels' => ['instagram']]);
    $event = H::event('Upacara Senin', now()->addDays(3)->toDateString(), '07:00');
    $row = new Appreciation(['appreciation_event_id' => $event->getKey(), 'achievement_id' => $publication->achievement_id]);
    $row->forceFill(['status' => AppreciationStatus::Dijadwalkan])->save();
    $student = $publication->achievement->student;
    $student->user->forceFill(['status' => UserStatus::Active, 'must_change_password' => false, 'email_verified_at' => now()])->save();
    TalentGate::pass($student);

    $this->actingAs($student->user->fresh());

    Livewire::test(Portofolio::class)
        ->assertSee('Dijadwalkan diapresiasi di Upacara Senin, ')
        ->assertSee('07.00 WIB.')
        ->assertSee('Dipublikasikan Humas 15 Okt 2026.');
});
