<?php

declare(strict_types=1);

namespace App\Domain\Files;

use DateTimeImmutable;
use DateTimeInterface;
use finfo;
use InvalidArgumentException;
use Symfony\Component\Uid\Ulid;

/**
 * Upload allowlist (ARCHITECTURE §4 files, SECURITY §3d): extension AND sniffed MIME must both be
 * allowed; the stored extension comes from the sniffed MIME, never from the user's file name.
 * Used by StoreUpload (wave 2) and FormRequests (`extensions:` + `mimetypes:` + `max:` in KB).
 */
final class UploadRules
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const DEFAULT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public const MIME_BY_EXTENSION = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public const MSG_UNREADABLE = 'File tidak bisa dibaca.';

    public const MSG_EMPTY = 'File kosong.';

    public const MSG_CONTENT = 'Isi file tidak sesuai jenis yang diizinkan.';

    public const MSG_BAD_PDF = 'File PDF tidak valid.';

    public const MAX_ORIGINAL_NAME = 150;

    private const EXTENSION_BY_MIME = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** @var list<string> */
    private readonly array $extensions;

    /** @param list<string> $extensions subset of DEFAULT_EXTENSIONS */
    public function __construct(
        private readonly int $maxBytes = self::MAX_BYTES,
        array $extensions = self::DEFAULT_EXTENSIONS,
    ) {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('Batas ukuran file harus lebih dari 0.');
        }

        $normalized = array_values(array_unique(array_map(strtolower(...), $extensions)));

        if ($normalized === [] || array_diff($normalized, self::DEFAULT_EXTENSIONS) !== []) {
            throw new InvalidArgumentException('Jenis file harus dari: pdf, jpg, jpeg, png, webp.');
        }

        $this->extensions = $normalized;
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return $this->extensions;
    }

    /** @return list<string> */
    public function mimeTypes(): array
    {
        return array_values(array_unique(array_map(
            fn (string $ext): string => self::MIME_BY_EXTENSION[$ext],
            $this->extensions,
        )));
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /** For Laravel's `max:` rule on files (kilobytes). */
    public function maxKilobytes(): int
    {
        return intdiv($this->maxBytes, 1024);
    }

    public function isAllowedExtension(string $extension): bool
    {
        return in_array(strtolower(ltrim($extension, '.')), $this->extensions, true);
    }

    public function isAllowedMime(string $mime): bool
    {
        return in_array(strtolower($mime), $this->mimeTypes(), true);
    }

    /** MIME type from the file content (libmagic), or null when unreadable. */
    public function sniffMime(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($mime) && $mime !== '' ? strtolower($mime) : null;
    }

    /** Canonical storage extension for an allowed MIME (image/jpeg → jpg). */
    public function extensionForMime(string $mime): ?string
    {
        return self::EXTENSION_BY_MIME[strtolower($mime)] ?? null;
    }

    /**
     * Check a file on disk against the rules; empty list = accepted.
     *
     * @return list<string>
     */
    public function validate(string $path, string $originalName): array
    {
        $size = is_file($path) && is_readable($path) ? filesize($path) : false;

        if ($size === false) {
            return [self::MSG_UNREADABLE];
        }

        $errors = [];

        if (! $this->isAllowedExtension(pathinfo($originalName, PATHINFO_EXTENSION))) {
            $errors[] = $this->typeMessage();
        }

        if ($size === 0) {
            return [...$errors, self::MSG_EMPTY];
        }

        if ($size > $this->maxBytes) {
            $errors[] = $this->sizeMessage();
        }

        $mime = $this->sniffMime($path);

        if ($mime === null || ! $this->isAllowedMime($mime)) {
            $errors[] = self::MSG_CONTENT;
        } elseif ($mime === 'application/pdf' && ! $this->hasPdfMagic($path)) {
            $errors[] = self::MSG_BAD_PDF;
        }

        return $errors;
    }

    /** SECURITY §3d: a PDF must start with "%PDF-". */
    public function hasPdfMagic(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = fread($handle, 5);
        fclose($handle);

        return $head === '%PDF-';
    }

    /**
     * Random private path "{directory}/{Y}/{m}/{ulid}.{ext}" — no user input in the path.
     */
    public function storagePath(string $mime, ?DateTimeInterface $now = null, string $directory = 'lampiran'): string
    {
        $extension = $this->extensionForMime($mime);

        if ($extension === null || ! $this->isAllowedMime($mime)) {
            throw new InvalidArgumentException(self::MSG_CONTENT);
        }

        if (preg_match('~^[a-z0-9_-]+(/[a-z0-9_-]+)*$~', $directory) !== 1) {
            throw new InvalidArgumentException('Nama folder penyimpanan tidak valid.');
        }

        $now ??= new DateTimeImmutable;

        return sprintf('%s/%s/%s.%s', $directory, $now->format('Y/m'), strtolower((string) new Ulid), $extension);
    }

    /** Display-only original name for the DB: no paths/control chars, ≤ 150 characters. */
    public function sanitizeOriginalName(string $name): string
    {
        $name = mb_scrub($name, 'UTF-8');
        $name = basename(str_replace('\\', '/', $name));
        $name = trim(preg_replace('/[\p{C}]+/u', '', $name) ?? '');

        if ($name === '' || $name === '.' || $name === '..') {
            return 'file';
        }

        return mb_substr($name, 0, self::MAX_ORIGINAL_NAME);
    }

    /**
     * The display name stored for an accepted file: sanitized, ending in the extension of its content (QA8, Q1-20). The
     * content decides the type, so a PNG saved as "brosur.pdf" is stored as "brosur.png" instead of being downloaded as a
     * PDF nobody can open. A name that already fits stays as typed (".jpeg", ".JPG").
     */
    public function nameForContent(string $originalName, string $mime): string
    {
        $name = $this->sanitizeOriginalName($originalName);
        $actual = $this->extensionForMime($mime);
        $extension = pathinfo($name, PATHINFO_EXTENSION);

        if ($actual === null || (self::MIME_BY_EXTENSION[strtolower($extension)] ?? null) === strtolower($mime)) {
            return $name;
        }

        $base = $extension === '' ? $name : mb_substr($name, 0, -mb_strlen($extension) - 1);

        return mb_substr($base, 0, self::MAX_ORIGINAL_NAME - strlen($actual) - 1).'.'.$actual;
    }

    private function typeMessage(): string
    {
        $labels = array_map(strtoupper(...), array_values(array_diff(
            $this->extensions,
            in_array('jpg', $this->extensions, true) ? ['jpeg'] : [],
        )));
        $last = array_pop($labels);
        $list = match (count($labels)) {
            0 => $last,
            1 => $labels[0].' atau '.$last,
            default => implode(', ', $labels).', atau '.$last,
        };

        return "Jenis file tidak diizinkan. Pakai {$list}.";
    }

    private function sizeMessage(): string
    {
        $mb = $this->maxBytes / (1024 * 1024);
        $label = floor($mb) === $mb ? (string) (int) $mb : str_replace('.', ',', (string) round($mb, 1));

        return "Ukuran file maksimal {$label} MB.";
    }
}
