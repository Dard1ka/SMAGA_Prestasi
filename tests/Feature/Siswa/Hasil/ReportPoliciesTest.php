<?php

declare(strict_types=1);

use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Achievement;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();

    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho');
    $this->mentor = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $this->registration = F::registration($this->nadia, members: [$this->dimas], mentor: $this->mentor);
});

it('ReportPolicy::create: only the ketua, only in menunggu_laporan, only without a report or after perlu_revisi', function (): void {
    expect($this->nadia->user->can('create', [Report::class, $this->registration]))->toBeTrue()
        ->and($this->dimas->user->can('create', [Report::class, $this->registration]))->toBeFalse()
        ->and($this->mentor->can('create', [Report::class, $this->registration]))->toBeFalse()
        ->and(F::staff(RoleName::Kesiswaan)->can('create', [Report::class, $this->registration]))->toBeFalse()
        ->and(F::staff(RoleName::Admin)->can('create', [Report::class, $this->registration]))->toBeFalse();

    $report = Report::factory()->create(['registration_id' => $this->registration->getKey(), 'claimed_result' => ResultType::Finalis]);
    expect($this->nadia->user->can('create', [Report::class, $this->registration->fresh()]))->toBeFalse();

    $report->forceFill(['status' => ReportStatus::PerluRevisi])->save();
    expect($this->nadia->user->can('create', [Report::class, $this->registration->fresh()]))->toBeTrue();

    $this->registration->forceFill(['status' => RegistrationStatus::Berlangsung])->save();
    expect($this->nadia->user->can('create', [Report::class, $this->registration->fresh()]))->toBeFalse();

    $this->registration->forceFill(['status' => RegistrationStatus::MenungguLaporan])->save();
    $this->nadia->user->forceFill(['status' => UserStatus::Disabled])->save();
    expect($this->nadia->user->fresh()->can('create', [Report::class, $this->registration->fresh()]))->toBeFalse();
});

it('ReportPolicy::view: members who did not decline, the mentor and verifiers', function (): void {
    $report = Report::factory()->create(['registration_id' => $this->registration->getKey(), 'claimed_result' => ResultType::Finalis]);

    expect($this->nadia->user->can('view', $report))->toBeTrue()
        ->and($this->dimas->user->can('view', $report))->toBeTrue()
        ->and($this->mentor->can('view', $report))->toBeTrue()
        ->and(F::staff(RoleName::Kesiswaan)->can('view', $report))->toBeTrue()
        ->and(F::staff(RoleName::Guru, 'Guru lain')->can('view', $report))->toBeFalse()
        ->and(F::staff(RoleName::Humas)->can('view', $report))->toBeTrue()
        ->and(F::staff(RoleName::TataUsaha)->can('view', $report))->toBeFalse()
        ->and(F::student('Rizky Maulana', 'XI-A')->user->can('view', $report))->toBeFalse();

    $this->registration->members()->where('student_id', $this->dimas->getKey())->first()->forceFill(['declined_at' => now(), 'confirmed_at' => null])->save();
    expect($this->dimas->user->fresh()->can('view', $report))->toBeFalse();
});

it('AchievementPolicy: own achievements only; achievement.view_all for staff', function (): void {
    $mine = Achievement::factory()->create(['student_id' => $this->nadia->getKey()]);
    $theirs = Achievement::factory()->create(['student_id' => $this->dimas->getKey()]);

    expect($this->nadia->user->can('viewAny', Achievement::class))->toBeTrue()
        ->and($this->nadia->user->can('view', $mine))->toBeTrue()
        ->and($this->nadia->user->can('view', $theirs))->toBeFalse()
        ->and(F::staff(RoleName::Kesiswaan)->can('view', $theirs))->toBeTrue()
        ->and(F::staff(RoleName::KepalaSekolah)->can('view', $theirs))->toBeTrue()
        ->and(F::staff(RoleName::Guru)->can('view', $theirs))->toBeFalse()
        ->and(F::staff(RoleName::TataUsaha)->can('viewAny', Achievement::class))->toBeFalse();
});
