<?php

declare(strict_types=1);

namespace Database\Seeders\Support\Demo;

use App\Models\Letter;
use Carbon\CarbonImmutable;

/**
 * The invitation a demo surat tugas answers ("Dasar: … Nomor … Tanggal …"), for the demo data (LOCAL ONLY, QA5).
 * Fictional, and always the same for one demo lomba and letter date, so DemoSeeder and `sth:demo:refresh-files
 * --lengkapi` write the same values: a number like "047/PAN-KRENOVA/IX/2026" and a date 5 to 14 days before the
 * letter. Both letters of one registration answer the same invitation.
 */
final class DemoLetterBasis
{
    /** File name prefixes DemoSeeder gives the two demo letters; the rest of the name is the lomba's demo key. */
    public const PREFIXES = ['surat-tugas-siswa-', 'surat-perintah-tugas-guru-'];

    /** Invitations written into the demo set by hand: demo key => [number, Y-m-d]. */
    private const KNOWN = [
        'wice26' => ['IYSA/WICE/INV/118/VIII/2026', '2026-08-28'],
    ];

    private const ROMAN = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    /** @return array{number: string, date: string} */
    public static function for(string $key, CarbonImmutable $issuedOn): array
    {
        if (isset(self::KNOWN[$key])) {
            return ['number' => self::KNOWN[$key][0], 'date' => self::KNOWN[$key][1]];
        }

        $hash = crc32($key);
        $date = $issuedOn->startOfDay()->subDays(5 + $hash % 10);
        $code = strtoupper((string) preg_replace('/[^a-z]+/', '', (string) preg_replace('/\d+$/', '', $key)));

        return [
            'number' => sprintf('%03d/PAN-%s/%s/%d', 10 + $hash % 890, $code !== '' ? $code : 'LOMBA', self::ROMAN[$date->month - 1], $date->year),
            'date' => $date->toDateString(),
        ];
    }

    /** The demo key of a demo letter ("surat-tugas-siswa-krenova26.pdf" → "krenova26"), or null for another file. */
    public static function keyOf(Letter $letter): ?string
    {
        $name = (string) $letter->file?->original_name;

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix) && str_ends_with($name, '.pdf')) {
                $key = substr($name, strlen($prefix), -4);

                return $key !== '' ? $key : null;
            }
        }

        return null;
    }
}
