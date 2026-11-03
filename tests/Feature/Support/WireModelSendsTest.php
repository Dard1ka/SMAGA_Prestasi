<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File as Filesystem;

/*
 | SV1, third browser check (2026-11-01): the riset title of Laporan hasil had `wire:model.blur="judul"`. In Livewire 4
 | (js/directives/wire-model.js) a model directive sends a request only with `.live` or `.lazy`; the modifiers before
 | `.live` (or without it) only decide when the value changes in the browser. So `.blur` / `.change` alone never reached
 | the server: LaporHasil::updated() never ran and "Isi judul penelitian." stayed under a filled title. A Livewire::test()
 | `set()` bypasses the browser and could not see it. Write `wire:model.live.blur` (or `.lazy` for "on change"). Comments
 | are read too: the doc comment of <x-sth.input> showed the client-only form as the example to copy.
 */

/** @return list<string> "file:line wire:model…" of every model with such a trigger and no `.live` / `.lazy` */
function wmsClientOnlyModels(): array
{
    // Modifiers that only say WHEN a model is sent: without `.live` / `.lazy` they decide nothing the server sees.
    $triggers = ['blur', 'change', 'enter'];
    $found = [];

    foreach (Filesystem::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $index => $line) {
            preg_match_all('/wire:model((?:\.[A-Za-z0-9_-]+)+)/', $line, $matches);

            foreach ($matches[1] as $i => $tail) {
                $modifiers = explode('.', ltrim($tail, '.'));
                $triggered = array_intersect($modifiers, $triggers) !== [];
                $sends = in_array('live', $modifiers, true) || in_array('lazy', $modifiers, true);

                if ($triggered && ! $sends) {
                    $found[] = str_replace('\\', '/', $file->getRelativePathname()).':'.($index + 1).' '.$matches[0][$i];
                }
            }
        }
    }

    return $found;
}

it('sends every wire:model that waits for blur, change or enter (.live or .lazy), also in comments (SV1)', function (): void {
    expect(wmsClientOnlyModels())->toBe([]);
});

it('reads the views, so an empty list means something', function (): void {
    $live = 0;

    foreach (Filesystem::allFiles(resource_path('views')) as $file) {
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            $live += substr_count((string) file_get_contents($file->getPathname()), 'wire:model.live');
        }
    }

    expect($live)->toBeGreaterThan(50);
});

it('keeps the riset title of Laporan hasil a model that is sent on blur', function (): void {
    expect((string) file_get_contents(resource_path('views/livewire/siswa/lomba-saya/lapor-hasil.blade.php')))
        ->toContain('wire:model.live.blur="judul"');
});
