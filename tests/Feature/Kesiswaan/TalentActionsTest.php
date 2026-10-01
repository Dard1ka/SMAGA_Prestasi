<?php

declare(strict_types=1);

use App\Actions\Talent\MoveTalentStage;
use App\Actions\Talent\RejectTalentApplication;
use App\Actions\Talent\UpdateTalentAssessment;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Notifications\Talent\TalentApplicationRejected;
use App\Notifications\Talent\TalentStageChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        'assessment_on' => '2026-10-07',
        'note' => 'Nilai sains SMP kuat.',
    ]);

    $application->refresh();
    expect($application->status)->toBe(TalentStatus::Pemetaan)
        ->and($application->mentor_id)->toBe($this->guru->getKey())
        ->and($application->assessment_on->format('Y-m-d'))->toBe('2026-10-07')
        ->and($application->note)->toBe('Nilai sains SMP kuat.');

    Notification::assertSentTo($studentUser, TalentStageChanged::class, fn (TalentStageChanged $n): bool => str_contains($n->body, 'Pemetaan'));
    expect(Activity::query()->where('event', 'talent.stage_changed')->exists())->toBeTrue();
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

    app(UpdateTalentAssessment::class)->handle($this->kesiswaan, $application, ['mentor_id' => $this->guru->getKey(), 'assessment_on' => '2026-10-09']);

    expect($application->fresh()->mentor_id)->toBe($this->guru->getKey())
        ->and($application->fresh()->status)->toBe(TalentStatus::Terdaftar);
    Notification::assertNothingSent();
});

it('marks tidak lolos only with a note and notifies the student', function (): void {
    $application = F::application(status: TalentStatus::Seleksi);

    expect(fn () => app(RejectTalentApplication::class)->handle($this->kesiswaan, $application, '   '))->toThrow(ValidationException::class);

    app(RejectTalentApplication::class)->handle($this->kesiswaan, $application, 'Coba lagi tahun depan.');

    expect($application->fresh()->status)->toBe(TalentStatus::TidakLolos)
        ->and($application->fresh()->note)->toBe('Coba lagi tahun depan.');
    Notification::assertSentTo($application->student->user, TalentApplicationRejected::class);

    expect(fn () => app(RejectTalentApplication::class)->handle($this->kesiswaan, $application->fresh(), 'Lagi'))->toThrow(ValidationException::class);
});

it('allows Kesiswaan and Admin only', function (): void {
    $application = F::application();

    foreach ([$this->guru, F::staff(RoleName::Humas, 'Mas Galih'), F::staff(RoleName::KepalaSekolah, 'Kepala'), $application->student->user] as $user) {
        expect(fn () => app(MoveTalentStage::class)->handle($user, $application, TalentStatus::Pemetaan))->toThrow(AuthorizationException::class)
            ->and(fn () => app(RejectTalentApplication::class)->handle($user, $application, 'x'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(UpdateTalentAssessment::class)->handle($user, $application, ['note' => 'x']))->toThrow(AuthorizationException::class);
    }

    app(MoveTalentStage::class)->handle(F::staff(RoleName::Admin, 'Operator STH'), $application, TalentStatus::Pemetaan);
    expect($application->fresh()->status)->toBe(TalentStatus::Pemetaan);
});
