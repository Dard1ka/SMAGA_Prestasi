<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Enums\UserStatus;
use App\Http\Middleware\EnsureTalentChosen;
use App\Livewire\Siswa\ProfilTalenta;
use App\Models\Classroom;
use App\Models\TalentApplication;
use App\Models\TalentType;
use Database\Seeders\TalentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;
use Tests\Support\TalentGate;

/*
| FS1-E (D-144): a kelas X student with no talent choice is held on Profil talenta with the required alert until one is
| chosen. Livewire updates, Profil talenta, Bantuan, Akun › Keamanan, Notifikasi and logout stay open; the gate runs
| after activation and only for students. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    $this->seed(TalentSeeder::class);
    $this->withoutVite();

    $this->hana = F::student('Hana Safira', 'X-8');
});

/** A talent choice of $student as the Actions leave it. */
function gateChoice(object $student, TalentStatus $status = TalentStatus::Terdaftar): TalentApplication
{
    $osn = TalentType::query()->where('code', TalentTypeCode::Osn)->sole();
    $row = new TalentApplication;
    $row->forceFill([
        'student_id' => $student->getKey(),
        'talent_type_id' => $osn->getKey(),
        'talent_branch_id' => $osn->branches()->where('name', 'Kimia')->value('id'),
        'status' => $status,
    ])->save();

    return $row;
}

it('sends a kelas X student without a talent to Profil talenta with the required alert', function (): void {
    expect(EnsureTalentChosen::ALERT)->toBe('Pilih minimal satu talenta untuk melanjutkan. Belum punya? Pilih Lainnya lalu tulis minatmu.');

    foreach (['siswa.beranda', 'siswa.lomba.index', 'siswa.lomba-saya.index', 'siswa.portofolio', 'siswa.peringkat'] as $route) {
        $this->actingAs($this->hana->user)->get(route($route))->assertRedirect(route('siswa.talenta'));
    }

    $this->actingAs($this->hana->user)->getJson(route('siswa.lomba.index'))->assertForbidden();
    $this->actingAs($this->hana->user)->get(route('siswa.talenta'))->assertOk()->assertSee(EnsureTalentChosen::ALERT);
    $this->actingAs($this->hana->user)->get(route('home'))->assertRedirect(route('siswa.beranda'));
});

it('runs after activation and only for kelas X students without any talent', function (): void {
    $nadia = F::student('Nadia Putri Anindya', 'XI-4');
    $raka = F::student('Raka Pradipta', 'XII-2');

    foreach ([$nadia, $raka] as $student) {
        $this->actingAs($student->user)->get(route('siswa.beranda'))->assertOk();
        $this->actingAs($student->user)->get(route('siswa.talenta'))->assertOk()->assertDontSee(EnsureTalentChosen::ALERT);
        expect(EnsureTalentChosen::applies($student->user))->toBeFalse();
    }

    // An old "tidak lolos" row is a talent: not gated.
    $aurelia = F::student('Aurelia Maharani', 'X-3');
    gateChoice($aurelia, TalentStatus::TidakLolos);
    $this->actingAs($aurelia->user)->get(route('siswa.beranda'))->assertOk();

    // A deleted row is not: gated.
    $galang = F::student('Galang Putra Mahendra', 'X-1');
    gateChoice($galang)->delete();
    $this->actingAs($galang->user)->get(route('siswa.beranda'))->assertRedirect(route('siswa.talenta'));

    // Activation comes first: the gate never sends a student who still has to activate to the talent page.
    $pending = F::student('Citra Ayu Lestari', 'X-2');
    $pending->user->forceFill(['status' => UserStatus::PendingActivation, 'must_change_password' => true, 'password_changed_at' => null, 'email_verified_at' => null])->save();
    $this->actingAs($pending->user->fresh())->get(route('siswa.beranda'))->assertRedirect(route('aktivasi'));
    $this->actingAs($pending->user->fresh())->get(route('siswa.talenta'))->assertRedirect(route('aktivasi'));

    // Staff never reach the gate (role:siswa first) and are never held by it.
    $kesiswaan = F::staff(RoleName::Kesiswaan, 'Dwi Rahayu');
    $this->actingAs($kesiswaan)->get(route('siswa.beranda'))->assertForbidden();
    expect(EnsureTalentChosen::applies($kesiswaan))->toBeFalse();

    // The classroom's "active" switch does not matter: the level does.
    $room = Classroom::factory()->level(10)->create(['name' => 'X-9', 'active' => false]);
    $bima = F::student('Bima Satria Wicaksono', 'X-9');
    expect($bima->classroom_id)->toBe($room->getKey());
    $this->actingAs($bima->user)->get(route('siswa.beranda'))->assertRedirect(route('siswa.talenta'));

    expect(EnsureTalentChosen::applies($this->hana->user))->toBeTrue();
    $this->hana->user->forceFill(['status' => UserStatus::Disabled])->save();
    expect(EnsureTalentChosen::applies($this->hana->user->fresh()))->toBeFalse();
});

