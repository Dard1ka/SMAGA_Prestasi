<?php

declare(strict_types=1);

use App\Actions\Competitions\CreateCompetition;
use App\Enums\RoleName;
use App\Notifications\Competitions\CompetitionSubmitted;
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

it('tells the Admin when Kesiswaan adds a lomba, never the actor', function (): void {
    app(CreateCompetition::class)->handle($this->kesiswaan, F::form());

    Notification::assertSentTo($this->admin, CompetitionSubmitted::class);
    Notification::assertNotSentTo($this->kesiswaan, CompetitionSubmitted::class);
});

it('does not tell roles that cannot verify a lomba', function (): void {
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $humas = F::staff(RoleName::Humas, 'Mas Galih');

    app(CreateCompetition::class)->handle($this->admin, F::form());

    Notification::assertNotSentTo([$guru, $humas], CompetitionSubmitted::class);
});
