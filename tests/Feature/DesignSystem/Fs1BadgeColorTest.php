<?php

declare(strict_types=1);

use App\Support\Navigation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;

afterEach(fn () => Navigation::resolveBadgesUsing(null));

function fs1CssBlock(string $selector): string
{
    $css = (string) file_get_contents(resource_path('css/app.css'));
    preg_match('/\n    '.preg_quote($selector, '/').' \{(.*?)\n    \}/s', $css, $m);

    return $m[1] ?? '';
}

it('paints the sidebar counts red with white text and leaves tab counts gray (D-136)', function (): void {
    expect(fs1CssBlock('.badge'))->toContain('background: var(--color-red);')->toContain('color: var(--color-white);')
        ->and(fs1CssBlock('.cnt'))->toContain('background: var(--color-gray-bg);');

    $html = Blade::render('<x-sth.sidebar :nav="$nav" />', [
        'nav' => Navigation::for(['name' => 'X', 'roles' => ['kesiswaan']], 'kesiswaan', ['kesiswaan.usulan' => 3], 'none'),
    ]);
    expect($html)->toContain('<span class="badge num" data-sth-badge="kesiswaan.usulan"');
});

it('paints the bell bubble red and keeps the unread row marker navy (D-136)', function (): void {
    $html = Blade::render('<x-sth.topbar :nav="$nav" title="Beranda" :notifications="3" />', [
        'nav' => Navigation::for(['name' => 'Ibu Dwi Rahayu', 'roles' => ['kesiswaan']], 'kesiswaan', [], 'none'),
    ]);
    preg_match('/<span([^>]*data-sth-badge="akun\.notifikasi"[^>]*)>/', $html, $bubble);

    expect($bubble[1] ?? '')->toContain('bg-red')->toContain('text-white')->not->toContain('bg-primary')
        ->and((string) file_get_contents(resource_path('views/livewire/akun/notifikasi.blade.php')))
        ->toContain("'bg-primary' => \$item['unread']");
});

it('keeps the live count script away from the badge colour (D-127)', function (): void {
    $script = (string) file_get_contents(resource_path('js/app.js'));
    $block = Str::betweenFirst($script, "window.addEventListener('sth-badges'", '/* ----------------'); // not between(): that cuts at the LAST marker

    expect($block)->not->toBe('')->toContain('badge.hidden = count === 0;')
        ->not->toContain('class')
        ->not->toContain('style');
});
