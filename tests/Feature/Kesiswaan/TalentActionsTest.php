<?php

declare(strict_types=1);

use App\Actions\Talent\MoveTalentStage;
use App\Actions\Talent\RejectTalentApplication;
use App\Actions\Talent\RestoreTalentApplication;
use App\Actions\Talent\UpdateTalentAssessment;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Models\TalentApplication;
use App\Notifications\Support\PlainMailMessage;
use App\Notifications\Talent\TalentApplicationRejected;
use App\Notifications\Talent\TalentApplicationRestored;
use App\Notifications\Talent\TalentReviewRequested;
use App\Notifications\Talent\TalentStageChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
});

it('moves one stage forward, saves pembina + assessment date and notifies the student', function (): void {
    $application = F::application();
    $studentUser = $application->student->user;

    app(MoveTalentStage::class)->handle($this->kesiswaan, $application, TalentStatus::Pemetaan, [
        'mentor_id' => (string) $this->guru->getKey(),
        'assessment_on' => today()->addDays(7)->format('Y-m-d'),
        'note' => 'Nilai sains SMP kuat.',
    ]);

    $application->refresh();
    expect($application->status)->toBe(TalentStatus::Pemetaan)
        ->and($application->mentor_id)->toBe($this->guru->getKey())
        ->and($application->assessment_on->format('Y-m-d'))->toBe(today()->addDays(7)->format('Y-m-d'))
        ->and($application->note)->toBe('Nilai sains SMP kuat.');

    Notification::assertSentTo($studentUser, TalentStageChanged::class, fn (TalentStageChanged $n): bool => str_contains($n->body, 'Pemetaan'));
    expect(Activity::query()->where('event', 'talent.stage_changed')->exists())->toBeTrue();
});

// Two notes, two readers (lead, 2026-10-04) ------------------------------------------------------

it('sends only the note for the student with a move and keeps the note for the guru pembina internal', function (): void {
    $application = F::application();

    app(MoveTalentStage::class)->handle($this->kesiswaan, $application, TalentStatus::Pemetaan, [
        'note' => 'Perlu pendampingan praktikum.',
        'student_note' => "Selamat, siapkan rapor semester 1.\nBawa saat pemetaan.",
    ]);

    expect($application->fresh()->note)->toBe('Perlu pendampingan praktikum.')
        ->and($application->fresh()->student_note)->toBe("Selamat, siapkan rapor semester 1.\nBawa saat pemetaan.");

    Notification::assertSentTo($application->student->user, TalentStageChanged::class, fn (TalentStageChanged $n): bool => str_contains($n->body, 'Catatan dari Kesiswaan: Selamat, siapkan rapor semester 1. Bawa saat pemetaan.')
        && ! str_contains($n->body, 'pendampingan praktikum'));
});

it('treats both notes as optional and drops the note of the earlier stage on the next move', function (): void {
    $application = F::application();
    $move = app(MoveTalentStage::class);

    $move->handle($this->kesiswaan, $application, TalentStatus::Pemetaan, ['student_note' => 'Lolos pemetaan awal.']);
    $move->handle($this->kesiswaan, $application->fresh(), TalentStatus::Seleksi, ['note' => '   ', 'student_note' => '']);

    expect($application->fresh()->status)->toBe(TalentStatus::Seleksi)
        ->and($application->fresh()->student_note)->toBeNull()
        ->and($application->fresh()->note)->toBeNull();

    $move->handle($this->kesiswaan, $application->fresh(), TalentStatus::Pembinaan);

    Notification::assertSentTo($application->student->user, TalentStageChanged::class, fn (TalentStageChanged $n): bool => str_contains($n->body, 'peserta pembinaan')
        && ! str_contains($n->body, 'Catatan dari Kesiswaan'));
});

it('limits each note to 1.000 characters', function (): void {
    $application = F::application();
    $long = str_repeat('a', 1001);

    expect(fn () => app(MoveTalentStage::class)->handle($this->kesiswaan, $application, TalentStatus::Pemetaan, ['student_note' => $long]))->toThrow(ValidationException::class)
        ->and(fn () => app(MoveTalentStage::class)->handle($this->kesiswaan, $application, TalentStatus::Pemetaan, ['note' => $long]))->toThrow(ValidationException::class)
        ->and(fn () => app(RejectTalentApplication::class)->handle($this->kesiswaan, $application, $long))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application, ['note' => $long]))->toThrow(ValidationException::class);

    expect($application->fresh()->status)->toBe(TalentStatus::Terdaftar);
    Notification::assertNothingSent();
});

