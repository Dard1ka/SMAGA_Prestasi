<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Kesiswaan\Lomba\Index;
use App\Models\Competition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    $this->withoutVite();
    $this->kesiswaan = F::staff(RoleName::Kesiswaan);

    Competition::factory()->published()->create(['name' => 'Lomba Terbuka', 'registration_closes_at' => now()->addDays(20)]);
    Competition::factory()->create(['name' => 'Usulan Baru', 'created_at' => now()->subDay(), 'registration_closes_at' => now()->addDays(10)]);
    Competition::factory()->create(['name' => 'Usulan Lama', 'created_at' => now()->subDays(5), 'registration_closes_at' => now()->addDays(30)]);
    Competition::factory()->published()->create(['name' => 'Lomba Selesai', 'registration_closes_at' => now()->subDay()]);
});

it('lists pending lomba in the catalog oldest first, between open and closed lomba (FS1 §2.7)', function (string $role): void {
    $user = $role === 'admin' ? F::staff(RoleName::Admin, 'Operator STH') : $this->kesiswaan;

    Livewire::actingAs($user)->test(Index::class)->call('setTab', 'katalog')
        ->assertSeeInOrder(['Lomba Terbuka', 'Usulan Lama', 'Usulan Baru', 'Lomba Selesai']);
})->with(['kesiswaan', 'admin']);

// UX2 (the lead, 2026-10-20): the verification queue lists the newest usulan first (the catalog keeps D-135).
it('lists the verification queue newest first', function (): void {
    Livewire::withQueryParams(['tab' => 'verifikasi'])->actingAs($this->kesiswaan)->test(Index::class)
        ->assertSeeInOrder(['Usulan Baru', 'Usulan Lama']);
});
