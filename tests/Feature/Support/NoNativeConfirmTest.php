<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File as Filesystem;

/*
 | D-128 (lead, 2026-10-10): an action that cannot be taken back, or that tells several people at once, asks in the
 | app's own dialog (<x-sth.modal>), never in the browser's confirm(). `wire:confirm` opens that native box: it is not
 | styled, it cannot show who is told or what is rejected, and it answers "no" without a word once the browser's
 | "jangan tampilkan dialog lagi" is ticked. The pattern: an ask method sets a #[Locked] property and opens the dialog,
 | the confirm method does nothing without it (Kesiswaan › Verifikasi hasil "Tolak", Talenta, Kelola lomba "Tutup").
 */

/** @return list<string> "file:line" of every use of the native confirm in a Blade view */
function d128NativeConfirms(): array
{
    $found = [];

    foreach (Filesystem::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $index => $line) {
            // A Blade comment that only talks about it is no use of it.
            $code = (string) preg_replace('/\{\{--.*?--\}\}/', '', $line);

            if (preg_match('/wire:confirm\b/', $code) === 1) {
                $found[] = str_replace('\\', '/', $file->getRelativePathname()).':'.($index + 1);
            }
        }
    }

    return $found;
}

it('uses no native browser confirm (wire:confirm) in any view: a question is an <x-sth.modal> (D-128)', function (): void {
    expect(d128NativeConfirms())->toBe([]);
});

it('reads more than a handful of views, so an empty list means something (D-128)', function (): void {
    $views = array_filter(Filesystem::allFiles(resource_path('views')), fn ($file): bool => str_ends_with($file->getFilename(), '.blade.php'));

    expect(count($views))->toBeGreaterThan(100);
});