it('never saves a note for the student through "Simpan"', function (): void {
    $application = F::application();

    app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application, ['note' => 'Hubungi wali kelas dulu.', 'student_note' => 'Tidak boleh tersimpan.']);

    expect($application->fresh()->note)->toBe('Hubungi wali kelas dulu.')
        ->and($application->fresh()->student_note)->toBeNull();
    Notification::assertNothingSent();
});

it('refuses to skip stages or to move a final stage', function (): void {
    $application = F::application();

    expect(fn () => app(MoveTalentStage::class)->handle($this->kesiswaan, $application, TalentStatus::Seleksi))->toThrow(ValidationException::class)
        ->and(fn () => app(MoveTalentStage::class)->handle($this->kesiswaan, $application, TalentStatus::TidakLolos))->toThrow(ValidationException::class);

    $final = F::application(status: TalentStatus::Pembinaan, branch: 'Kimia');
    expect(fn () => app(MoveTalentStage::class)->handle($this->kesiswaan, $final, TalentStatus::Pembinaan))->toThrow(ValidationException::class);
});

it('accepts only guru accounts as pembina and a valid date', function (): void {
    $application = F::application();

    expect(fn () => app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application, ['mentor_id' => $this->kesiswaan->getKey()]))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application, ['assessment_on' => '2026-02-31']))->toThrow(ValidationException::class);

    app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application, ['mentor_id' => $this->guru->getKey(), 'assessment_on' => today()->addDays(2)->format('Y-m-d')]);

    expect($application->fresh()->mentor_id)->toBe($this->guru->getKey())
        ->and($application->fresh()->status)->toBe(TalentStatus::Terdaftar);
    Notification::assertNothingSent();
});

// Tidak lolos ------------------------------------------------------------------------------------

it('marks tidak lolos without a note, remembers the stage and notifies the student', function (): void {
    $application = F::application(status: TalentStatus::Seleksi);

    app(RejectTalentApplication::class)->handle($this->kesiswaan, $application, '   ');

    $fresh = $application->fresh();
    expect($fresh->status)->toBe(TalentStatus::TidakLolos)
        ->and($fresh->student_note)->toBeNull()
        ->and($fresh->rejected_from)->toBe(TalentStatus::Seleksi);

    Notification::assertSentTo($application->student->user, TalentApplicationRejected::class, fn (TalentApplicationRejected $n): bool => str_contains($n->body, 'belum lolos tahap Seleksi/asesmen')
        && str_contains($n->body, 'minta ditinjau ulang')
        && ! str_contains($n->body, 'Catatan dari Kesiswaan'));

    expect(fn () => app(RejectTalentApplication::class)->handle($this->kesiswaan, $fresh, 'Lagi'))->toThrow(ValidationException::class);
});

it('marks tidak lolos with a note for the student and saves the panel fields in the same step', function (): void {
    $application = F::application(status: TalentStatus::Pemetaan);

    app(RejectTalentApplication::class)->handle($this->kesiswaan, $application, 'Coba lagi tahun depan.', [
        'mentor_id' => (string) $this->guru->getKey(),
        'note' => 'Kuota cabang penuh, bukan soal nilai.',
    ]);

    $fresh = $application->fresh();
    expect($fresh->status)->toBe(TalentStatus::TidakLolos)
        ->and($fresh->student_note)->toBe('Coba lagi tahun depan.')
        ->and($fresh->note)->toBe('Kuota cabang penuh, bukan soal nilai.')
        ->and($fresh->mentor_id)->toBe($this->guru->getKey())
        ->and($fresh->rejected_from)->toBe(TalentStatus::Pemetaan);

    Notification::assertSentTo($application->student->user, TalentApplicationRejected::class, fn (TalentApplicationRejected $n): bool => str_contains($n->body, 'Catatan dari Kesiswaan: Coba lagi tahun depan.')
        && ! str_contains($n->body, 'Kuota cabang penuh'));

    $log = Activity::query()->where('event', 'talent.rejected')->sole();
    expect($log->properties['from'])->toBe('pemetaan')
        ->and((int) $log->causer_id)->toBe((int) $this->kesiswaan->getKey());
});

