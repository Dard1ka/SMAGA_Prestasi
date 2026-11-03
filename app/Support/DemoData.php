<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\File;

/**
 * Does this database hold the local demo data set (DemoSeeder)? APP_ENV=local alone does not say it: the lead's server
 * instances run APP_ENV=local with real data (D-168), and the dashboard showed "Data contoh" there (SV1).
 *
 * The demo seed stores its sample files on the private `local` disk under DIRECTORY
 * (Database\Seeders\Support\DemoFiles::DIRECTORY, kept equal by a test); nothing else writes there: uploads go to
 * lampiran/…, the letters and the signature image to folders of their own.
 */
final class DemoData
{
    /** Folder of the demo files on the `local` disk; the same value as Database\Seeders\Support\DemoFiles::DIRECTORY. */
    public const DIRECTORY = 'demo';

    /** Never outside APP_ENV=local; there one query on the unique index of files.path, stopping at the first row. */
    public static function present(): bool
    {
        return app()->environment('local')
            && File::query()->where('disk', 'local')->where('path', 'like', self::DIRECTORY.'/%')->exists();
    }
}
