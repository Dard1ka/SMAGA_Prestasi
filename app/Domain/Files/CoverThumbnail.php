<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Enums\FilePurpose;
use App\Models\File;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * The small picture of a lomba cover that cards and lists show (FileController::cover, route `files.cover`).
 *
 * StoreUpload keeps a cover at up to 2048 px, far more than a card needs, and one catalog page shows a dozen of
 * them. So every cover gets one progressive JPEG of at most MAX_SIDE px at quality QUALITY (the drawn posters of
 * the demo data come to 50 to 75 kB), made with GD the way StoreUpload re-encodes an upload:
 * - made on the first request and then kept on the same private `local` disk as "sampul/<file id>.jpg" (never the
 *   `public` disk); it is only ever sent by the policy-checked FileController;
 * - only for a File with purpose `cover` whose stored bytes are a JPEG, PNG or WEBP of at most
 *   StoreUpload::MAX_IMAGE_SIDE px, which is all StoreUpload keeps. Anything else is not decoded: bytes() gives
 *   null and the route answers 404;
 * - written under a temporary name and renamed into place, so two requests that make the same picture at once
 *   never leave or read half a file (both write the same bytes, the later rename simply wins);
 * - derived data: deleting "sampul/" or a file in it is always safe, the next request makes the picture again
 *   (do that after changing MAX_SIDE or QUALITY). forget() removes it with its original (StoreUpload::discard()).
 *
 * The app never rewrites the bytes of a File (a new cover is a new File with a new id), so one picture per id is
 * enough. Whoever does rewrite them in place (the local demo data: DemoFileRefresher) calls forget() afterwards.
 */
final class CoverThumbnail
{
    public const DISK = StoreUpload::DISK;

    public const DIRECTORY = 'sampul';

    /** Long side in px. A cover is at most 160 CSS px high on screen: 640 serves a 3× phone and halves the weight of 960. */
    public const MAX_SIDE = 640;

    public const QUALITY = 80;

    public const MIME = 'image/jpeg';

    /** What StoreUpload keeps for a cover (getimagesize() types). Nothing else is ever decoded here. */
    private const SOURCE_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /** Where the picture of this cover is kept on the private disk. */
    public static function path(File $file): string
    {
        return self::DIRECTORY.'/'.$file->getKey().'.jpg';
    }

    /**
     * Validator for the browser cache: changes when the original's bytes change (its stored sha256) or when the
     * size or quality here change. Needs no disk access, so a 304 costs nothing.
     */
    public static function etag(File $file): string
    {
        return substr(hash('sha256', implode('|', ['sampul', (string) $file->sha256, self::MAX_SIDE, self::QUALITY, 'progressive'])), 0, 32);
    }

    /**
     * The JPEG bytes of the small picture: the kept one, or a new one that is kept for the next request.
     * null = no picture can be made (not a cover, original gone, not an image StoreUpload would have stored).
     */
    public static function bytes(File $file): ?string
    {
        if ($file->purpose !== FilePurpose::Cover) {
            return null;
        }

        $path = self::path($file);
        $kept = self::read(self::DISK, $path);

        if ($kept !== null && str_starts_with($kept, "\xFF\xD8")) {
            return $kept;
        }

        $bytes = self::render($file);

        if ($bytes !== null) {
            self::keep($path, $bytes);
        }

        return $bytes;
    }

    /** Remove the kept picture of a cover (its original is being deleted or its bytes were replaced). */
    public static function forget(File $file): void
    {
        if ($file->purpose === FilePurpose::Cover) {
            Storage::disk(self::DISK)->delete(self::path($file));
        }
    }

    private static function render(File $file): ?string
    {
        $original = self::read((string) $file->disk, (string) $file->path);

        if ($original === null) {
            return null;
        }

        // Type and dimensions are read from the header before anything is decoded (as in StoreUpload).
        $size = @getimagesizefromstring($original);

        if ($size === false
            || ! in_array($size[2], self::SOURCE_TYPES, true)
            || min($size[0], $size[1]) < 1
            || max($size[0], $size[1]) > StoreUpload::MAX_IMAGE_SIDE) {
            return null;
        }

        try {
            $image = ImageManager::usingDriver(GdDriver::class)->decodeBinary($original);
            // Never enlarges; transparent areas of a PNG or WEBP become white in the JPEG.
            $image->scaleDown(self::MAX_SIDE, self::MAX_SIDE);

            // Progressive: about a tenth smaller at this size, and it appears at once on a slow connection.
            $bytes = $image->encodeUsingFormat(Format::JPEG, quality: self::QUALITY, progressive: true)->toString();
        } catch (Throwable) {
            return null;
        }

        return str_starts_with($bytes, "\xFF\xD8") ? $bytes : null;
    }

    /**
     * Temporary name first, then a rename: a reader sees the whole picture or none. When it cannot be kept (disk
     * full, folder not writable) the picture is still sent and simply made again on the next request.
     */
    private static function keep(string $path, string $bytes): void
    {
        $disk = Storage::disk(self::DISK);
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        try {
            $kept = $disk->put($temporary, $bytes) !== false && $disk->move($temporary, $path) !== false;
        } catch (Throwable) {
            $kept = false;
        }

        if (! $kept) {
            try {
                $disk->delete($temporary);
            } catch (Throwable) {
                // Nothing to clean up.
            }
        }
    }

    /** The bytes at a path, or null when there is no such file (or it is empty or unreadable). */
    private static function read(string $disk, string $path): ?string
    {
        try {
            $bytes = Storage::disk($disk)->get($path);
        } catch (Throwable) {
            return null;
        }

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }
}
