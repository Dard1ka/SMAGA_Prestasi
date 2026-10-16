<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Kesiswaan\Talenta;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Attributes\Title;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    $this->withoutVite();
});

it('titles the page and the menu item "Database talenta siswa" with the new subtitle (D-119)', function (): void {
    $title = (new ReflectionClass(Talenta::class))->getAttributes(Title::class)[0]->getArguments()[0];
    $label = collect(Navigation::for(['name' => 'X', 'roles' => ['kesiswaan']], 'kesiswaan', [], 'none')['sections'])
        ->flatMap(fn (array $s) => $s['items'])->keyBy('key')['talenta']['label'];

    expect($title)->toBe('Database talenta siswa')->and($label)->toBe($title);

    $this->actingAs(F::staff(RoleName::Kesiswaan))->get(route('kesiswaan.talenta.index'))->assertOk()
        ->assertSee('Database talenta siswa')
        ->assertSee('Data talenta pilihan siswa.')
        ->assertDontSee('Penjaringan, pemetaan, dan seleksi peserta pembinaan.');
});

it('keeps the talent page forbidden for the other staff roles', function (RoleName $role): void {
    $this->actingAs(F::staff($role, 'Staf '.$role->value))->get(route('kesiswaan.talenta.index'))->assertForbidden();
})->with([RoleName::Guru, RoleName::Humas, RoleName::TataUsaha, RoleName::KepalaSekolah]);
