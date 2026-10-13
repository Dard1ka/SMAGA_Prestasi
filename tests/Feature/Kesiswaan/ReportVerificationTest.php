<?php

declare(strict_types=1);

use App\Actions\Reports\RejectReport;
use App\Actions\Reports\RequestReportRevision;
use App\Actions\Reports\VerifyReport;
use App\Enums\PublicationStatus;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\WeightGroup;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Achievement;
use App\Models\Publication;
use App\Models\RegistrationMember;
use App\Models\WeightValue;
use App\Models\WeightVersion;
use App\Notifications\Verification\AchievementReadyForPublication;
use App\Notifications\Verification\BinaanReportDecided;
use App\Notifications\Verification\ReportRejected;
use App\Notifications\Verification\ReportRevisionRequested;
use App\Notifications\Verification\ReportVerified;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->humas = F::staff(RoleName::Humas, 'Mas Galih Prasetyo');
});

it('verifies a team report: full points for every member, snapshot, selesai, Humas queue, notifications', function (): void {
    $leader = F::student('Nadia Putri Anindya', 'XI-4');
    $dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $keisha = F::student('Keisha Larasati', 'XI-6');
    // QA10: Humas is told only when the queue shows someone (a member with publish consent).
    $leader->forceFill(['publish_consent' => true])->save();
    $report = F::pendingReport($leader, [$dimas, $keisha], ResultType::Juara3);
    $version = WeightVersion::active();

    $achievements = app(VerifyReport::class)->handle($this->kesiswaan, $report, ResultType::Juara3, F::allChecks(), 'Sertifikat sesuai.');

    expect($achievements)->toHaveCount(3);

    foreach ($achievements as $achievement) {
        $achievement->refresh();
        expect($achievement->points)->toBe('33.60')
            ->and($achievement->w_level)->toBe('80.00')
            ->and($achievement->w_result)->toBe('0.70')
            ->and($achievement->w_organizer)->toBe('0.60')
            ->and($achievement->weight_version_id)->toBe($version->getKey())
            ->and($achievement->result)->toBe(ResultType::Juara3)
            ->and($achievement->publication->status)->toBe(PublicationStatus::Siap);
    }

    $report->refresh();
    expect($report->status)->toBe(ReportStatus::Diverifikasi)
        ->and($report->final_result)->toBe(ResultType::Juara3)
        ->and($report->verified_by)->toBe($this->kesiswaan->getKey())
        ->and($report->registration->status)->toBe(RegistrationStatus::Selesai)
        ->and(Publication::query()->count())->toBe(3)
        ->and(Activity::query()->where('event', 'report.verified')->exists())->toBeTrue();

    Notification::assertSentTo([$leader->user, $dimas->user, $keisha->user], ReportVerified::class);
    Notification::assertSentTo($this->humas, AchievementReadyForPublication::class);
});

it('uses the capaian set by Kesiswaan, not the claim', function (): void {
    $report = F::pendingReport(claimed: ResultType::Juara1);

    $achievements = app(VerifyReport::class)->handle($this->kesiswaan, $report, ResultType::Finalis, F::allChecks());

    // 80 × 0,50 × 0,60 = 24
    expect($achievements->first()->fresh()->points)->toBe('24.00')
        ->and($report->fresh()->claimed_result)->toBe(ResultType::Juara1)
        ->and($report->fresh()->final_result)->toBe(ResultType::Finalis);
});

it('takes the weights from the active version at verification time', function (): void {
    $newer = WeightVersion::factory()->withDefaultValues()->create(['effective_at' => now()->subDay()]);
    WeightValue::query()->where('weight_version_id', $newer->getKey())->where('group', WeightGroup::Level->value)->where('key', 'nasional')->update(['value' => '90.00']);
    $report = F::pendingReport();

    $achievement = app(VerifyReport::class)->handle($this->kesiswaan, $report, ResultType::Juara3, F::allChecks())->first()->fresh();

    // 90 × 0,70 × 0,60 = 37,8
    expect($achievement->points)->toBe('37.80')
        ->and($achievement->weight_version_id)->toBe($newer->getKey());
});

it('requires all four checks', function (): void {
    $report = F::pendingReport();

    expect(fn () => app(VerifyReport::class)->handle($this->kesiswaan, $report, ResultType::Juara3, ['nama_peserta' => true, 'nama_lomba' => true, 'sertifikat_asli' => true]))
        ->toThrow(ValidationException::class);

    expect($report->fresh()->status)->toBe(ReportStatus::Dikirim)
        ->and(Achievement::query()->count())->toBe(0);
});

