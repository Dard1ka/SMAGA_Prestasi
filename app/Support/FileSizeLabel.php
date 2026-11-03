<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A file size as the screens print it, with the decimal comma (CLAUDE.md §3.9): "1,6 MB" from 1 MB up, else whole
 * kilobytes ("350 KB", "1.023 KB", at least "1 KB"); a size that rounds to 1.024 KB is "1,0 MB". The one rule behind every screen's
 * size (r3, 2026-10-31: Humas › Arsip foto printed a 1,6 MB photo as "2 MB"); LombaPresenter::fileSize() and the
 * Kesiswaan screens' fileSize() hand over to it.
 */
final class FileSizeLabel
{
    private const KB = 1024;

    private const MB = 1024 * 1024;

    public static function format(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $kilobytes = max(1, (int) round($bytes / self::KB));

        if ($bytes >= self::MB || $kilobytes >= self::KB) {
            return number_format(max($bytes, self::MB) / self::MB, 1, ',', '.').' MB';
        }

        // The thousands dot of LombaPresenter::fileSize() before r3 ("1.023 KB"), as every count on the screens.
        return number_format($kilobytes, 0, ',', '.').' KB';
    }
}
