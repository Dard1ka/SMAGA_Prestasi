<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\UserStatus;
use App\Models\TalentApplication;
use App\Models\TalentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();

    $this->nadia = F::student();
    $this->application = TalentApplication::factory()->create(['student_id' => $this->nadia->getKey()]);
});

it('TalentApplicationPolicy: students apply and cancel their own terdaftar choices', function (): void {
    $dimas = F::student('Dimas Arya Nugroho');

    expect($this->nadia->user->can('create', TalentApplication::class))->toBeTrue()
        ->and($this->nadia->user->can('viewAny', TalentApplication::class))->toBeTrue()
        ->and($this->nadia->user->can('view', $this->application))->toBeTrue()
        ->and($this->nadia->user->can('cancel', $this->application))->toBeTrue()
        ->and($dimas->user->can('view', $this->application))->toBeFalse()
        ->and($dimas->user->can('cancel', $this->application))->toBeFalse();

    $this->application->forceFill(['status' => TalentStatus::Seleksi])->save();
    expect($this->nadia->user->can('cancel', $this->application->fresh()))->toBeFalse();

    $this->nadia->user->forceFill(['status' => UserStatus::Disabled])->save();
    expect($this->nadia->user->fresh()->can('create', TalentApplication::class))->toBeFalse();
});

it('TalentApplicationPolicy: staff never apply; Kesiswaan and pembina may view', function (): void {
    $pembina = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $this->application->forceFill(['mentor_id' => $pembina->getKey()])->save();

    foreach ([RoleName::Kesiswaan, RoleName::Admin, RoleName::Guru, RoleName::Humas] as $role) {
        expect(F::staff($role, 'Staf '.$role->value)->can('create', TalentApplication::class))->toBeFalse();
    }

    expect(F::staff(RoleName::Kesiswaan)->can('view', $this->application))->toBeTrue()
        ->and($pembina->can('view', $this->application->fresh()))->toBeTrue()
        ->and(F::staff(RoleName::Guru, 'Guru lain')->can('view', $this->application))->toBeFalse()
        ->and(F::staff(RoleName::Humas)->can('view', $this->application))->toBeFalse();
});

it('TalentProfilePolicy: own profile only; Kesiswaan and guru pembina may read it', function (): void {
    $profile = TalentProfile::factory()->create(['student_id' => $this->nadia->getKey()]);
    $dimas = F::student('Dimas Arya Nugroho');
    $kesiswaan = F::staff(RoleName::Kesiswaan);

    expect($this->nadia->user->can('save', [TalentProfile::class, $profile]))->toBeTrue()
        ->and($this->nadia->user->can('view', $profile))->toBeTrue()
        ->and($dimas->user->can('save', [TalentProfile::class, $profile]))->toBeFalse()
        ->and($dimas->user->can('save', [TalentProfile::class, null]))->toBeTrue()
        ->and($dimas->user->can('view', $profile))->toBeFalse()
        ->and($kesiswaan->can('view', $profile))->toBeTrue()
        ->and($kesiswaan->can('save', [TalentProfile::class, null]))->toBeFalse()
        ->and(F::staff(RoleName::TataUsaha)->can('view', $profile))->toBeFalse();
});
