<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Kesiswaan\DatabasePrestasi;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Attributes\Title;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    $this->withoutVite();
    $this->admin = F::staff(RoleName::Admin, 'Operator STH');
});

it('words the Admin top bar search as name or NISN (D-137)', function (): void {
    $nav = Navigation::for(['name' => 'X', 'roles' => ['admin']], null, [], 'admin.beranda');

    expect($nav['search']['placeholder'])->toBe('Cari nama atau NISN');
});

it('gives Admin a "Database prestasi" item in Data that names the page it opens (D-119)', function (): void {
    $nav = Navigation::for(['name' => 'X', 'roles' => ['admin']], null, [], 'kesiswaan.prestasi.index');
    $data = collect($nav['sections'])->firstWhere('label', 'Data')['items'];
    $title = (new ReflectionClass(DatabasePrestasi::class))->getAttributes(Title::class)[0]->getArguments()[0];

    expect($nav['role'])->toBe('admin')
        ->and($data[0]['key'])->toBe('prestasi')
        ->and($data[0]['label'])->toBe($title)
        ->and($data[0]['href'])->toBe(route('kesiswaan.prestasi.index'))
        ->and($data[0]['current'])->toBeTrue()
        ->and(collect($nav['sections'])->flatMap(fn (array $s) => $s['items'])->where('current', true))->toHaveCount(1);
});

it('opens Database prestasi for Admin inside the Admin menu', function (): void {
    $this->actingAs($this->admin)->get(route('kesiswaan.prestasi.index'))->assertOk()
        ->assertSee('Database prestasi')
        ->assertSee('Pengguna &amp; peran', false)
        ->assertDontSee('Verifikasi usulan lomba');
});

it('still keeps the page from roles without it', function (RoleName $role): void {
    $this->actingAs(F::staff($role, 'Staf '.$role->value))->get(route('kesiswaan.prestasi.index'))->assertForbidden();
})->with([RoleName::Humas, RoleName::Guru, RoleName::TataUsaha, RoleName::KepalaSekolah]);