// Pulihkan ---------------------------------------------------------------------------------------

it('restores a rejected choice to the stage it had, keeps the pembina, closes the review request and tells the student', function (): void {
    $application = F::application(status: TalentStatus::Seleksi);
    $application->forceFill(['mentor_id' => $this->guru->getKey(), 'note' => 'Catatan internal.'])->save();

    app(RejectTalentApplication::class)->handle($this->kesiswaan, $application, 'Nilai asesmen kurang.');
    $application->fresh()->forceFill(['review_requested_at' => now(), 'review_message' => 'Saya sakit saat asesmen.'])->save();

    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application->fresh(), 'Asesmen susulan Jumat ini.');

    $fresh = $application->fresh();
    expect($fresh->status)->toBe(TalentStatus::Seleksi)
        ->and($fresh->student_note)->toBe('Asesmen susulan Jumat ini.')
        ->and($fresh->rejected_from)->toBeNull()
        ->and($fresh->review_requested_at)->toBeNull()
        ->and($fresh->review_message)->toBeNull()
        ->and($fresh->mentor_id)->toBe($this->guru->getKey())
        ->and($fresh->note)->toBe('Catatan internal.');

    Notification::assertSentTo($application->student->user, TalentApplicationRestored::class, fn (TalentApplicationRestored $n): bool => str_contains($n->body, 'Permintaan tinjau ulangmu diterima.')
        && str_contains($n->body, 'dibuka kembali, sekarang di tahap Seleksi/asesmen.')
        && str_contains($n->body, 'Catatan dari Kesiswaan: Asesmen susulan Jumat ini.')
        && ! str_contains($n->body, 'Catatan internal'));

    $log = Activity::query()->where('event', 'talent.restored')->sole();
    expect($log->properties['to'])->toBe('seleksi')
        ->and($log->properties['review_requested'])->toBeTrue()
        ->and((int) $log->causer_id)->toBe((int) $this->kesiswaan->getKey());

    // Back in the pipeline: the next stage is open again.
    app(MoveTalentStage::class)->handle($this->kesiswaan, $fresh, TalentStatus::Pembinaan);
    expect($application->fresh()->status)->toBe(TalentStatus::Pembinaan);
});

it('restores without a request and without a note, to Terdaftar when the earlier stage is unknown', function (): void {
    $application = TalentApplication::factory()->status(TalentStatus::TidakLolos)->create([
        'student_id' => F::student('Citra Ayu Lestari', 'XI-2')->getKey(),
        'talent_type_id' => F::talentType()->getKey(),
        'student_note' => 'Catatan lama.',
    ]);

    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application);

    expect($application->fresh()->status)->toBe(TalentStatus::Terdaftar)
        ->and($application->fresh()->student_note)->toBeNull();

    Notification::assertSentTo($application->student->user, TalentApplicationRestored::class, fn (TalentApplicationRestored $n): bool => str_contains($n->body, 'sekarang di tahap Terdaftar.')
        && ! str_contains($n->body, 'Permintaan tinjau ulangmu')
        && ! str_contains($n->body, 'Catatan dari Kesiswaan'));
    expect(Activity::query()->where('event', 'talent.restored')->sole()->properties['review_requested'])->toBeFalse();
});

it('restores only a choice that is tidak lolos', function (TalentStatus $status): void {
    $application = F::application(status: $status);

    expect(fn () => app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application))
        ->toThrow(ValidationException::class, 'Hanya pilihan yang tidak lolos yang bisa dipulihkan.');

    expect($application->fresh()->status)->toBe($status);
    Notification::assertNothingSent();
})->with([TalentStatus::Terdaftar, TalentStatus::Pemetaan, TalentStatus::Seleksi, TalentStatus::Pembinaan]);

it('does not notify a student who can no longer log in', function (): void {
    $application = TalentApplication::factory()->rejected(TalentStatus::Pemetaan)->create(['student_id' => F::student()->getKey(), 'talent_type_id' => F::talentType()->getKey()]);
    $application->student->user->forceFill(['status' => 'archived'])->save();

    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application);

    expect($application->fresh()->status)->toBe(TalentStatus::Pemetaan);
    Notification::assertNothingSent();
});

