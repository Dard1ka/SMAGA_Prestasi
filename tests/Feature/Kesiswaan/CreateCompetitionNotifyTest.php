<?php

declare(strict_types=1);

use App\Actions\Competitions\CreateCompetition;
use App\Actions\Talent\MoveTalentStage;
use App\Enums\RoleName;
use App\Models\TalentApplication;
use App\Models\TalentBranch;
use App\Models\TalentType;
use App\Notifications\Competitions\CompetitionSubmitted;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->admin = F::staff(RoleName::Admin, 'Operator STH');
});

it('tells Kesiswaan when the Admin adds a lomba to the verification queue', function (): void {
    app(CreateCompetition::class)->handle($this->admin, F::form());

    Notification::assertSentTo($this->kesiswaan, CompetitionSubmitted::class);
    Notification::assertNotSentTo($this->admin, CompetitionSubmitted::class);
});

it('never lets Kesiswaan add a lomba (D-145)', function (): void {
    expect(fn () => app(CreateCompetition::class)->handle($this->kesiswaan, F::form(), F::pdf()))
        ->toThrow(AuthorizationException::class);

    Notification::assertNothingSent();
});

it('does not tell roles that cannot verify a lomba', function (): void {
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $humas = F::staff(RoleName::Humas, 'Mas Galih');

    app(CreateCompetition::class)->handle($this->admin, F::form());

    Notification::assertNotSentTo([$guru, $humas], CompetitionSubmitted::class);
});

it('does not repeat the talent type in the notification label', function (): void {
    $type = TalentType::factory()->create(['name' => 'Debat']);
    $branch = TalentBranch::factory()->create(['talent_type_id' => $type->getKey(), 'name' => 'Debat Bahasa Indonesia (LDBI)']);
    $other = TalentBranch::factory()->create(['talent_type_id' => $type->getKey(), 'name' => 'Biologi']);

    $debat = TalentApplication::factory()->create(['talent_type_id' => $type->getKey(), 'talent_branch_id' => $branch->getKey()]);
    $biologi = TalentApplication::factory()->create(['talent_type_id' => $type->getKey(), 'talent_branch_id' => $other->getKey()]);

    $typeLabel = $type->code?->label() ?? $type->name;

    expect(MoveTalentStage::talentLabel($debat))->not->toContain('Debat Debat')
        ->and(MoveTalentStage::talentLabel($biologi))->toBe(trim($typeLabel.' Biologi'));
});
