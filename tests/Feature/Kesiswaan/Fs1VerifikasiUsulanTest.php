<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Kesiswaan\Lomba\Index;
use App\Notifications\Competitions\CompetitionSubmitted;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;
use Tests\Feature\Siswa\Lomba\LombaFixtures as L;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();
    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
});

it('names the menu item and the tab it opens "Verifikasi usulan lomba" (D-119)', function (): void {
    $items = collect(Navigation::for(['name' => 'X', 'roles' => ['kesiswaan']], 'kesiswaan', [], 'none')['sections'])
        ->flatMap(fn (array $s) => $s['items'])->keyBy('key');

    expect($items['usulan']['label'])->toBe('Verifikasi usulan lomba')
        ->and($items['usulan']['badgeKey'])->toBe('kesiswaan.usulan');

    Livewire::actingAs($this->kesiswaan)->test(Index::class)
        ->assertSee('Verifikasi usulan lomba')
        ->assertSeeInOrder(['Verifikasi usulan lomba', 'menunggu diperiksa'])
        ->assertDontSee('Antrean verifikasi');
});

it('names the Beranda tile and card "Verifikasi usulan lomba"', function (): void {
    $this->actingAs($this->kesiswaan)->get(route('kesiswaan.beranda'))->assertOk()
        ->assertSeeInOrder(['Verifikasi usulan lomba', 'menunggu diperiksa'])
        ->assertDontSee('Upload lomba');
});

it('sends the verifier mail and the bell entry as "Verifikasi usulan lomba" (BUG-LOMBA-08)', function (): void {
    $competition = L::pendingCompetition(L::user(L::student()), ['name' => 'OSN Kota 2026']);
    $notice = new CompetitionSubmitted($competition, 'Nadia Putri Anindya');
    $mail = $notice->toMail($this->kesiswaan);

    expect($mail->subject)->toBe('Verifikasi usulan lomba: OSN Kota 2026')
        ->and($mail->actionText)->toBe('Verifikasi usulan lomba')
        ->and($notice->toArray($this->kesiswaan)['title'])->toBe('Verifikasi usulan lomba: OSN Kota 2026');
});

it('lets a long menu name wrap instead of cutting it', function (): void {
    $html = Blade::render('<x-sth.sidebar :nav="$nav" />', [
        'nav' => Navigation::for(['name' => 'X', 'roles' => ['kesiswaan']], 'kesiswaan', ['kesiswaan.usulan' => 12], 'none'),
    ]);
    $css = (string) file_get_contents(resource_path('css/app.css'));
    preg_match('/\n    \.nav \{(.*?)\n    \}/s', $css, $nav);

    expect($html)->toContain('<span class="min-w-0 flex-1 leading-snug">Verifikasi usulan lomba</span>')
        ->not->toContain('flex-1 truncate')
        ->and($nav[1] ?? '')->toContain('min-height: 44px')
        ->not->toMatch('/(?<![\w-])height:\s*44px/');
});