it('keeps the exempt routes and Livewire updates open', function (string $route): void {
    $this->actingAs($this->hana->user)->get(route($route))->assertOk();
})->with(['siswa.talenta', 'siswa.bantuan', 'akun.keamanan', 'notifikasi.index']);

it('lets the gated student pick a Lainnya talent through Livewire and opens the other pages', function (): void {
    expect(Livewire::getPersistentMiddleware())->not->toContain(EnsureTalentChosen::class);
    $lainnya = TalentType::query()->where('code', TalentTypeCode::Lainnya)->sole();

    $this->actingAs($this->hana->user)->getJson(route('notifikasi.jumlah'))->assertOk();

    Livewire::actingAs($this->hana->user)->test(ProfilTalenta::class)
        ->assertSee(EnsureTalentChosen::ALERT)
        ->call('pilihJenis', $lainnya->getKey())->set('lainnya', 'Kaligrafi')->call('daftar')
        ->assertHasNoErrors()->assertDontSee(EnsureTalentChosen::ALERT);

    $this->actingAs($this->hana->user)->get(route('siswa.beranda'))->assertOk();
    $this->actingAs($this->hana->user)->get(route('siswa.lomba.index'))->assertOk();

    // Deleting the only talent brings the gate back.
    $choice = TalentApplication::query()->where('student_id', $this->hana->getKey())->sole();
    Livewire::actingAs($this->hana->user)->test(ProfilTalenta::class)
        ->call('batalkan', $choice->getKey())
        ->assertSee(EnsureTalentChosen::ALERT);
    $this->actingAs($this->hana->user)->get(route('siswa.beranda'))->assertRedirect(route('siswa.talenta'));

    $response = $this->actingAs($this->hana->user)->post(route('logout'));
    $response->assertRedirect();
    expect($response->headers->get('Location'))->not->toBe(route('siswa.talenta'));
    $this->assertGuest();
});

it('never locks the student inside the tour', function (): void {
    $this->hana->user->forceFill(['tour_seen_at' => null])->save();

    $this->actingAs($this->hana->user)->followingRedirects()->get(route('siswa.tour'))
        ->assertOk()
        ->assertSee(EnsureTalentChosen::ALERT)
        ->assertDontSee('id="tur-menu"', false);

    TalentGate::pass($this->hana);

    $this->actingAs($this->hana->user)->get(route('siswa.beranda'))
        ->assertOk()
        ->assertSee('id="tur-menu"', false);
    $this->actingAs($this->hana->user)->get(route('siswa.talenta'))->assertOk()->assertDontSee(EnsureTalentChosen::ALERT);
});

it('leads back to the page the gate held once the first talent is chosen (FS1 fix wave)', function (): void {
    $lainnya = TalentType::query()->where('code', TalentTypeCode::Lainnya)->sole();
    $held = route('siswa.lomba.index', ['q' => 'olimpiade']);

    $this->actingAs($this->hana->user)->get($held)->assertRedirect(route('siswa.talenta'));
    $this->actingAs($this->hana->user)->getJson(route('siswa.portofolio'))->assertForbidden();
    expect(session(EnsureTalentChosen::INTENDED))->toBe('/siswa/lomba?q=olimpiade');

    Livewire::actingAs($this->hana->user)->test(ProfilTalenta::class)
        ->call('pilihJenis', $lainnya->getKey())->set('lainnya', 'Kaligrafi')->call('daftar')
        ->assertHasNoErrors()
        ->assertRedirect($held);

    expect(session(EnsureTalentChosen::INTENDED))->toBeNull()
        ->and(session('toast'))->toBe('Lainnya (Kaligrafi) ditambahkan.');

    // A second talent, with the gate already lifted, stays on Profil talenta.
    $osn = TalentType::query()->where('code', TalentTypeCode::Osn)->sole();
    Livewire::actingAs($this->hana->user)->test(ProfilTalenta::class)
        ->call('pilihJenis', $osn->getKey())->call('pilihCabang', $osn->branches()->where('name', 'Kimia')->value('id'))->call('daftar')
        ->assertHasNoErrors()
        ->assertNoRedirect();
});

it('lets a Livewire update through by its route name and holds a page route (FS1 fix wave)', function (): void {
    $routes = app('router')->getRoutes();
    $update = collect($routes->getRoutes())->first(fn ($route): bool => str_ends_with((string) $route->getName(), 'livewire.update'));
    expect($update)->not->toBeNull();

    $handle = function ($route, string $method) {
        $request = Request::create('/'.ltrim($route->uri(), '/'), $method);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $this->hana->user);
        $request->setLaravelSession(app('session')->driver());

        return (new EnsureTalentChosen)->handle($request, fn () => response('lewat'));
    };

    expect($handle($update, 'POST')->getContent())->toBe('lewat')
        ->and($handle($routes->getByName('siswa.beranda'), 'GET')->isRedirect(route('siswa.talenta')))->toBeTrue();
});
