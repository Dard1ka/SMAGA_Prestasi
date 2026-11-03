<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Auth\AuthTestHelpers as H;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
});

// Item 12: staff are never addressed with "kamu" or "-mu" (D-119), also on the home of a role-less account.
it('tells a staff account without a role about it without addressing it as kamu (QA9)', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->actingAs($user->fresh())->get(route('home'))
        ->assertOk()
        ->assertSee('Akun ini belum punya peran. Peran dari Admin berlaku setelah disetujui. Coba masuk lagi nanti.')
        ->assertDontSee('Akunmu');
});
