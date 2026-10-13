<?php

declare(strict_types=1);

use App\Actions\Competitions\CreateCompetition;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Livewire\Kesiswaan\Lomba\Form;
use App\Livewire\Kesiswaan\Lomba\Index;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

uses(RefreshDatabase::class);

/*
| FS1 Paket B (D-145): only siswa (usulan) and Admin add a lomba. Kesiswaan checks, corrects and decides; guru,
| Kepala Sekolah, Tata Usaha and Humas no longer upload lomba info.
*/

beforeEach(function (): void {
    F::boot();
    // The page tests render the layout; no built assets are needed for that.
    $this->withoutVite();
});

it('gives competition.create to siswa and admin only (D-145)', function (RoleName $role, bool $may): void {
    expect(in_array(Permission::CompetitionCreate, $role->defaultPermissions(), true))->toBe($may);
})->with([
    [RoleName::Siswa, true], [RoleName::Admin, true], [RoleName::Guru, false], [RoleName::Kesiswaan, false],
    [RoleName::KepalaSekolah, false], [RoleName::TataUsaha, false], [RoleName::Humas, false],
]);

it('refuses CreateCompetition to every staff role but Admin', function (RoleName $role): void {
    expect(fn () => app(CreateCompetition::class)->handle(F::staff($role), F::form(), F::pdf()))
        ->toThrow(AuthorizationException::class);
})->with([RoleName::Guru, RoleName::Kesiswaan, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas]);

it('shows "Tambah lomba" to Admin only and opens the create drawer only for Admin', function (): void {
    $kesiswaan = F::staff(RoleName::Kesiswaan);
    $admin = F::staff(RoleName::Admin, 'Operator STH');

    // The button, not the closed drawer's default title, which the page also holds.
    Livewire::actingAs($kesiswaan)->test(Index::class)
        ->assertDontSeeHtml('wire:click="create"')
        ->assertSee('Ubah filter.');
    Livewire::actingAs($kesiswaan)->test(Index::class)->call('create')->assertForbidden();
    Livewire::actingAs($kesiswaan)->test(Form::class)->call('open')->assertForbidden();
    Livewire::actingAs($admin)->test(Index::class)
        ->assertSeeHtml('wire:click="create"')
        ->assertSee('Tambah lomba')
        ->assertSee('Ubah filter atau tambah lomba baru.');
    Livewire::actingAs($admin)->test(Form::class)->call('open')->assertOk()->assertSet('formMode', 'create');
});

it('removes "Upload info lomba" and "Upload saya" from Guru › Lomba', function (): void {
    $guru = F::staff(RoleName::Guru);

    $this->actingAs($guru)->get(route('guru.lomba.index'))
        ->assertOk()->assertDontSee('Upload info lomba')->assertDontSee('Upload saya');
    $this->actingAs($guru)->get(route('guru.lomba.index', ['tab' => 'unggahan']))->assertOk()->assertDontSee('Upload saya');
});