// Access -----------------------------------------------------------------------------------------

it('allows Kesiswaan and Admin only', function (): void {
    $application = F::application();
    $rejected = TalentApplication::factory()->rejected()->create([
        'student_id' => F::student('Bima Satria Wicaksono', 'XII-7')->getKey(),
        'talent_type_id' => F::talentType()->getKey(),
        'mentor_id' => $this->guru->getKey(),
    ]);

    foreach ([$this->guru, F::staff(RoleName::Humas, 'Mas Galih'), F::staff(RoleName::KepalaSekolah, 'Kepala'), F::staff(RoleName::TataUsaha, 'Staf TU'), $application->student->user, $rejected->student->user] as $user) {
        expect(fn () => app(MoveTalentStage::class)->handle($user, $application, TalentStatus::Pemetaan))->toThrow(AuthorizationException::class)
            ->and(fn () => app(RejectTalentApplication::class)->handle($user, $application, 'x'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(UpdateTalentAssessment::class)->handle($user, $application, ['note' => 'x']))->toThrow(AuthorizationException::class)
            ->and(fn () => app(RestoreTalentApplication::class)->handle($user, $rejected))->toThrow(AuthorizationException::class)
            ->and($user->can('restore', $rejected))->toBeFalse();
    }

    expect($rejected->fresh()->status)->toBe(TalentStatus::TidakLolos);

    $admin = F::staff(RoleName::Admin, 'Operator STH');
    app(MoveTalentStage::class)->handle($admin, $application, TalentStatus::Pemetaan);
    app(RestoreTalentApplication::class)->handle($admin, $rejected);

    expect($application->fresh()->status)->toBe(TalentStatus::Pemetaan)
        ->and($rejected->fresh()->status)->toBe(TalentStatus::Seleksi);
});

it('refuses every change on a choice the student withdrew', function (): void {
    $rejected = TalentApplication::factory()->rejected()->create(['student_id' => F::student()->getKey(), 'talent_type_id' => F::talentType()->getKey()]);
    $rejected->delete();

    expect(fn () => app(RestoreTalentApplication::class)->handle($this->kesiswaan, $rejected))->toThrow(AuthorizationException::class);
});

// Mail -------------------------------------------------------------------------------------------

it('keeps the notes plain text in every talent mail (D-69)', function (): void {
    $hostile = '[Masuk ulang](https://evil.example/login) ![x](https://evil.example/p.png) **tebal**';
    $application = F::application(status: TalentStatus::Seleksi);
    $student = $application->student->user;

    $notifications = [
        TalentStageChanged::for($application, TalentStatus::Pemetaan, 'OSN Biologi', $hostile),
        TalentApplicationRejected::for($application, TalentStatus::Seleksi, 'OSN Biologi', $hostile),
        TalentApplicationRestored::for($application, 'OSN Biologi', $hostile),
        TalentReviewRequested::for($application, 'Hana Safira', 'X-8', 'OSN Biologi', $hostile),
    ];

    foreach ($notifications as $notification) {
        $mail = $notification->toMail($student);
        $html = (string) $mail->render();

        expect($mail)->toBeInstanceOf(PlainMailMessage::class)
            ->and($html)->not->toContain('href="https://evil.example')
            ->and($html)->not->toContain('<img')
            ->and($html)->not->toContain('<strong>tebal</strong>')
            ->and(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5))->toContain($hostile);
    }

    expect($notifications[3]->url())->toBe(route('kesiswaan.talenta.index', ['tahap' => 'tidak_lolos']))
        ->and($notifications[3]->body)->toContain('Hana Safira (X-8) minta pilihan talenta OSN Biologi')
        ->and($notifications[2]->url())->toBe(route('siswa.talenta'));
});

// Existing data ----------------------------------------------------------------------------------

