<?php

declare(strict_types=1);

namespace App\Support\Dev;

use Composer\Autoload\ClassLoader;
use Throwable;

/**
 * Local development only (routes/dev.php, docker/php/dev-cache.sh). On the Windows bind mount php-fpm keeps
 * compiled PHP in opcache and never re-checks the files, so every `reload.bat` has to empty opcache — and the
 * next request then reads the whole framework from the Windows drive again (measured: 7 s for the login page,
 * 5.7 s for the first page after logging in). save() writes down which project files were compiled just before
 * the reload; warm() compiles the same files again right after it, in one background request, so the
 * developer's first click is fast. Nothing here runs in production: the routes do not exist there.
 */
final class OpcacheWarmup
{
    /** One absolute path per line. Kept on the bind mount so it survives a container that is created again. */
    public static function listPath(): string
    {
        return storage_path('framework/cache/sth-opcache-scripts.txt');
    }

    /** Remember the project files php-fpm has compiled. Returns how many; 0 leaves the previous list alone. */
    public static function save(): int
    {
        $status = function_exists('opcache_get_status') ? @opcache_get_status(true) : false;
        $scripts = is_array($status) && is_array($status['scripts'] ?? null) ? array_keys($status['scripts']) : [];
        $base = base_path().'/';

        $scripts = array_values(array_filter(
            $scripts,
            static fn (mixed $path): bool => is_string($path)
                && str_starts_with($path, $base)
                && ! str_contains($path, '/storage/framework/'),
        ));

        if ($scripts === []) {
            return 0;
        }

        @file_put_contents(self::listPath(), implode("\n", $scripts)."\n", LOCK_EX);

        return count($scripts);
    }

    /**
     * Compile the remembered files that opcache does not hold yet. Reading a file from the Windows drive is the
     * slow part (~30 ms each), so the caller splits the list over several requests that run side by side:
     * request `$part` of `$of` takes every `$of`-th line.
     *
     * @return array{compiled: int, skipped: int}
     */
    public static function warm(int $part = 0, int $of = 1): array
    {
        $result = ['compiled' => 0, 'skipped' => 0];

        if (! function_exists('opcache_compile_file') || ! function_exists('opcache_is_script_cached') || ! is_file(self::listPath())) {
            return $result;
        }

        $of = max(1, min(8, $of));
        $part = max(0, min($of - 1, $part));
        $base = base_path().'/';
        $lines = @file(self::listPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        self::resolveAutoloadPaths($lines);

        foreach ($lines as $index => $path) {
            if (! str_starts_with($path, $base)) {
                $result['skipped']++;

                continue;
            }

            // Every request resolves every path: the realpath cache belongs to the worker that answers, and
            // each worker has to know the files (docker/php/fpm.dev.conf, "three fixed workers").
            @realpath($path);

            // Compiling is shared work (opcache is one block of memory for all workers), so it is split.
            if ($index % $of !== $part) {
                continue;
            }

            // Never one this request has already loaded (it is cached by now, and compiling it a second time
            // would declare its class twice).
            if (@opcache_is_script_cached($path)) {
                $result['skipped']++;

                continue;
            }

            try {
                @opcache_compile_file($path) ? $result['compiled']++ : $result['skipped']++;
            } catch (Throwable) {
                $result['skipped']++; // deleted or broken file: the real request will report it
            }
        }

        return $result;
    }

    /**
     * Composer includes a class file under the exact string of its class map, e.g.
     * `/var/www/html/vendor/composer/../laravel/framework/src/…`, and the realpath cache of a worker is filled
     * per string. Resolve those strings for the remembered files, so the worker's first real request does not
     * do one lookup per class on the Windows drive.
     *
     * @param  list<string>  $paths  normalised paths as opcache reports them
     */
    private static function resolveAutoloadPaths(array $paths): void
    {
        $wanted = array_flip($paths);

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach ($loader->getClassMap() as $file) {
                if (isset($wanted[self::normalise($file)])) {
                    @realpath($file);
                }
            }
        }
    }

    /** `/a/b/../c` → `/a/c`, without touching the filesystem. */
    private static function normalise(string $path): string
    {
        if (! str_contains($path, '/..')) {
            return $path;
        }

        $parts = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($parts);
            } elseif ($segment !== '.') {
                $parts[] = $segment;
            }
        }

        return implode('/', $parts);
    }
}
