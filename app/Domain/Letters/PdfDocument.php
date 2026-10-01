<?php

declare(strict_types=1);

namespace App\Domain\Letters;

/** A rendered PDF: raw bytes + page count + SHA-256 (stored per letter, SECURITY §3g). */
final readonly class PdfDocument
{
    public function __construct(
        public string $bytes,
        public int $pageCount,
    ) {}

    public function sha256(): string
    {
        return hash('sha256', $this->bytes);
    }

    public function size(): int
    {
        return strlen($this->bytes);
    }
}
