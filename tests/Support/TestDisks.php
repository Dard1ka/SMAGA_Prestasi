<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Per-test storage disks (W3-A). Tests\TestCase::setUp() gives every Feature test its own empty `local` and
 * `public` disk under storage/framework/testing/disks/iso-<disk>-<pid>-<random>, and tearDown() deletes them.
 *
 * Do NOT use Storage::fake(): it always uses one fixed folder and wipes it on start, so two test runs at
 * the same time (several containers on the same bind mount) delete each other's files, and files written
 * by one run show up in the other's counts. Without any fake, tests would write into the real dev
 * storage/app/private.
 *
 * Need a second fresh disk inside one test: `TestDisks::isolate('local')` (returns the new root).
 */
final class TestDisks
{
    /** @var list<string> roots created since the last cleanup() */
    private static array $roots = [];

    public static function isolate(string $disk = 'local'): string
    {
        $root = self::directory($disk);
        Storage::set($disk, Storage::createLocalDriver(['root' => $root, 'throw' => false]));

        return $root;
    }

    /** An empty scratch folder for this test only (deleted in cleanup()), e.g. the PdfRenderer sandbox root. */
    public static function directory(string $name): string
    {
        $root = storage_path(sprintf('framework/testing/disks/iso-%s-%d-%s', $name, getmypid(), bin2hex(random_bytes(6))));
        (new Filesystem)->ensureDirectoryExists($root);
        self::$roots[] = $root;

        return $root;
    }

    /** Root folder of the current (isolated) disk. */
    public static function root(string $disk = 'local'): string
    {
        return Storage::disk($disk)->path('');
    }

    public static function cleanup(): void
    {
        $files = new Filesystem;

        foreach (self::$roots as $root) {
            $files->deleteDirectory($root);
        }

        self::$roots = [];
    }
}
