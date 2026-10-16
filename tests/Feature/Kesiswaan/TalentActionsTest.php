<?php

declare(strict_types=1);

use App\Actions\Talent\UpdateTalentAssessment;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Models\TalentApplication;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| FS1-E (D-142): Kesiswaan only records the guru pembina (optional) and the note for him through
| UpdateTalentAssessment. No stages, no assessment date, no note for the student, nobody is notified.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
});

it('saves the guru pembina and the note for him, audited, without telling anyone', function (): void {
    $application = F::application(status: TalentStatus::Seleksi);

    app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application, [
        'mentor_id' => (string) $this->guru->getKey(),
        'note' => '  Nilai sains SMP kuat.  ',
    ]);

    expect($application->fresh()->mentor_id)->toBe($this->guru->getKey())
        ->and($application->fresh()->note)->toBe('Nilai sains SMP kuat.')
        ->and($application->fresh()->status)->toBe(TalentStatus::Seleksi);

    $log = Activity::query()->where('event', 'talent.assessment_updated')->sole();
    expect($log->properties->get('mentor_id'))->toBe($this->guru->getKey())
        ->and((int) $log->causer_id)->toBe((int) $this->kesiswaan->getKey());

    // Nothing changed: no second audit row. Without a guru pembina: null.
    app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application->fresh(), ['mentor_id' => (string) $this->guru->getKey(), 'note' => 'Nilai sains SMP kuat.']);
    expect(Activity::query()->where('event', 'talent.assessment_updated')->count())->toBe(1);

    app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application->fresh(), ['mentor_id' => '', 'note' => '']);
    expect($application->fresh()->mentor_id)->toBeNull()
        ->and($application->fresh()->note)->toBeNull();

    Notification::assertNothingSent();
});

it('ignores an assessment date and a note for the student', function (): void {
    $application = F::application();

    app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application, [
        'note' => 'Hubungi wali kelas dulu.',
        'assessment_on' => today()->addDays(3)->format('Y-m-d'),
        'student_note' => 'Tidak boleh tersimpan.',
        'student_note_draft' => 'Tidak boleh tersimpan juga.',
    ]);

    expect($application->fresh()->note)->toBe('Hubungi wali kelas dulu.')
        ->and($application->fresh()->assessment_on)->toBeNull()
        ->and($application->fresh()->student_note)->toBeNull()
        ->and($application->fresh()->student_note_draft)->toBeNull()
        ->and(app(UpdateTalentAssessment::class)->validated(['assessment_on' => '2020-01-01', 'student_note' => 'x', 'note' => 'y']))->toBe(['note' => 'y']);
    Notification::assertNothingSent();
});

it('limits the note to 1.000 characters', function (): void {
    $application = F::application();

    expect(fn () => app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application, ['note' => str_repeat('a', 1001)]))
        ->toThrow(ValidationException::class, 'Catatan maksimal 1.000 karakter.');

    expect($application->fresh()->note)->toBeNull();
});

it('accepts only guru accounts as pembina', function (): void {
    $application = F::application();

    foreach ([$this->kesiswaan, F::staff(RoleName::Humas, 'Mas Galih'), $application->student->user] as $notGuru) {
        expect(fn () => app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application, ['mentor_id' => $notGuru->getKey()]))
            ->toThrow(ValidationException::class, 'Pilih guru pembina dari daftar.');
    }

    expect(UpdateTalentAssessment::isActiveGuru($this->guru->getKey()))->toBeTrue()
        ->and(UpdateTalentAssessment::isActiveGuru($this->kesiswaan->getKey()))->toBeFalse()
        ->and(UpdateTalentAssessment::isActiveGuru(null))->toBeFalse()
        ->and(UpdateTalentAssessment::isActiveGuru(''))->toBeFalse();

    app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application, ['mentor_id' => $this->guru->getKey()]);

    expect($application->fresh()->mentor_id)->toBe($this->guru->getKey())
        ->and($application->fresh()->status)->toBe(TalentStatus::Terdaftar);
    Notification::assertNothingSent();
});

it('allows Kesiswaan and Admin only', function (): void {
    $application = F::application();
    $rejected = TalentApplication::factory()->rejected()->create([
        'student_id' => F::student('Bima Satria Wicaksono', 'XII-7')->getKey(),
        'talent_type_id' => F::talentType()->getKey(),
        'mentor_id' => $this->guru->getKey(),
    ]);

    foreach ([$this->guru, F::staff(RoleName::Humas, 'Mas Galih'), F::staff(RoleName::KepalaSekolah, 'Kepala'), F::staff(RoleName::TataUsaha, 'Staf TU'), $application->student->user, $rejected->student->user] as $user) {
        expect(fn () => app(UpdateTalentAssessment::class)->handle($user, $application, ['note' => 'x']))->toThrow(AuthorizationException::class)
            ->and(fn () => app(UpdateTalentAssessment::class)->handle($user, $rejected, ['mentor_id' => '']))->toThrow(AuthorizationException::class)
            ->and($user->can('update', $rejected))->toBeFalse();
    }

    expect($rejected->fresh()->mentor_id)->toBe($this->guru->getKey());

    $admin = F::staff(RoleName::Admin, 'Operator STH');
    app(UpdateTalentAssessment::class)->handle($admin, $rejected, ['mentor_id' => '', 'note' => 'Dicatat Admin.']);

    expect($rejected->fresh()->mentor_id)->toBeNull()
        ->and($rejected->fresh()->note)->toBe('Dicatat Admin.')
        ->and($rejected->fresh()->status)->toBe(TalentStatus::TidakLolos);
});

it('refuses every change on a choice the student deleted', function (): void {
    $deleted = F::application(status: TalentStatus::Pembinaan);
    $deleted->delete();

    expect(fn () => app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $deleted, ['note' => 'x']))->toThrow(AuthorizationException::class)
        ->and($this->kesiswaan->can('update', $deleted))->toBeFalse();
});

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
        ->and($unknown->fresh()->student_note)->toBe('Coba lagi tahun depan.')
        ->and($unknown->fresh()->rejected_from)->toBeNull()
        ->and($open->fresh()->note)->toBe('Catatan pembina lama.')
        ->and($open->fresh()->student_note)->toBeNull();
});