it('moves the note of rows rejected before the change to the student note and reads the stage from the audit log', function (): void {
    $rejected = F::application(F::student('Galang Putra Mahendra', 'X-TAHFIDZ'), TalentStatus::TidakLolos);
    $unknown = F::application(F::student('Aurelia Maharani', 'X-3'), TalentStatus::TidakLolos, 'Kimia');
    $open = F::application(F::student('Bima Satria Wicaksono', 'XII-7'), TalentStatus::Seleksi, 'Fisika');

    // The shape the old code left behind: one `note`, sent to the student on a rejection.
    DB::table('talent_applications')->where('id', $rejected->getKey())->update(['note' => 'Kuota cabang penuh.']);
    DB::table('talent_applications')->where('id', $unknown->getKey())->update(['note' => 'Coba lagi tahun depan.']);
    DB::table('talent_applications')->where('id', $open->getKey())->update(['note' => 'Catatan pembina lama.']);
    activity('talent')->performedOn($rejected)->causedBy($this->kesiswaan)->event('talent.rejected')
        ->withProperties(['from' => 'pemetaan', 'note' => 'Kuota cabang penuh.'])->log('talent.rejected');

    $migration = require database_path('migrations/2026_10_04_130000_m1d_add_notes_and_review_to_talent_applications.php');
    $migration->backfill();
    $migration->backfill(); // running it twice changes nothing

    expect($rejected->fresh()->student_note)->toBe('Kuota cabang penuh.')
        ->and($rejected->fresh()->note)->toBeNull()
        ->and($rejected->fresh()->rejected_from)->toBe(TalentStatus::Pemetaan)
        ->and($rejected->fresh()->restoreTarget())->toBe(TalentStatus::Pemetaan)
        ->and($unknown->fresh()->student_note)->toBe('Coba lagi tahun depan.')
        ->and($unknown->fresh()->rejected_from)->toBeNull()
        ->and($unknown->fresh()->restoreTarget())->toBe(TalentStatus::Terdaftar)
        ->and($open->fresh()->note)->toBe('Catatan pembina lama.')
        ->and($open->fresh()->student_note)->toBeNull();
});

// QA6: explore round 3 after PR 14 (2026-10-07) ----------------------------------------------------

it('refuses a new assessment date before today and keeps one that was saved earlier (QA6, #62 Q2-13)', function (): void {
    $application = F::application();
    $update = fn (array $details) => app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application->fresh(), $details);
    $yesterday = today()->subDay()->format('Y-m-d');

    foreach (['2020-01-15', $yesterday] as $past) {
        try {
            $update(['assessment_on' => $past]);
            $this->fail('A past assessment date was accepted: '.$past);
        } catch (ValidationException $e) {
            expect($e->errors()['assessment_on'][0])->toBe('Jadwal asesmen tidak boleh sebelum hari ini.');
        }
    }

    // The same rule for a stage move with details.
    expect(fn () => app(MoveTalentStage::class)->handle($this->kesiswaan, $application->fresh(), TalentStatus::Pemetaan, ['assessment_on' => $yesterday]))
        ->toThrow(ValidationException::class);

    $update(['assessment_on' => today()->format('Y-m-d')]);
    expect($application->fresh()->assessment_on->format('Y-m-d'))->toBe(today()->format('Y-m-d'));

    // The assessment took place: saving the panel later (another note) keeps its date.
    $application->forceFill(['assessment_on' => $yesterday])->save();
    $update(['assessment_on' => $yesterday, 'note' => 'Sudah asesmen.']);

    expect($application->fresh()->note)->toBe('Sudah asesmen.')
        ->and($application->fresh()->assessment_on->format('Y-m-d'))->toBe($yesterday);
});

it('keeps a saved past assessment date when a stage move or a rejection sends the panel fields again (QA6 review)', function (): void {
    $yesterday = today()->subDay()->format('Y-m-d');

    $moved = F::application();
    $moved->forceFill(['assessment_on' => $yesterday])->save();
    app(MoveTalentStage::class)->handle($this->kesiswaan, $moved->fresh(), TalentStatus::Pemetaan, ['assessment_on' => $yesterday]);
    expect($moved->fresh()->status)->toBe(TalentStatus::Pemetaan);

    $rejected = F::application(F::student('Rizky Maulana', 'XI-1'), TalentStatus::Seleksi, 'Kimia');
    $rejected->forceFill(['assessment_on' => $yesterday])->save();
    app(RejectTalentApplication::class)->handle($this->kesiswaan, $rejected->fresh(), null, ['assessment_on' => $yesterday]);
    expect($rejected->fresh()->status)->toBe(TalentStatus::TidakLolos);
});