it('skips members who declined or never confirmed', function (): void {
    $leader = F::student('Nadia Putri Anindya', 'XI-4');
    $report = F::pendingReport($leader, [F::student('Dimas Arya Nugroho', 'XI-4')]);
    RegistrationMember::query()->where('student_id', '!=', $leader->getKey())->update(['confirmed_at' => null]);

    expect(app(VerifyReport::class)->handle($this->kesiswaan, $report, ResultType::Juara3, F::allChecks()))->toHaveCount(1);
});

it('never lets the guru pembimbing verify own binaan, even with Kesiswaan rights (§3c-10)', function (): void {
    $report = F::pendingReport(mentor: $this->kesiswaan);

    expect(fn () => app(VerifyReport::class)->handle($this->kesiswaan, $report, ResultType::Juara3, F::allChecks()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(RejectReport::class)->handle($this->kesiswaan, $report, 'Tidak valid'))
        ->toThrow(AuthorizationException::class);

    $other = F::staff(RoleName::Kesiswaan, 'Pak Budi Santoso');
    expect(app(VerifyReport::class)->handle($other, $report, ResultType::Juara3, F::allChecks()))->toHaveCount(1);
});

it('denies verification to siswa, guru, kepsek and tu; allows admin and humas (D-139)', function (): void {
    $report = F::pendingReport();

    foreach ([F::student('Raka Pradipta', 'XII-2')->user, F::staff(RoleName::Guru, 'Pak Hendra'), F::staff(RoleName::KepalaSekolah, 'Kepala'), F::staff(RoleName::TataUsaha, 'TU')] as $user) {
        expect(fn () => app(VerifyReport::class)->handle($user, $report, ResultType::Juara3, F::allChecks()))->toThrow(AuthorizationException::class);
    }

    expect(app(VerifyReport::class)->handle(F::staff(RoleName::Admin, 'Operator STH'), $report, ResultType::Juara3, F::allChecks()))->toHaveCount(1)
        ->and(app(VerifyReport::class)->handle($this->humas, F::pendingReport(F::student('Bima Satria Wicaksono', 'XI-4')), ResultType::Juara3, F::allChecks()))->toHaveCount(1);
});

it('cannot decide the same report twice', function (): void {
    $report = F::pendingReport();
    app(VerifyReport::class)->handle($this->kesiswaan, $report, ResultType::Juara3, F::allChecks());

    expect(fn () => app(VerifyReport::class)->handle($this->kesiswaan, $report->fresh(), ResultType::Juara3, F::allChecks()))
        ->toThrow(AuthorizationException::class)
        ->and(Achievement::query()->count())->toBe(1);
});

it('asks for a revision with a note: report perlu_revisi, registration back to menunggu_laporan', function (): void {
    $report = F::pendingReport();
    $leaderUser = $report->registration->leader->user;

    expect(fn () => app(RequestReportRevision::class)->handle($this->kesiswaan, $report, ''))->toThrow(ValidationException::class);

    app(RequestReportRevision::class)->handle($this->kesiswaan, $report, 'Upload sertifikat yang terbaca.');

    $report->refresh();
    expect($report->status)->toBe(ReportStatus::PerluRevisi)
        ->and($report->note)->toBe('Upload sertifikat yang terbaca.')
        ->and($report->registration->status)->toBe(RegistrationStatus::MenungguLaporan)
        ->and(Achievement::query()->count())->toBe(0);

    Notification::assertSentTo($leaderUser, ReportRevisionRequested::class);
});

it('rejects with a reason: no points, registration ends', function (): void {
    $report = F::pendingReport();
    $leaderUser = $report->registration->leader->user;

    expect(fn () => app(RejectReport::class)->handle($this->kesiswaan, $report, ' '))->toThrow(ValidationException::class);

    app(RejectReport::class)->handle($this->kesiswaan, $report, 'Sertifikat bukan milik peserta.');

    $report->refresh();
    expect($report->status)->toBe(ReportStatus::Ditolak)
        ->and($report->registration->status)->toBe(RegistrationStatus::Selesai)
        ->and(Achievement::query()->count())->toBe(0)
        ->and(Publication::query()->count())->toBe(0);

    Notification::assertSentTo($leaderUser, ReportRejected::class);
    Notification::assertNotSentTo($this->humas, AchievementReadyForPublication::class);
});

it('flushes the public page cache after a verification (W3-A)', function (): void {
    $report = F::pendingReport(F::student('Nadia Putri Anindya', 'XI-4'), [], ResultType::Juara1);
    $version = (int) Cache::get(PublicAchievements::VERSION_KEY, 1);

    app(VerifyReport::class)->handle($this->kesiswaan, $report, ResultType::Juara1, F::allChecks());

    expect((int) Cache::get(PublicAchievements::VERSION_KEY, 1))->toBe($version + 1);
});

// QA5 (lead, 2026-10-07): the guru pembimbing hears every decision on a binaan report -----------------------

it('tells the guru pembimbing about every decision on a binaan report', function (string $decision, string $title, string $body): void {
    $guru = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $report = F::pendingReport(F::student('Nadia Putri Anindya', 'XI-4'), [], ResultType::Juara2, $guru);

    match ($decision) {
        'verified' => app(VerifyReport::class)->handle($this->kesiswaan, $report, ResultType::Juara2, F::allChecks()),
        'revision' => app(RequestReportRevision::class)->handle($this->kesiswaan, $report, 'Sertifikat belum terbaca.'),
        'rejected' => app(RejectReport::class)->handle($this->kesiswaan, $report, 'Bukan lomba yang didaftarkan.'),
    };

    Notification::assertSentToTimes($guru, BinaanReportDecided::class, 1);
    Notification::assertSentTo($guru, BinaanReportDecided::class, function (BinaanReportDecided $n) use ($guru, $title, $body, $report): bool {
        $mail = $n->toMail($guru);

        return str_starts_with($n->title, $title)
            && str_contains($n->body, $body)
            && $n->toArray($guru)['url'] === route('guru.binaan.show', $report->registration_id)
            && html_entity_decode((string) $mail->greeting, ENT_QUOTES | ENT_HTML5) === 'Yth. Retno Wulandari, S.Pd.,';
    });
})->with([
    'verified' => ['verified', 'Laporan binaan diverifikasi: ', 'Nadia Putri Anindya meraih Juara II.'],
    'revision' => ['revision', 'Laporan binaan perlu revisi: ', 'Catatan Kesiswaan: Sertifikat belum terbaca.'],
    'rejected' => ['rejected', 'Laporan binaan ditolak: ', 'Alasan: Bukan lomba yang didaftarkan.'],
]);

it('tells no guru when the report has no pembimbing or the guru cannot log in', function (): void {
    app(VerifyReport::class)->handle($this->kesiswaan, F::pendingReport(), ResultType::Juara3, F::allChecks());

    $frozen = F::staff(RoleName::Guru, 'Hendra Kusuma, S.Pd., M.Si.');
    $frozen->forceFill(['status' => 'disabled'])->save();
    app(RequestReportRevision::class)->handle($this->kesiswaan, F::pendingReport(mentor: $frozen), 'Sertifikat belum terbaca.');

    Notification::assertNothingSentTo($frozen);
    Notification::assertSentTimes(BinaanReportDecided::class, 0);
});

it('names the role that decided in the notices to the team and the guru (D-139)', function (RoleName $role, string $label): void {
    $decider = F::staff($role, 'Petugas '.$role->label());
    $guru = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $nadia = F::student('Nadia Putri Anindya', 'XI-4');
    $raka = F::student('Raka Pradipta', 'XII-2');

    app(VerifyReport::class)->handle($decider, F::pendingReport($nadia, [], ResultType::Juara2, $guru), ResultType::Juara2, F::allChecks());
    app(RequestReportRevision::class)->handle($decider, F::pendingReport($raka, mentor: $guru), 'Sertifikat belum terbaca.');

    Notification::assertSentTo($nadia->user, ReportVerified::class, fn (ReportVerified $n): bool => str_contains($n->body, 'sudah diverifikasi '.$label.'. Kamu mendapat'));
    Notification::assertSentTo($raka->user, ReportRevisionRequested::class, fn (ReportRevisionRequested $n): bool => $n->body === 'Catatan '.$label.': Sertifikat belum terbaca.');
    Notification::assertSentTo($guru, BinaanReportDecided::class, fn (BinaanReportDecided $n): bool => $n->kind === 'report.binaan_revision'
        && str_contains($n->body, 'Catatan '.$label.': Sertifikat belum terbaca.'));
})->with([
    'kesiswaan' => [RoleName::Kesiswaan, 'Kesiswaan'],
    'humas' => [RoleName::Humas, 'Humas'],
    'admin' => [RoleName::Admin, 'Admin'],
]);
