<?php

declare(strict_types=1);

use App\Actions\Publications\AppreciationList;
use App\Actions\Publications\EventWhen;
use App\Actions\Publications\PrintAppreciationList;
use App\Actions\Publications\RecordAppreciations;
use App\Domain\Letters\PdfRenderer;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\View;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures as H;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;

/*
| D-141: "Cetak daftar" per appreciation event, an inline A4 PDF of the students who may be named
| (NamedStudent), no NIS, NISN or points. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    config(['fortify.sth.require_staff_2fa' => false]);
    Notification::fake();
    $this->withoutVite();
    $this->humas = H::humas();
});

it('prints only the students who may be named, sorted by class then name, without NIS, NISN or points (D-141)', function (): void {
    $nadia = H::achievement(['nis' => '225226201', 'nisn' => '0098765432']); // XI-4, OPSI 2026, Juara II, Nasional
    $fajar = H::teammate($nadia, 'Fajar Ramadhan', 'XI-4');
    $raka = H::teammate($nadia, 'Raka Pradipta', 'X-2');
    $yoga = H::teammate($nadia, 'Yoga Pratama', 'XII-2');
    $dimas = H::teammate($nadia, 'Dimas Arya Nugroho', 'XII-2');
    $keisha = H::teammate($nadia, 'Keisha Larasati', 'XI-6');
    $event = H::event(heldTime: '07:00');
    app(RecordAppreciations::class)->handle($this->humas, $event, [$nadia, $fajar, $raka, $yoga, $dimas, $keisha]);

    // After scheduling: Yoga became an alumnus, Dimas withdrew consent, Keisha's account was disabled.
    $yoga->student->user->forceFill(['status' => UserStatus::Archived])->save();
    $dimas->student->forceFill(['publish_consent' => false])->save();
    $keisha->student->user->forceFill(['status' => UserStatus::Disabled])->save();

    $list = AppreciationList::for($event->fresh());

    expect(array_column($list['rows'], 'name'))->toBe(['Raka Pradipta', 'Fajar Ramadhan', 'Nadia Putri Anindya', 'Yoga Pratama'])
        ->and(array_column($list['rows'], 'class'))->toBe(['X-2', 'XI-4', 'XI-4', 'Alumni'])
        ->and(array_column($list['rows'], 'no'))->toBe([1, 2, 3, 4])
        ->and($list['rows'][0])->toBe(['no' => 1, 'name' => 'Raka Pradipta', 'class' => 'X-2', 'result' => ResultType::Juara2->label(), 'competition' => 'OPSI 2026', 'level' => Level::Nasional->label()])
        ->and($list['omitted'])->toBe(2);

    View::addNamespace(PdfRenderer::VIEW_NAMESPACE, resource_path('pdf'));
    $html = view('pdf::daftar-apresiasi', PrintAppreciationList::viewData($event->fresh()))->render();

    expect($html)->toContain('Daftar siswa apresiasi')->toContain('Upacara Senin, 5 Oktober 2026')->toContain(EventWhen::long($event))
        ->toContain('Raka Pradipta')->toContain('Yoga Pratama')
        ->toContain('2 prestasi tidak dicantumkan karena siswanya belum memberi izin publikasi atau akunnya nonaktif.')
        ->not->toContain('Dimas Arya Nugroho')->not->toContain('Keisha Larasati')
        ->not->toContain('225226201')->not->toContain('0098765432')
        ->not->toContain('poin')->not->toContain('style="');
});

it('opens the list as an inline PDF for Humas, Kesiswaan and Admin and audits it without names (D-141)', function (): void {
    $event = H::event(heldTime: '07:00');
    app(RecordAppreciations::class)->handle($this->humas, $event, [H::achievement()]);

    foreach ([$this->humas, K::staff(RoleName::Kesiswaan), K::staff(RoleName::Admin, 'Operator STH')] as $user) {
        $response = $this->actingAs($user)->get(route('apresiasi.cetak', $event))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect($response->headers->get('Content-Disposition'))->toStartWith('inline;')->toContain('daftar-apresiasi-upacara-senin-5-oktober-2026.pdf')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
            ->and($response->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'none'")
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->getContent())->toStartWith('%PDF-');
    }

    $audits = Activity::query()->where('event', 'appreciation.list_printed')->get();
    expect($audits)->toHaveCount(3)
        ->and($audits->first()->subject_id)->toBe($event->getKey())
        ->and($audits->first()->properties->all())->toBe(['event_id' => $event->getKey(), 'rows' => 1, 'omitted' => 0])
        ->and(json_encode($audits->first()->properties))->not->toContain('Nadia')
        ->and(AuditLog::EVENT_LABELS['appreciation.list_printed'])->toBe('Daftar apresiasi dicetak');
});

it('refuses the list to every other role, guests and unknown events (D-141)', function (): void {
    $event = H::event();

    foreach ([RoleName::Guru, RoleName::KepalaSekolah, RoleName::TataUsaha] as $role) {
        $this->actingAs(K::staff($role, 'Staf '.$role->value))->get(route('apresiasi.cetak', $event))->assertForbidden();
    }
    $this->actingAs(K::student('Raka Pradipta', 'XII-2')->user)->get(route('apresiasi.cetak', $event))->assertForbidden();
    $this->actingAs($this->humas)->get('/apresiasi/01ARZ3NDEKTSV4RRFFQ69G5FAV/cetak')->assertNotFound();
    auth()->logout();
    $this->get(route('apresiasi.cetak', $event))->assertRedirect(route('login'));

    expect(Activity::query()->where('event', 'appreciation.list_printed')->exists())->toBeFalse();
});

it('shows "Cetak daftar" on every event card with prestasi, on both Apresiasi routes (D-141)', function (): void {
    $event = H::event(heldTime: '07:00');
    $empty = H::event('Upacara Senin, 12 Oktober 2026', now()->addDays(11)->toDateString(), '07:00');
    app(RecordAppreciations::class)->handle($this->humas, $event, [H::achievement()]);

    foreach ([[$this->humas, 'humas.apresiasi.index'], [K::staff(RoleName::Kesiswaan), 'kesiswaan.apresiasi.index']] as [$user, $route]) {
        $html = $this->actingAs($user)->get(route($route))->assertOk()->assertSee('Cetak daftar')->getContent();
        expect($html)->toContain('href="'.route('apresiasi.cetak', $event).'"')->toContain('target="_blank"')
            ->not->toContain(route('apresiasi.cetak', $empty));
    }
});
