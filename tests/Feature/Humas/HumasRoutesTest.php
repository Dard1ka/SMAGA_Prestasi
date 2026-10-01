<?php

declare(strict_types=1);

use App\Enums\PublicationStatus;
use App\Enums\RoleName;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
| Humas area routes (UI-MAP §4d): area.staff + role:humas, each screen authorizes through its policy.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    config(['fortify.sth.require_staff_2fa' => false]);
    $this->withoutVite();
});

dataset('humas routes', [
    'publikasi' => ['humas.beranda'],
    'jadwal' => ['humas.jadwal'],
    'apresiasi' => ['humas.apresiasi.index'],
    'arsip' => ['humas.arsip'],
]);

it('redirects guests to the login page', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('login'));
})->with('humas routes');

it('opens every Humas screen for the humas role', function (string $route): void {
    HumasFixtures::publication();

    $this->actingAs(HumasFixtures::humas())
        ->get(route($route))
        ->assertOk()
        ->assertSee('Antrean publikasi');
})->with('humas routes');

it('forbids other staff roles, including admin (route is role:humas)', function (string $route, RoleName $role): void {
    $this->actingAs(AuthTestHelpers::staff($role))
        ->get(route($route))
        ->assertForbidden();
})->with('humas routes')->with([
    'guru' => [RoleName::Guru],
    'tata_usaha' => [RoleName::TataUsaha],
    'kepala_sekolah' => [RoleName::KepalaSekolah],
    'kesiswaan' => [RoleName::Kesiswaan],
    'admin' => [RoleName::Admin],
]);

it('forbids students', function (string $route): void {
    $student = Student::factory()->activated()->create();

    $this->actingAs($student->user()->first()->fresh())
        ->get(route($route))
        ->assertForbidden();
})->with('humas routes');

it('requires 2FA for Humas when the setting is on', function (): void {
    config(['fortify.sth.require_staff_2fa' => true]);

    $this->actingAs(HumasFixtures::humas())
        ->get(route('humas.beranda'))
        ->assertRedirect(route('two-factor.setup'));
});

it('shows the queue lanes with the demo-like data on the landing page', function (): void {
    HumasFixtures::publication();
    HumasFixtures::publication(publicationState: ['status' => PublicationStatus::Terjadwal, 'scheduled_at' => now()->addDay(), 'channels' => ['instagram']], studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');

    $this->actingAs(HumasFixtures::humas())
        ->get(route('humas.beranda'))
        ->assertOk()
        ->assertSee('Siap publikasi')
        ->assertSee('Terjadwal')
        ->assertSee('Dipublikasikan')
        ->assertSee('OPSI 2026')
        ->assertSee('FIKSI 2026')
        ->assertSee('Siapkan publikasi');
});
